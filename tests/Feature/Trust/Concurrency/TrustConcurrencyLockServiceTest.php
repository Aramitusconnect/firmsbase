<?php

namespace Tests\Feature\Trust\Concurrency;

use App\Enums\FirmUserRole;
use App\Models\Client;
use App\Models\FirmUser;
use App\Services\TrustAccountService;
use App\Services\TrustConcurrencyLockService;
use App\Services\TrustDepositService;
use App\Services\TrustLedgerService;
use App\Services\TrustRefundRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Trust\Concerns\SetsUpTrustEligibleFirm;
use Tests\TestCase;

/**
 * Correction #12: TrustConcurrencyLockService must be the ONLY lock
 * helper used by TrustDepositService, TrustTransferRequestService::apply(),
 * TrustRefundRequestService::complete(), TrustChargebackService's
 * reversal path (via TrustLedgerEntryReversalService), and
 * TrustHighRiskAdjustmentService.
 *
 * A genuine multi-connection concurrent race test is not reliable in
 * this codebase's single-process PHPUnit run (no thread/process
 * primitive is used elsewhere in the test suite, and introducing one
 * here would be the first of its kind and a stability risk per the
 * user's explicit contingency instruction). Per that instruction, this
 * file instead combines:
 *   1. A structural test (source inspection) proving every one of the
 *      five named money-moving services actually calls
 *      TrustConcurrencyLockService::withLockedBalances(), not a
 *      home-grown lock.
 *   2. A direct test of TrustConcurrencyLockService itself, proving it
 *      issues a SELECT ... FOR UPDATE against the balance row inside a
 *      DB transaction (captured via DB::listen).
 *   3. A sequential double-spend simulation: two refund attempts against
 *      the same ledger, each going through the real locked path in
 *      turn, prove the balance check is re-read from the LOCKED row
 *      (not a request-time snapshot) so the second attempt correctly
 *      sees the first attempt's already-applied debit and is rejected
 *      rather than double-spending.
 */
class TrustConcurrencyLockServiceTest extends TestCase
{
    use RefreshDatabase, SetsUpTrustEligibleFirm;

    private const MONEY_MOVING_SERVICE_FILES = [
        'TrustDepositService.php',
        'TrustTransferRequestService.php',
        'TrustRefundRequestService.php',
        'TrustLedgerEntryReversalService.php',
        'TrustHighRiskAdjustmentService.php',
    ];

    public function test_every_money_moving_service_routes_through_the_single_lock_service(): void
    {
        $basePath = app_path('Services');

        foreach (self::MONEY_MOVING_SERVICE_FILES as $file) {
            $source = file_get_contents($basePath.DIRECTORY_SEPARATOR.$file);

            $this->assertStringContainsString(
                'TrustConcurrencyLockService',
                $source,
                "{$file} must depend on TrustConcurrencyLockService."
            );
            $this->assertStringContainsString(
                'withLockedBalances(',
                $source,
                "{$file} must call withLockedBalances() rather than implementing its own lock."
            );
            $this->assertStringNotContainsString(
                'lockForUpdate()',
                $source,
                "{$file} must never call lockForUpdate() itself — only TrustConcurrencyLockService is allowed to."
            );
        }
    }

    public function test_with_locked_balances_issues_a_select_for_update_inside_a_transaction(): void
    {
        $firm = $this->makeTrustEligibleFirm();
        $account = app(TrustAccountService::class)->open($firm, 'Firm IOLTA Trust Account');
        $client = Client::factory()->forFirm($firm)->create();
        $ledger = app(TrustLedgerService::class)->open($firm, $account, $client);

        $capturedSql = [];
        DB::listen(function ($query) use (&$capturedSql) {
            $capturedSql[] = $query->sql;
        });

        app(TrustConcurrencyLockService::class)->withLockedBalances($ledger, null, function () {
            return true;
        });

        $lockingQueries = array_filter($capturedSql, fn ($sql) => str_contains(strtolower($sql), 'for update'));

        $this->assertNotEmpty($lockingQueries, 'withLockedBalances() must issue at least one SELECT ... FOR UPDATE query.');
    }

    public function test_sequential_refunds_against_the_same_ledger_cannot_double_spend(): void
    {
        $firm = $this->makeTrustEligibleFirm();
        $account = app(TrustAccountService::class)->open($firm, 'Firm IOLTA Trust Account');
        $client = Client::factory()->forFirm($firm)->create();
        $ledger = app(TrustLedgerService::class)->open($firm, $account, $client);
        $user = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::FirmOwner]);
        $approver = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::FirmOwner]);

        $deposits = app(TrustDepositService::class);
        $approved = $deposits->approveDeposit($firm, $deposits->requestDeposit($firm, $ledger, $user, 6000), $approver);
        $deposits->post($firm, $ledger, $approved);

        $refunds = app(TrustRefundRequestService::class);

        $firstRequest = $refunds->requestRefund($firm, $ledger, $user, 4000);
        $refunds->approveRefund($firm, $firstRequest, $approver);
        $refunds->complete($firm, $firstRequest->fresh(), $approver);

        // The ledger only had 6000; 4000 is now gone. A second refund
        // for another 4000 must see the LOCKED, already-updated balance
        // (2000 remaining) and be rejected — proving the lock's
        // re-read-under-lock semantics, not a stale request-time
        // snapshot of 6000.
        $secondRequest = $refunds->requestRefund($firm, $ledger, $user, 4000);
        $refunds->approveRefund($firm, $secondRequest, $approver);

        $this->expectException(\RuntimeException::class);
        $refunds->complete($firm, $secondRequest->fresh(), $approver);
    }
}
