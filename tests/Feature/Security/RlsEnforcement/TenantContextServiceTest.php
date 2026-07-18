<?php

namespace Tests\Feature\Security\RlsEnforcement;

use App\Models\Client;
use App\Models\Firm;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * TenantContextServiceTest — Section 39A. Proves TenantContextService's
 * own context lifecycle: cleared after runWithFirmContext() (both the
 * PHP-memory layer and the PostgreSQL session/transaction setting),
 * and does not leak between independent operations (each test's own
 * RefreshDatabase transaction already guarantees no leak between
 * TESTS; this proves no leak WITHIN a single test/process either,
 * which is the harder guarantee — the one that matters for a reused
 * queue worker or Octane process).
 */
class TenantContextServiceTest extends TestCase
{
    use RefreshDatabase;

    private TenantContextService $tenantContext;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantContext = new TenantContextService();
    }

    public function test_has_firm_context_is_false_before_any_context_is_set(): void
    {
        $this->assertFalse($this->tenantContext->hasFirmContext());
        $this->assertNull($this->tenantContext->currentFirmId());
    }

    public function test_set_firm_context_activates_php_memory_context(): void
    {
        $firm = Firm::factory()->create();

        $this->tenantContext->setFirmContext($firm);

        $this->assertTrue($this->tenantContext->hasFirmContext());
        $this->assertSame($firm->id, $this->tenantContext->currentFirmId());

        $this->tenantContext->clearFirmContext();
    }

    public function test_set_firm_context_accepts_a_firm_instance_an_int_id_or_a_uuid_string(): void
    {
        $firm = Firm::factory()->create();

        $this->tenantContext->setFirmContext($firm);
        $this->assertSame($firm->id, $this->tenantContext->currentFirmId());
        $this->tenantContext->clearFirmContext();

        $this->tenantContext->setFirmContext($firm->id);
        $this->assertSame($firm->id, $this->tenantContext->currentFirmId());
        $this->tenantContext->clearFirmContext();

        $this->tenantContext->setFirmContext($firm->uuid);
        $this->assertSame($firm->id, $this->tenantContext->currentFirmId());
        $this->tenantContext->clearFirmContext();
    }

    public function test_clear_firm_context_deactivates_php_memory_context(): void
    {
        $firm = Firm::factory()->create();
        $this->tenantContext->setFirmContext($firm);

        $this->tenantContext->clearFirmContext();

        $this->assertFalse($this->tenantContext->hasFirmContext());
        $this->assertNull($this->tenantContext->currentFirmId());
    }

    public function test_tenant_context_is_cleared_after_run_with_firm_context_php_memory_layer(): void
    {
        $firm = Firm::factory()->create();

        $this->tenantContext->runWithFirmContext($firm, fn () => null);

        $this->assertFalse($this->tenantContext->hasFirmContext());
        $this->assertNull($this->tenantContext->currentFirmId());
    }

    public function test_tenant_context_is_cleared_after_run_with_firm_context_database_layer(): void
    {
        $firmA = Firm::factory()->create();
        // ClientFactory deliberately leaves the database tenant context
        // set to the created row's firm after create() returns (see its
        // own docblock) — clear that baseline first so this test proves
        // what it actually claims to prove: that runWithFirmContext()
        // itself does not leak forward when there was no ambient
        // context active before it was called. (Nesting into an
        // existing ambient context is covered separately by
        // TenantContextServiceSessionScopedNestingTest, where the
        // correct behavior is to restore, not clear, that context.)
        $clientA = Client::factory()->forFirm($firmA)->create();
        $this->tenantContext->clearDatabaseTenantContext();

        DB::statement('ALTER TABLE clients FORCE ROW LEVEL SECURITY');

        $this->tenantContext->runWithFirmContext($firmA, fn () => Client::withoutGlobalScopes()->count());

        // After the call returns, the database session setting must be
        // cleared — an unscoped read (no context) must see 0 rows,
        // proving nothing leaked forward.
        $this->assertSame(0, Client::withoutGlobalScopes()->count());
    }

    public function test_tenant_context_does_not_leak_between_two_sequential_run_with_firm_context_calls(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $clientA = Client::factory()->forFirm($firmA)->create();
        $clientB = Client::factory()->forFirm($firmB)->create();

        DB::statement('ALTER TABLE clients FORCE ROW LEVEL SECURITY');

        $visibleUnderA = $this->tenantContext->runWithFirmContext(
            $firmA,
            fn () => Client::withoutGlobalScopes()->pluck('id')->all(),
        );

        $visibleUnderB = $this->tenantContext->runWithFirmContext(
            $firmB,
            fn () => Client::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertSame([$clientA->id], $visibleUnderA);
        $this->assertSame([$clientB->id], $visibleUnderB, 'Firm A context must not leak into the Firm B call.');
    }

    public function test_context_is_cleared_even_when_the_callback_throws(): void
    {
        $firm = Firm::factory()->create();

        try {
            $this->tenantContext->runWithFirmContext($firm, function () {
                throw new \RuntimeException('deliberate test failure');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertFalse($this->tenantContext->hasFirmContext(), 'Context must be cleared even when the callback throws.');
    }

    public function test_set_database_tenant_context_throws_when_no_php_memory_context_is_active(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->tenantContext->setDatabaseTenantContext();
    }

    public function test_clear_database_tenant_context_is_safe_to_call_with_no_active_context(): void
    {
        $this->tenantContext->clearDatabaseTenantContext();

        $this->assertFalse($this->tenantContext->hasFirmContext());
    }

    /**
     * Section 39A-5, Checkpoint 1 — proves runWithFirmContext() behaves
     * correctly when the CALLING code has already opened its own
     * explicit transaction (not merely RefreshDatabase's own ambient
     * per-test wrapper, which every test in this class already runs
     * inside implicitly). This is distinct from
     * TenantContextServiceSessionScopedNestingTest, which covers
     * non-transactional ambient context nesting — here the outer scope
     * is a real, explicit DB::transaction() the caller itself controls.
     *
     * isLocalScoped() (private) chooses SET LOCAL semantics whenever
     * DB::transactionLevel() > 0 — already true throughout this test
     * from RefreshDatabase alone, so opening a second, explicit
     * DB::transaction() here nests via a SAVEPOINT (Laravel's own
     * transaction-nesting behavior), exactly like a real caller (e.g. a
     * queue job or console command that wraps its own work in a
     * transaction before calling a service that uses
     * runWithFirmContext() internally) would produce.
     */
    public function test_run_with_firm_context_works_correctly_when_called_inside_an_explicit_outer_transaction(): void
    {
        $firmA = Firm::factory()->create();
        // ClientFactory deliberately leaves the database tenant context set
        // to the created row's firm after create() returns (see its own
        // docblock) and never clears it — clear that baseline first so the
        // explicit outer transaction opened below starts from a genuinely
        // empty state. Otherwise the SAVEPOINT this test creates would
        // capture ClientFactory's leftover firm_id as its rollback target
        // (SET LOCAL + ROLLBACK TO SAVEPOINT restores the value held AT
        // SAVEPOINT CREATION time, not NULL), which would make the final
        // assertion below fail for a reason unrelated to what this test
        // actually intends to prove.
        $clientA = Client::factory()->forFirm($firmA)->create();
        $this->tenantContext->clearDatabaseTenantContext();

        DB::statement('ALTER TABLE clients FORCE ROW LEVEL SECURITY');

        $visibleUnderA = null;
        $transactionLevelInsideOuter = null;

        try {
            DB::transaction(function () use ($firmA, &$visibleUnderA, &$transactionLevelInsideOuter) {
                $transactionLevelInsideOuter = DB::transactionLevel();

                $visibleUnderA = $this->tenantContext->runWithFirmContext(
                    $firmA,
                    fn () => Client::withoutGlobalScopes()->pluck('id')->all(),
                );

                // Force the explicit outer transaction opened by THIS
                // test to roll back (via a savepoint rollback, since it
                // is nested inside RefreshDatabase's own transaction),
                // proving the whole scenario — not just runWithFirmContext()
                // in isolation — behaves correctly under rollback.
                throw new \RuntimeException('deliberate rollback of the explicit outer transaction');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertGreaterThan(
            0,
            $transactionLevelInsideOuter,
            'An explicit outer transaction must genuinely be open when runWithFirmContext() is called.'
        );

        $this->assertSame(
            [$clientA->id],
            $visibleUnderA,
            'runWithFirmContext() must still correctly scope reads to the given firm while nested inside an explicit outer transaction.'
        );

        // After the explicit outer transaction rolled back, the session
        // setting must be gone — not merely "restored to empty by our
        // own finally block," but genuinely reverted by PostgreSQL's
        // own SAVEPOINT ROLLBACK, since SET LOCAL is transaction-scoped.
        $this->assertNull($this->tenantContext->currentFirmId());

        $afterRollback = DB::selectOne(
            'select current_setting(?, true) as value',
            ['app.current_firm_id']
        )->value;

        $this->assertTrue(
            $afterRollback === null || $afterRollback === '',
            'The database session setting must be cleared after the explicit outer transaction rolls back.'
        );
    }
}
