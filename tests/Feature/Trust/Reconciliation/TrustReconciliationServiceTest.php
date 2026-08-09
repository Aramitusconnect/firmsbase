<?php

namespace Tests\Feature\Trust\Reconciliation;

use App\Enums\FirmUserRole;
use App\Enums\TrustReconciliationStatus;
use App\Models\Client;
use App\Models\FirmUser;
use App\Models\Matter;
use App\Models\MatterTrustBalance;
use App\Services\TenantContextService;
use App\Services\TrustAccountService;
use App\Services\TrustDepositService;
use App\Services\TrustLedgerService;
use App\Services\TrustReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Trust\Concerns\SetsUpTrustEligibleFirm;
use Tests\TestCase;

class TrustReconciliationServiceTest extends TestCase
{
    use RefreshDatabase, SetsUpTrustEligibleFirm;

    private TrustReconciliationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(TrustReconciliationService::class);
    }

    public function test_reconciliation_is_balanced_when_asserted_bank_balance_matches_the_system(): void
    {
        $firm = $this->makeTrustEligibleFirm();
        $account = app(TrustAccountService::class)->open($firm, 'Firm IOLTA Trust Account');
        $client = Client::factory()->forFirm($firm)->create();
        $ledger = app(TrustLedgerService::class)->open($firm, $account, $client);
        $user = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::FirmOwner]);
        $depositApprover = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::FirmOwner]);

        $deposits = app(TrustDepositService::class);
        $approved = $deposits->approveDeposit($firm, $deposits->requestDeposit($firm, $ledger, $user, 10000), $depositApprover);
        $deposits->post($firm, $ledger, $approved);

        $reconciliation = $this->service->run($firm, $account, $user, now()->subMonth(), now(), 10000);

        $this->assertSame(TrustReconciliationStatus::Balanced, $reconciliation->status);
        $this->assertSame(0, $reconciliation->discrepancy_cents);
    }

    public function test_reconciliation_records_a_discrepancy_without_auto_correcting_anything(): void
    {
        $firm = $this->makeTrustEligibleFirm();
        $account = app(TrustAccountService::class)->open($firm, 'Firm IOLTA Trust Account');
        $client = Client::factory()->forFirm($firm)->create();
        $ledger = app(TrustLedgerService::class)->open($firm, $account, $client);
        $user = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::FirmOwner]);
        $depositApprover = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::FirmOwner]);

        $deposits = app(TrustDepositService::class);
        $approved = $deposits->approveDeposit($firm, $deposits->requestDeposit($firm, $ledger, $user, 10000), $depositApprover);
        $deposits->post($firm, $ledger, $approved);

        $reconciliation = $this->service->run($firm, $account, $user, now()->subMonth(), now(), 9500);

        $this->assertSame(TrustReconciliationStatus::Discrepancy, $reconciliation->status);
        $this->assertSame(500, $reconciliation->discrepancy_cents);
        // The system's own cached ledger balance must be untouched by
        // running a reconciliation — discrepancies are never
        // auto-corrected.
        $this->assertSame(10000, $ledger->balance->fresh()->balance_cents);
    }

    /**
     * Dedicated regression test for the Wave 10 §0 fail-open fix:
     * before the fix, run() never established tenant context, so
     * $account->ledgers (gated by BelongsToTenant's global scope, and
     * now by trust_ledgers' own FORCE RLS policy) silently returned an
     * EMPTY collection instead of throwing. That left
     * $systemBalanceCents at 0 for the entire loop, so asserting a
     * bank balance of exactly $0 against a real, nonzero ledger
     * balance would have WRONGLY reported Balanced (0 - 0 = 0
     * discrepancy) — the worst possible outcome for a trust-accounting
     * reconciliation, since it would mask a genuine shortfall. This
     * test proves the fix end-to-end, through the real public
     * TrustReconciliationService::run() call path exactly as
     * production would invoke it (no direct context manipulation, no
     * bypass): a real TrustAccount with a real TrustLedger carrying a
     * real, posted, nonzero ($10000) balance, reconciled against an
     * asserted bank balance of exactly $0, must report Discrepancy —
     * never Balanced — with the discrepancy amount reflecting the
     * true $10000 system balance.
     *
     * Empirically confirmed to be a genuine, non-tautological proof
     * (not merely structural): temporarily reverting
     * TrustReconciliationService::run() to its pre-fix, unwrapped form
     * during this review and re-running this exact test WITHOUT the
     * explicit clearDatabaseTenantContext() call below still passed —
     * because every earlier factory/service call in this test's own
     * setup (makeTrustEligibleFirm(), TrustAccountService::open(),
     * etc.) leaves ambient PostgreSQL session context lingering at
     * this SAME firm's id for the rest of the RefreshDatabase
     * transaction (factories' context-hold create() pattern never
     * clears it, and runWithFirmContext() only ever restores to
     * whatever was already ambient). That accidentally matches the
     * firm under test and masks the real production condition — every
     * real call site reaches this method with NO ambient context at
     * all. The explicit clear below removes that lingering artifact
     * and reproduces the true no-context condition, which is what
     * makes this test actually fail against the reverted, pre-fix
     * code (confirmed) and pass only against the real fix (confirmed).
     */
    public function test_reconciliation_reports_discrepancy_not_balanced_when_asserted_bank_balance_is_zero_against_a_nonzero_real_ledger_balance(): void
    {
        $firm = $this->makeTrustEligibleFirm();
        $account = app(TrustAccountService::class)->open($firm, 'Firm IOLTA Trust Account');
        $client = Client::factory()->forFirm($firm)->create();
        $ledger = app(TrustLedgerService::class)->open($firm, $account, $client);
        $user = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::FirmOwner]);
        $depositApprover = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::FirmOwner]);

        $deposits = app(TrustDepositService::class);
        $approved = $deposits->approveDeposit($firm, $deposits->requestDeposit($firm, $ledger, $user, 10000), $depositApprover);
        $deposits->post($firm, $ledger, $approved);

        // Sanity-check the real ledger balance really is nonzero
        // before asserting a $0 bank balance against it — otherwise a
        // 0-vs-0 comparison would trivially (and meaninglessly) report
        // Balanced regardless of whether the fail-open bug exists.
        $this->assertSame(10000, $ledger->balance->fresh()->balance_cents);

        // Explicitly clear ambient PostgreSQL tenant context left
        // behind by the fixture setup above (see this test's own
        // docblock) so run() is genuinely reached with NO context —
        // the real production condition every actual call site hits —
        // rather than accidentally inheriting a still-active session
        // setting that happens to match this firm.
        (new TenantContextService)->clearDatabaseTenantContext();

        $reconciliation = $this->service->run($firm, $account, $user, now()->subMonth(), now(), assertedBankBalanceCents: 0);

        $this->assertSame(
            TrustReconciliationStatus::Discrepancy,
            $reconciliation->status,
            'A $0 asserted bank balance against a real $10000 ledger balance must report Discrepancy — reporting Balanced here would mean the fail-open bug (silently empty $account->ledgers under missing tenant context) has regressed.'
        );
        $this->assertSame(10000, $reconciliation->system_balance_cents, 'The system balance must reflect the REAL $10000 ledger balance, not a silently-empty-collection 0.');
        $this->assertSame(0, $reconciliation->asserted_bank_balance_cents);
        $this->assertSame(10000, $reconciliation->discrepancy_cents);
    }

    public function test_reconciliation_fails_fast_if_a_ledgers_own_cache_is_stale(): void
    {
        $firm = $this->makeTrustEligibleFirm();
        $account = app(TrustAccountService::class)->open($firm, 'Firm IOLTA Trust Account');
        $client = Client::factory()->forFirm($firm)->create();
        $ledger = app(TrustLedgerService::class)->open($firm, $account, $client);
        $user = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::FirmOwner]);

        $ledger->balance->update(['balance_cents' => 999]);

        $this->expectException(\RuntimeException::class);
        $this->service->run($firm, $account, $user, now()->subMonth(), now(), 999);
    }

    /**
     * Phase H — the third independent leg. A matter-attributed deposit
     * whose matter_trust_balances cache is deliberately left stale
     * (simulating a bug that recomputes the ledger-level cache but
     * forgets to recompute the affected matter's own cache) must be
     * caught by the client-liability leg even though bank vs. system
     * still agrees perfectly — this is exactly the class of drift the
     * pre-existing two-way check could never see.
     */
    public function test_reconciliation_reports_discrepancy_when_matter_liability_cache_has_drifted_from_the_ledger(): void
    {
        $firm = $this->makeTrustEligibleFirm();
        $account = app(TrustAccountService::class)->open($firm, 'Firm IOLTA Trust Account');
        $client = Client::factory()->forFirm($firm)->create();
        $ledger = app(TrustLedgerService::class)->open($firm, $account, $client);
        $matter = Matter::factory()->forClient($client)->create();
        $user = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::FirmOwner]);
        $depositApprover = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::FirmOwner]);

        $deposits = app(TrustDepositService::class);
        $approved = $deposits->approveDeposit($firm, $deposits->requestDeposit($firm, $ledger, $user, 10000, $matter), $depositApprover);
        $deposits->post($firm, $ledger, $approved, $matter);

        // Simulate the matter cache silently falling out of sync with
        // the ledger cache — trust_ledger_entries themselves are
        // untouched, so the ledger-level cache remains fully correct.
        MatterTrustBalance::query()
            ->where('trust_ledger_id', $ledger->id)
            ->where('matter_id', $matter->id)
            ->first()
            ->update(['balance_cents' => 4000]);

        $reconciliation = $this->service->run($firm, $account, $user, now()->subMonth(), now(), 10000);

        $this->assertSame(TrustReconciliationStatus::Discrepancy, $reconciliation->status);
        $this->assertSame(0, $reconciliation->discrepancy_cents, 'Bank vs. system must still agree — this drift is invisible to the old two-way check.');
        $this->assertSame(10000, $reconciliation->system_balance_cents);
        $this->assertSame(4000, $reconciliation->client_liability_cents);
        $this->assertSame(6000, $reconciliation->client_liability_discrepancy_cents);
    }

    /**
     * Accounting Integrity Hardening Pass, item 5 — the fourth
     * combination the three-way check must reject: bank agrees with
     * the (drifted) client-liability leg but NOT with the ledger leg.
     * Both discrepancy_cents and client_liability_discrepancy_cents
     * must be nonzero, and status must be Discrepancy — a reconciliation
     * is never "half-balanced."
     */
    public function test_reconciliation_reports_discrepancy_when_bank_agrees_with_client_liabilities_but_not_the_ledger(): void
    {
        $firm = $this->makeTrustEligibleFirm();
        $account = app(TrustAccountService::class)->open($firm, 'Firm IOLTA Trust Account');
        $client = Client::factory()->forFirm($firm)->create();
        $ledger = app(TrustLedgerService::class)->open($firm, $account, $client);
        $matter = Matter::factory()->forClient($client)->create();
        $user = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::FirmOwner]);
        $depositApprover = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::FirmOwner]);

        $deposits = app(TrustDepositService::class);
        $approved = $deposits->approveDeposit($firm, $deposits->requestDeposit($firm, $ledger, $user, 10000, $matter), $depositApprover);
        $deposits->post($firm, $ledger, $approved, $matter);

        MatterTrustBalance::query()
            ->where('trust_ledger_id', $ledger->id)
            ->where('matter_id', $matter->id)
            ->first()
            ->update(['balance_cents' => 4000]);

        $reconciliation = $this->service->run($firm, $account, $user, now()->subMonth(), now(), assertedBankBalanceCents: 4000);

        $this->assertSame(TrustReconciliationStatus::Discrepancy, $reconciliation->status);
        $this->assertSame(10000, $reconciliation->system_balance_cents);
        $this->assertSame(4000, $reconciliation->asserted_bank_balance_cents);
        $this->assertSame(4000, $reconciliation->client_liability_cents);
        $this->assertSame(6000, $reconciliation->discrepancy_cents, 'Bank matches the drifted client-liability leg, not the real ledger balance.');
        $this->assertSame(6000, $reconciliation->client_liability_discrepancy_cents);
    }

    /**
     * Accounting Integrity Hardening Pass, item 5 — the "ledger agrees
     * with client liabilities but not the bank" combination, made
     * explicit with a matter-attributed deposit (the plain
     * unattributed-deposit discrepancy test above already exercises
     * this combination implicitly; this test names it directly per the
     * hardening pass's own required combination list).
     */
    public function test_reconciliation_reports_discrepancy_when_ledger_agrees_with_client_liabilities_but_not_the_bank(): void
    {
        $firm = $this->makeTrustEligibleFirm();
        $account = app(TrustAccountService::class)->open($firm, 'Firm IOLTA Trust Account');
        $client = Client::factory()->forFirm($firm)->create();
        $ledger = app(TrustLedgerService::class)->open($firm, $account, $client);
        $matter = Matter::factory()->forClient($client)->create();
        $user = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::FirmOwner]);
        $depositApprover = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::FirmOwner]);

        $deposits = app(TrustDepositService::class);
        $approved = $deposits->approveDeposit($firm, $deposits->requestDeposit($firm, $ledger, $user, 7500, $matter), $depositApprover);
        $deposits->post($firm, $ledger, $approved, $matter);

        $reconciliation = $this->service->run($firm, $account, $user, now()->subMonth(), now(), assertedBankBalanceCents: 7000);

        $this->assertSame(TrustReconciliationStatus::Discrepancy, $reconciliation->status);
        $this->assertSame(7500, $reconciliation->system_balance_cents);
        $this->assertSame(7500, $reconciliation->client_liability_cents, 'No matter-cache drift here — client liabilities correctly agree with the ledger.');
        $this->assertSame(0, $reconciliation->client_liability_discrepancy_cents);
        $this->assertSame(500, $reconciliation->discrepancy_cents, 'Only the bank leg disagrees.');
    }

    public function test_reconciliation_is_balanced_when_all_three_legs_agree_with_a_matter_attributed_deposit(): void
    {
        $firm = $this->makeTrustEligibleFirm();
        $account = app(TrustAccountService::class)->open($firm, 'Firm IOLTA Trust Account');
        $client = Client::factory()->forFirm($firm)->create();
        $ledger = app(TrustLedgerService::class)->open($firm, $account, $client);
        $matter = Matter::factory()->forClient($client)->create();
        $user = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::FirmOwner]);
        $depositApprover = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::FirmOwner]);

        $deposits = app(TrustDepositService::class);
        $approved = $deposits->approveDeposit($firm, $deposits->requestDeposit($firm, $ledger, $user, 7500, $matter), $depositApprover);
        $deposits->post($firm, $ledger, $approved, $matter);

        $reconciliation = $this->service->run($firm, $account, $user, now()->subMonth(), now(), 7500);

        $this->assertSame(TrustReconciliationStatus::Balanced, $reconciliation->status);
        $this->assertSame(0, $reconciliation->discrepancy_cents);
        $this->assertSame(0, $reconciliation->client_liability_discrepancy_cents);
        $this->assertSame(7500, $reconciliation->client_liability_cents);
    }
}
