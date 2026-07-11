<?php

namespace Tests\Feature\Trust\Refunds;

use App\Enums\FirmUserRole;
use App\Enums\TrustLedgerEntryType;
use App\Enums\TrustRefundRequestStatus;
use App\Models\Client;
use App\Models\FirmUser;
use App\Models\Matter;
use App\Services\TrustAccountService;
use App\Services\TrustDepositService;
use App\Services\TrustLedgerService;
use App\Services\TrustRefundRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Trust\Concerns\SetsUpTrustEligibleFirm;
use Tests\TestCase;

class TrustRefundRequestServiceTest extends TestCase
{
    use RefreshDatabase, SetsUpTrustEligibleFirm;

    private TrustRefundRequestService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(TrustRefundRequestService::class);
    }

    private function setupFundedLedger(int $depositAmount = 10000): array
    {
        $firm = $this->makeTrustEligibleFirm();
        $account = app(TrustAccountService::class)->open($firm, 'Firm IOLTA Trust Account');
        $client = Client::factory()->forFirm($firm)->create();
        $ledger = app(TrustLedgerService::class)->open($firm, $account, $client);
        $user = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::FirmOwner]);

        $deposits = app(TrustDepositService::class);
        $approved = $deposits->approveDeposit($firm, $deposits->requestDeposit($firm, $ledger, $user, $depositAmount), $user);
        $deposits->post($firm, $ledger, $approved);

        return [$firm, $ledger, $user];
    }

    public function test_full_refund_lifecycle_posts_a_refund_entry(): void
    {
        [$firm, $ledger, $user] = $this->setupFundedLedger(10000);

        $request = $this->service->requestRefund($firm, $ledger, $user, 4000);
        $this->service->approveRefund($firm, $request, $user);
        $entry = $this->service->complete($firm, $request->fresh(), $user);

        $this->assertSame(TrustLedgerEntryType::Refund, $entry->entry_type);
        $this->assertSame(-4000, $entry->amount_cents);
        $this->assertSame(TrustRefundRequestStatus::Completed, $request->fresh()->status);
        $this->assertSame(6000, $ledger->balance->fresh()->balance_cents);
    }

    public function test_refund_cannot_exceed_the_available_balance(): void
    {
        [$firm, $ledger, $user] = $this->setupFundedLedger(1000);

        $request = $this->service->requestRefund($firm, $ledger, $user, 5000);
        $this->service->approveRefund($firm, $request, $user);

        $this->expectException(\RuntimeException::class);
        $this->service->complete($firm, $request->fresh(), $user);
    }

    public function test_refund_cannot_be_completed_before_approval(): void
    {
        [$firm, $ledger, $user] = $this->setupFundedLedger(10000);

        $request = $this->service->requestRefund($firm, $ledger, $user, 4000);

        $this->expectException(\RuntimeException::class);
        $this->service->complete($firm, $request, $user);
    }

    /**
     * Regression test for the Section 39A-3L Checkpoint 4 Phase D bug:
     * complete() previously read $request->trustLedger / $request->matter
     * WITHOUT explicit tenant context. Since `matters` is a FORCE-RLS
     * table, that unwrapped read silently resolved $matter to null
     * (instead of throwing), which meant the `if ($matter)` gate below
     * silently SKIPPED assertDebitKeepsMatterBalanceNonNegative()
     * entirely — a refund attributed to a matter could silently drain
     * OTHER matters' attributed trust funds as long as the ledger's
     * total balance covered it. This test builds a ledger whose TOTAL
     * balance is large enough to cover the refund, but whose matter-
     * attributed balance is not, so the bug (if reintroduced) would let
     * this refund complete successfully instead of throwing.
     */
    public function test_refund_exceeding_the_matters_attributed_balance_is_rejected_even_when_the_ledger_total_covers_it(): void
    {
        $firm = $this->makeTrustEligibleFirm();
        $account = app(TrustAccountService::class)->open($firm, 'Firm IOLTA Trust Account');
        $client = Client::factory()->forFirm($firm)->create();
        $ledger = app(TrustLedgerService::class)->open($firm, $account, $client);
        $matter = Matter::factory()->forClient($client)->create();
        $user = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::FirmOwner]);

        $deposits = app(TrustDepositService::class);

        // Unattributed deposit: inflates the LEDGER total (13000) far
        // beyond the refund amount, without touching the matter's own
        // attributed balance.
        $unattributed = $deposits->approveDeposit($firm, $deposits->requestDeposit($firm, $ledger, $user, 10000), $user);
        $deposits->post($firm, $ledger, $unattributed);

        // Matter-attributed deposit: only 3000 is attributed to this
        // specific matter.
        $attributed = $deposits->approveDeposit($firm, $deposits->requestDeposit($firm, $ledger, $user, 3000, $matter), $user);
        $deposits->post($firm, $ledger, $attributed, $matter);

        $this->assertSame(13000, $ledger->balance->fresh()->balance_cents);

        // Refund of 5000 attributed to the matter: ledger total
        // (13000 - 5000 = 8000) is still comfortably positive, but the
        // matter's own attributed balance (3000 - 5000 = -2000) would
        // go negative. This MUST be rejected.
        $request = $this->service->requestRefund($firm, $ledger, $user, 5000, $matter);
        $this->service->approveRefund($firm, $request, $user);

        try {
            $this->service->complete($firm, $request->fresh(), $user);
            $this->fail('Expected a RuntimeException because the refund would draw the matter attributed balance below zero.');
        } catch (\RuntimeException $e) {
            $this->assertSame(
                "This operation would draw the matter's attributed trust balance below zero.",
                $e->getMessage(),
            );
        }

        // Nothing was posted: neither the ledger total nor the request
        // status changed.
        $this->assertSame(13000, $ledger->balance->fresh()->balance_cents);
        $this->assertSame(TrustRefundRequestStatus::Approved, $request->fresh()->status);
    }

    /**
     * The positive-path companion to the above: a refund that stays
     * within the matter's own attributed balance must succeed, and the
     * resulting trust_ledger_entries row's matter_id must correctly
     * match the request's matter — never null — proving complete()'s
     * fixed context-wrapped read actually resolves the real matter
     * rather than merely failing safe.
     */
    public function test_refund_within_the_matters_attributed_balance_succeeds_and_the_entry_matter_id_matches(): void
    {
        $firm = $this->makeTrustEligibleFirm();
        $account = app(TrustAccountService::class)->open($firm, 'Firm IOLTA Trust Account');
        $client = Client::factory()->forFirm($firm)->create();
        $ledger = app(TrustLedgerService::class)->open($firm, $account, $client);
        $matter = Matter::factory()->forClient($client)->create();
        $user = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::FirmOwner]);

        $deposits = app(TrustDepositService::class);
        $attributed = $deposits->approveDeposit($firm, $deposits->requestDeposit($firm, $ledger, $user, 3000, $matter), $user);
        $deposits->post($firm, $ledger, $attributed, $matter);

        $request = $this->service->requestRefund($firm, $ledger, $user, 2000, $matter);
        $this->service->approveRefund($firm, $request, $user);
        $entry = $this->service->complete($firm, $request->fresh(), $user);

        $this->assertSame($matter->id, $entry->matter_id);
        $this->assertSame(TrustLedgerEntryType::Refund, $entry->entry_type);
        $this->assertSame(-2000, $entry->amount_cents);
        $this->assertSame(1000, $ledger->balance->fresh()->balance_cents);

        $matterBalance = $this->runWithFirmContext($firm, fn () => \App\Models\MatterTrustBalance::query()
            ->where('trust_ledger_id', $ledger->id)
            ->where('matter_id', $matter->id)
            ->firstOrFail());
        $this->assertSame(1000, $matterBalance->balance_cents);
    }
}
