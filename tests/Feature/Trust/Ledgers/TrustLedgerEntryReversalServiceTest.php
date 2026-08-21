<?php

namespace Tests\Feature\Trust\Ledgers;

use App\Enums\TrustLedgerEntryType;
use App\Enums\TrustLedgerStatus;
use App\Exceptions\TrustLedgerNotActiveException;
use App\Models\Client;
use App\Models\Matter;
use App\Models\MatterTrustBalance;
use App\Models\TrustLedgerEntry;
use App\Services\TenantContextService;
use App\Services\TrustAccountService;
use App\Services\TrustBalanceService;
use App\Services\TrustLedgerEntryReversalService;
use App\Services\TrustLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Trust\Concerns\SetsUpTrustEligibleFirm;
use Tests\TestCase;

class TrustLedgerEntryReversalServiceTest extends TestCase
{
    use RefreshDatabase, SetsUpTrustEligibleFirm;

    private TrustLedgerEntryReversalService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(TrustLedgerEntryReversalService::class);
    }

    public function test_reversal_creates_a_new_opposite_signed_row_and_leaves_the_original_unchanged(): void
    {
        $firm = $this->makeTrustEligibleFirm();
        $account = app(TrustAccountService::class)->open($firm, 'Firm IOLTA Trust Account');
        $client = Client::factory()->forFirm($firm)->create();
        $ledger = app(TrustLedgerService::class)->open($firm, $account, $client);

        $original = TrustLedgerEntry::create([
            'firm_id' => $firm->id,
            'trust_ledger_id' => $ledger->id,
            'entry_type' => TrustLedgerEntryType::Deposit,
            'amount_cents' => 10000,
            'posted_at' => now(),
        ]);
        app(TrustBalanceService::class)->recomputeForLedger($ledger);
        $originalAttributesBefore = $original->fresh()->getAttributes();

        $reversal = $this->service->reverse($firm, $ledger, $original->fresh());

        $this->assertSame(TrustLedgerEntryType::Reversal, $reversal->entry_type);
        $this->assertSame(-10000, $reversal->amount_cents);
        $this->assertSame($original->id, $reversal->reverses_entry_id);
        $this->assertSame($originalAttributesBefore, $original->fresh()->getAttributes());
        $this->assertSame(0, $ledger->balance->fresh()->balance_cents);
    }

    public function test_an_entry_cannot_be_reversed_twice(): void
    {
        $firm = $this->makeTrustEligibleFirm();
        $account = app(TrustAccountService::class)->open($firm, 'Firm IOLTA Trust Account');
        $client = Client::factory()->forFirm($firm)->create();
        $ledger = app(TrustLedgerService::class)->open($firm, $account, $client);

        $original = TrustLedgerEntry::create([
            'firm_id' => $firm->id,
            'trust_ledger_id' => $ledger->id,
            'entry_type' => TrustLedgerEntryType::Deposit,
            'amount_cents' => 10000,
            'posted_at' => now(),
        ]);
        app(TrustBalanceService::class)->recomputeForLedger($ledger);

        $this->service->reverse($firm, $ledger, $original->fresh());

        $this->expectException(\RuntimeException::class);
        $this->service->reverse($firm, $ledger, $original->fresh());
    }

    public function test_a_reversal_entry_cannot_itself_be_reversed(): void
    {
        $firm = $this->makeTrustEligibleFirm();
        $account = app(TrustAccountService::class)->open($firm, 'Firm IOLTA Trust Account');
        $client = Client::factory()->forFirm($firm)->create();
        $ledger = app(TrustLedgerService::class)->open($firm, $account, $client);

        $original = TrustLedgerEntry::create([
            'firm_id' => $firm->id,
            'trust_ledger_id' => $ledger->id,
            'entry_type' => TrustLedgerEntryType::Deposit,
            'amount_cents' => 10000,
            'posted_at' => now(),
        ]);
        app(TrustBalanceService::class)->recomputeForLedger($ledger);
        $reversal = $this->service->reverse($firm, $ledger, $original->fresh());

        $this->expectException(\RuntimeException::class);
        $this->service->reverse($firm, $ledger, $reversal->fresh());
    }

    /**
     * Regression test for the Section 39A-3L Checkpoint 4 Phase D bug:
     * reverse() previously read $originalEntry->matter WITHOUT explicit
     * tenant context. Since `matters` is a FORCE-RLS table, that
     * unwrapped read silently resolved $matter to null (instead of
     * throwing) whenever this method was reached with no ambient
     * database session context active — which is exactly the real
     * condition in production, since this method is reached through
     * TrustChargebackService::reverse(), whose own eligibility check
     * clears any ambient context before calling here (see the
     * companion "reached via TrustChargebackService::reverse()" proof
     * in TrustChargebackServiceTest, which exercises the exact real
     * call path with no manual context manipulation at all). This test
     * proves reverse() itself, in isolation, correctly resolves the
     * real matter and posts a reversal entry whose matter_id matches
     * the original entry's matter_id — not null — even when no ambient
     * context is left over from MatterFactory's own context-hold
     * create() pattern. The explicit clearDatabaseTenantContext() call
     * below is what makes this a genuine proof rather than a
     * tautology: MatterFactory::create() leaves session-scoped context
     * behind it (RefreshDatabase's outer transaction means it is NOT
     * auto-reverted at statement end), so without this explicit clear
     * the ambient context would still be active by accident and this
     * test would pass even with the bug reintroduced.
     */
    public function test_reversal_entry_matter_id_matches_the_original_entrys_matter_and_recomputes_the_matter_balance(): void
    {
        $firm = $this->makeTrustEligibleFirm();
        $account = app(TrustAccountService::class)->open($firm, 'Firm IOLTA Trust Account');
        $client = Client::factory()->forFirm($firm)->create();
        $ledger = app(TrustLedgerService::class)->open($firm, $account, $client);
        $matter = Matter::factory()->forClient($client)->create();

        $original = TrustLedgerEntry::create([
            'firm_id' => $firm->id,
            'trust_ledger_id' => $ledger->id,
            'matter_id' => $matter->id,
            'entry_type' => TrustLedgerEntryType::Deposit,
            'amount_cents' => 10000,
            'posted_at' => now(),
        ]);
        app(TrustBalanceService::class)->recomputeForLedger($ledger);
        app(TrustBalanceService::class)->recomputeForMatter($ledger, $matter);

        // Resolve the fresh copy of $original BEFORE clearing context.
        // trust_ledger_entries is now (Wave 10) FORCE-RLS'd and this
        // model does not use BelongsToTenant, so a bare ->fresh() call
        // issued with no ambient app.current_firm_id would itself now
        // return null (RLS's USING clause hides every row), which
        // would make this test fail for a reason unrelated to what it
        // is actually proving. The test's real subject is whether
        // reverse() ITSELF correctly re-establishes context to resolve
        // $originalEntry->matter internally — not whether a raw
        // ->fresh() call outside any service wrap can see the row. So
        // the fresh() read happens under the still-active context left
        // by MatterFactory's context-hold create(), and only then do
        // we clear context before calling into the service under test.
        $originalFresh = $original->fresh();

        // Explicitly clear any ambient database tenant context left
        // over from Matter::factory()->create()'s context-hold
        // pattern above, so this test genuinely reproduces the
        // no-context condition reverse() must fail-closed/succeed
        // correctly under, rather than accidentally inheriting a
        // still-active session setting.
        (new TenantContextService)->clearDatabaseTenantContext();

        $reversal = $this->service->reverse($firm, $ledger, $originalFresh);

        $this->assertSame($matter->id, $reversal->matter_id);
        $this->assertSame(TrustLedgerEntryType::Reversal, $reversal->entry_type);
        $this->assertSame(-10000, $reversal->amount_cents);

        // $ledger->balance is being lazy-loaded here for the first time
        // in this test (unlike the other test methods in this file,
        // which access it earlier while context is still ambient).
        // trust_balances is now (Wave 10) FORCE-RLS'd, so this relation
        // read must run under the ledger's own firm context — same
        // reasoning as $originalFresh above and the matterBalance read
        // below, not a change to what this assertion actually proves.
        $ledgerBalance = $this->runWithFirmContext($firm, fn () => $ledger->balance()->firstOrFail());
        $this->assertSame(0, $ledgerBalance->balance_cents);

        $matterBalance = $this->runWithFirmContext($firm, fn () => MatterTrustBalance::query()
            ->where('trust_ledger_id', $ledger->id)
            ->where('matter_id', $matter->id)
            ->firstOrFail());
        $this->assertSame(0, $matterBalance->balance_cents);
    }

    /**
     * Trust & Accounting Integrity Hardening, Mission 1.1: reverse()
     * must refuse to post against a Frozen or Closed ledger, applying
     * the same uniform rule as every other money-moving entry point —
     * this codebase has no governed exception carving out reversals
     * during a freeze.
     */
    public function test_an_entry_cannot_be_reversed_once_its_ledger_is_frozen(): void
    {
        $firm = $this->makeTrustEligibleFirm();
        $account = app(TrustAccountService::class)->open($firm, 'Firm IOLTA Trust Account');
        $client = Client::factory()->forFirm($firm)->create();
        $ledger = app(TrustLedgerService::class)->open($firm, $account, $client);

        $original = TrustLedgerEntry::create([
            'firm_id' => $firm->id,
            'trust_ledger_id' => $ledger->id,
            'entry_type' => TrustLedgerEntryType::Deposit,
            'amount_cents' => 10000,
            'posted_at' => now(),
        ]);
        app(TrustBalanceService::class)->recomputeForLedger($ledger);

        app(TrustLedgerService::class)->freeze($firm, $ledger->fresh());

        try {
            $this->service->reverse($firm, $ledger->fresh(), $original->fresh());
            $this->fail('Expected a TrustLedgerNotActiveException.');
        } catch (TrustLedgerNotActiveException $e) {
            $this->assertSame(TrustLedgerStatus::Frozen, $e->status);
        }

        $this->assertSame(10000, $ledger->balance->fresh()->balance_cents);
    }
}
