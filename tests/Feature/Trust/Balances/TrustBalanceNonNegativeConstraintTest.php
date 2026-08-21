<?php

namespace Tests\Feature\Trust\Balances;

use App\Models\Client;
use App\Models\Matter;
use App\Models\MatterTrustBalance;
use App\Models\TrustBalance;
use App\Services\TrustAccountService;
use App\Services\TrustLedgerService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Trust\Concerns\SetsUpTrustEligibleFirm;
use Tests\TestCase;

/**
 * Trust & Accounting Integrity Hardening, Mission 1.3 — proves the
 * database-level CHECK constraints added in
 * 2026_11_21_100001_add_non_negative_balance_check_to_trust_balances_table
 * and its matter_trust_balances companion actually reject a negative
 * balance, independent of the application-level guards every
 * money-moving service already has (this test bypasses those guards
 * entirely with a direct update, exactly the scenario the constraint
 * exists to catch as a backstop).
 *
 * Deliberately NOT added to trust_ledger_entries.amount_cents — that
 * column is legitimately signed (withdrawals/refunds/reversals are
 * negative), so a non-negative CHECK there would reject correct data.
 * See the migration's own docblock and the Mission 1.3 report for the
 * full verification.
 */
class TrustBalanceNonNegativeConstraintTest extends TestCase
{
    use RefreshDatabase, SetsUpTrustEligibleFirm;

    public function test_the_database_rejects_a_negative_trust_balance(): void
    {
        $firm = $this->makeTrustEligibleFirm();
        $account = app(TrustAccountService::class)->open($firm, 'Firm IOLTA Trust Account');
        $client = Client::factory()->forFirm($firm)->create();
        $ledger = app(TrustLedgerService::class)->open($firm, $account, $client);

        $this->runWithFirmContext($firm, function () use ($ledger) {
            $this->expectException(QueryException::class);

            TrustBalance::query()
                ->where('trust_ledger_id', $ledger->id)
                ->update(['balance_cents' => -1]);
        });
    }

    public function test_the_database_rejects_a_negative_matter_trust_balance(): void
    {
        $firm = $this->makeTrustEligibleFirm();
        $account = app(TrustAccountService::class)->open($firm, 'Firm IOLTA Trust Account');
        $client = Client::factory()->forFirm($firm)->create();
        $ledger = app(TrustLedgerService::class)->open($firm, $account, $client);
        $matter = Matter::factory()->forClient($client)->create();

        $this->runWithFirmContext($firm, function () use ($firm, $ledger, $matter) {
            MatterTrustBalance::query()->create([
                'firm_id' => $firm->id,
                'trust_ledger_id' => $ledger->id,
                'matter_id' => $matter->id,
                'balance_cents' => 0,
                'last_recomputed_at' => now(),
            ]);

            $this->expectException(QueryException::class);

            MatterTrustBalance::query()
                ->where('trust_ledger_id', $ledger->id)
                ->where('matter_id', $matter->id)
                ->update(['balance_cents' => -1]);
        });
    }
}
