<?php

namespace Tests\Feature\Trust\Deposits;

use App\Enums\FirmUserRole;
use App\Enums\TrustLedgerEntryType;
use App\Enums\TrustLedgerStatus;
use App\Exceptions\TrustLedgerNotActiveException;
use App\Models\Client;
use App\Models\FirmUser;
use App\Services\TrustAccountService;
use App\Services\TrustDepositService;
use App\Services\TrustLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Trust\Concerns\SetsUpTrustEligibleFirm;
use Tests\TestCase;

class TrustDepositServiceTest extends TestCase
{
    use RefreshDatabase, SetsUpTrustEligibleFirm;

    private TrustDepositService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(TrustDepositService::class);
    }

    private function makeLedger()
    {
        $firm = $this->makeTrustEligibleFirm();
        $account = app(TrustAccountService::class)->open($firm, 'Firm IOLTA Trust Account');
        $client = Client::factory()->forFirm($firm)->create();
        $ledger = app(TrustLedgerService::class)->open($firm, $account, $client);

        return [$firm, $ledger];
    }

    public function test_full_deposit_lifecycle_posts_a_deposit_entry_and_updates_the_balance(): void
    {
        [$firm, $ledger] = $this->makeLedger();
        $requester = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::BillingStaff]);
        $approver = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::FirmOwner]);

        $requested = $this->service->requestDeposit($firm, $ledger, $requester, 25000);
        $approved = $this->service->approveDeposit($firm, $requested, $approver);
        $entry = $this->service->post($firm, $ledger, $approved);

        $this->assertSame(TrustLedgerEntryType::Deposit, $entry->entry_type);
        $this->assertSame(25000, $entry->amount_cents);
        $this->assertSame(25000, $ledger->balance->fresh()->balance_cents);
    }

    public function test_billing_staff_cannot_approve_their_own_deposit_request(): void
    {
        [$firm, $ledger] = $this->makeLedger();
        $requester = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::BillingStaff]);

        $requested = $this->service->requestDeposit($firm, $ledger, $requester, 25000);

        $this->expectException(\RuntimeException::class);
        $this->service->approveDeposit($firm, $requested, $requester);
    }

    public function test_the_same_approval_cannot_be_posted_twice(): void
    {
        [$firm, $ledger] = $this->makeLedger();
        $requester = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::FirmOwner]);
        $approver = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::FirmOwner]);

        $requested = $this->service->requestDeposit($firm, $ledger, $requester, 25000);
        $approved = $this->service->approveDeposit($firm, $requested, $approver);
        $this->service->post($firm, $ledger, $approved);

        $this->expectException(\RuntimeException::class);
        $this->service->post($firm, $ledger, $approved->fresh());
    }

    public function test_posting_requires_a_deposit_approved_event_not_a_requested_one(): void
    {
        [$firm, $ledger] = $this->makeLedger();
        $requester = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::FirmOwner]);

        $requested = $this->service->requestDeposit($firm, $ledger, $requester, 25000);

        $this->expectException(\RuntimeException::class);
        $this->service->post($firm, $ledger, $requested);
    }

    public function test_deposit_is_blocked_for_an_ineligible_firm(): void
    {
        $firm = \App\Models\Firm::factory()->create();
        $requester = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::FirmOwner]);

        $this->expectException(\RuntimeException::class);
        $this->service->requestDeposit($firm, \App\Models\TrustLedger::factory()->create(['firm_id' => $firm->id]), $requester, 1000);
    }

    /**
     * Trust & Accounting Integrity Hardening, Mission 1.1: none of the
     * money-moving trust services previously checked TrustLedger.status
     * at all — a Frozen or Closed ledger could still receive a posted
     * deposit. post() must now refuse.
     */
    public function test_a_deposit_cannot_be_posted_to_a_frozen_ledger(): void
    {
        [$firm, $ledger] = $this->makeLedger();
        $requester = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::FirmOwner]);
        $approver = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::FirmOwner]);
        $approved = $this->service->approveDeposit($firm, $this->service->requestDeposit($firm, $ledger, $requester, 5000), $approver);

        app(TrustLedgerService::class)->freeze($firm, $ledger);

        try {
            $this->service->post($firm, $ledger->fresh(), $approved);
            $this->fail('Expected a TrustLedgerNotActiveException.');
        } catch (TrustLedgerNotActiveException $e) {
            $this->assertSame(TrustLedgerStatus::Frozen, $e->status);
        }

        $this->assertSame(0, $ledger->balance->fresh()->balance_cents);
    }

    public function test_a_deposit_cannot_be_posted_to_a_closed_ledger(): void
    {
        [$firm, $ledger] = $this->makeLedger();
        $requester = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::FirmOwner]);
        $approver = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::FirmOwner]);
        $approved = $this->service->approveDeposit($firm, $this->service->requestDeposit($firm, $ledger, $requester, 5000), $approver);

        app(TrustLedgerService::class)->close($firm, $ledger);

        $this->expectException(TrustLedgerNotActiveException::class);
        $this->service->post($firm, $ledger->fresh(), $approved);
    }
}
