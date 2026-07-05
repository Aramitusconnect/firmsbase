<?php

namespace Tests\Feature\Trust\Adjustments;

use App\Enums\FirmUserRole;
use App\Enums\TrustLedgerEntryType;
use App\Models\Client;
use App\Models\FirmUser;
use App\Services\TrustAccountService;
use App\Services\TrustDepositService;
use App\Services\TrustHighRiskAdjustmentService;
use App\Services\TrustLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Trust\Concerns\SetsUpTrustEligibleFirm;
use Tests\TestCase;

/**
 * Correction #6: high-risk adjustments require two DIFFERENT approvers,
 * both from {FirmOwner, Attorney}.
 */
class TrustHighRiskAdjustmentServiceTest extends TestCase
{
    use RefreshDatabase, SetsUpTrustEligibleFirm;

    private TrustHighRiskAdjustmentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(TrustHighRiskAdjustmentService::class);
    }

    private function setupFundedLedger(int $amount = 10000): array
    {
        $firm = $this->makeTrustEligibleFirm();
        $account = app(TrustAccountService::class)->open($firm, 'Firm IOLTA Trust Account');
        $client = Client::factory()->forFirm($firm)->create();
        $ledger = app(TrustLedgerService::class)->open($firm, $account, $client);
        $requester = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::BillingStaff]);

        $deposits = app(TrustDepositService::class);
        $approved = $deposits->approveDeposit($firm, $deposits->requestDeposit($firm, $ledger, $requester, $amount), FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::FirmOwner]));
        $deposits->post($firm, $ledger, $approved);

        return [$firm, $ledger, $requester];
    }

    public function test_a_credit_adjustment_requires_two_different_approvers_and_posts_an_adjustment_entry(): void
    {
        [$firm, $ledger, $requester] = $this->setupFundedLedger(10000);
        $firstApprover = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::FirmOwner]);
        $secondApprover = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::Attorney]);

        $requested = $this->service->requestAdjustment($firm, $ledger, $requester, 500, 'Correcting a data-entry error.');
        $firstApproved = $this->service->firstApprove($firm, $requested, $firstApprover);
        $entry = $this->service->secondApprove($firm, $firstApproved, $secondApprover);

        $this->assertSame(TrustLedgerEntryType::Adjustment, $entry->entry_type);
        $this->assertSame(500, $entry->amount_cents);
        $this->assertSame(10500, $ledger->balance->fresh()->balance_cents);
    }

    public function test_the_same_approver_cannot_provide_both_approvals(): void
    {
        [$firm, $ledger, $requester] = $this->setupFundedLedger(10000);
        $approver = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::FirmOwner]);

        $requested = $this->service->requestAdjustment($firm, $ledger, $requester, 500, 'Correcting an error.');
        $firstApproved = $this->service->firstApprove($firm, $requested, $approver);

        $this->expectException(\RuntimeException::class);
        $this->service->secondApprove($firm, $firstApproved, $approver);
    }

    public function test_a_debit_adjustment_cannot_draw_the_ledger_balance_below_zero(): void
    {
        [$firm, $ledger, $requester] = $this->setupFundedLedger(1000);
        $firstApprover = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::FirmOwner]);
        $secondApprover = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::Attorney]);

        $requested = $this->service->requestAdjustment($firm, $ledger, $requester, -5000, 'Large correction.');
        $firstApproved = $this->service->firstApprove($firm, $requested, $firstApprover);

        $this->expectException(\RuntimeException::class);
        $this->service->secondApprove($firm, $firstApproved, $secondApprover);
    }

    public function test_billing_staff_cannot_be_an_approver(): void
    {
        [$firm, $ledger, $requester] = $this->setupFundedLedger(10000);
        $firstApprover = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::FirmOwner]);
        $billingStaffSecond = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::BillingStaff]);

        $requested = $this->service->requestAdjustment($firm, $ledger, $requester, 500, 'Correction.');
        $firstApproved = $this->service->firstApprove($firm, $requested, $firstApprover);

        $this->expectException(\RuntimeException::class);
        $this->service->secondApprove($firm, $firstApproved, $billingStaffSecond);
    }
}
