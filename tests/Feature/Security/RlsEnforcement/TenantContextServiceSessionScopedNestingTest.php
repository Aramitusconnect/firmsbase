<?php

namespace Tests\Feature\Security\RlsEnforcement;

use App\Models\Firm;
use App\Services\TenantContextService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Regression test for the runWithFirmContext() nested-context leak.
 *
 * Deliberately does NOT use RefreshDatabase. RefreshDatabase wraps every
 * test in a real outer transaction, which makes DB::transactionLevel() > 0
 * for the entire test body — that forces every set_config() call in
 * TenantContextService onto SET LOCAL semantics (see isLocalScoped()),
 * which auto-reverts at the test's own transaction rollback regardless of
 * whether runWithFirmContext() itself saves/restores correctly. That is
 * exactly what let this bug stay invisible to the rest of the existing
 * TenantContextServiceTest suite: none of those tests can ever observe a
 * true session-scoped (non-transactional) leak.
 *
 * In production, the one place an ambient firm context is set outside of
 * any transaction is HTTP middleware (see FirmPanelProvider's
 * EstablishFirmTenantContext + ApplyTenantDatabaseContext on the `firm`
 * panel) — this test reproduces that shape directly: a real,
 * non-transactional, session-scoped set before calling
 * runWithFirmContext(), proving the nested call restores rather than
 * wipes it.
 */
class TenantContextServiceSessionScopedNestingTest extends TestCase
{
    private TenantContextService $tenantContext;

    private Firm $outerFirm;

    private Firm $innerFirm;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantContext = new TenantContextService();
        $this->outerFirm = Firm::factory()->create();
        $this->innerFirm = Firm::factory()->create();
    }

    protected function tearDown(): void
    {
        $this->tenantContext->clearDatabaseTenantContext();
        $this->tenantContext->clearFirmContext();

        $this->outerFirm->delete();
        $this->innerFirm->delete();

        parent::tearDown();
    }

    public function test_nested_run_with_firm_context_restores_ambient_session_scoped_outer_context(): void
    {
        $this->assertSame(
            0,
            DB::transactionLevel(),
            'This test must run outside of any transaction to reproduce the session-scoped (SET, not SET LOCAL) production path — if this fails, RefreshDatabase or similar has been added and will mask the bug this test exists to catch.'
        );

        // Simulate an ambient, middleware-established context — set
        // outside of any transaction, exactly like EstablishFirmTenantContext
        // would for a whole HTTP request.
        $this->tenantContext->setFirmContext($this->outerFirm);
        $this->tenantContext->setDatabaseTenantContextForFirmId($this->outerFirm->id);

        $this->tenantContext->runWithFirmContext($this->innerFirm, fn () => null);

        $this->assertSame(
            $this->outerFirm->id,
            $this->tenantContext->currentFirmId(),
            'Nested runWithFirmContext() must restore the outer PHP-memory firm context, not wipe it.'
        );

        $restoredDatabaseValue = DB::selectOne(
            'select current_setting(?, true) as value',
            ['app.current_firm_id']
        )->value;

        $this->assertSame(
            (string) $this->outerFirm->id,
            $restoredDatabaseValue,
            'Nested runWithFirmContext() must restore the outer ambient database session setting, not wipe it.'
        );
    }

    public function test_nested_run_with_firm_context_still_clears_when_there_was_no_outer_context(): void
    {
        $this->assertSame(0, DB::transactionLevel());

        $this->tenantContext->runWithFirmContext($this->innerFirm, fn () => null);

        $this->assertNull(
            $this->tenantContext->currentFirmId(),
            'With no outer context active, runWithFirmContext() must still leave PHP-memory context cleared.'
        );

        $restoredDatabaseValue = DB::selectOne(
            'select current_setting(?, true) as value',
            ['app.current_firm_id']
        )->value;

        $this->assertTrue(
            $restoredDatabaseValue === null || $restoredDatabaseValue === '',
            'With no outer context active, runWithFirmContext() must still leave the database session setting cleared.'
        );
    }

    public function test_run_without_firm_context_restores_database_only_outer_context(): void
    {
        $this->assertSame(
            0,
            DB::transactionLevel(),
            'This test must remain outside a transaction so the outer database context is session scoped.'
        );

        $this->assertNull(
            $this->tenantContext->currentFirmId(),
            'The regression setup must begin without PHP-memory tenant context.'
        );

        // Establish only the PostgreSQL context. PHP-memory context
        // intentionally remains null.
        $this->tenantContext->setDatabaseTenantContextForFirmId(
            $this->outerFirm->id
        );

        $before = DB::selectOne(
            'select current_setting(?, true) as value',
            ['app.current_firm_id']
        )->value;

        $this->assertSame(
            (string) $this->outerFirm->id,
            $before,
            'The database-only outer context must be active before the callback.'
        );

        $this->tenantContext->runWithoutFirmContext(function (): void {
            $this->assertNull(
                $this->tenantContext->currentFirmId(),
                'runWithoutFirmContext() must keep PHP-memory context cleared.'
            );

            $during = DB::selectOne(
                'select current_setting(?, true) as value',
                ['app.current_firm_id']
            )->value;

            $this->assertTrue(
                $during === null || $during === '',
                'runWithoutFirmContext() must clear the database context inside the callback.'
            );
        });

        $this->assertNull(
            $this->tenantContext->currentFirmId(),
            'Restoring a database-only outer context must not invent PHP-memory context.'
        );

        $after = DB::selectOne(
            'select current_setting(?, true) as value',
            ['app.current_firm_id']
        )->value;

        $this->assertSame(
            (string) $this->outerFirm->id,
            $after,
            'runWithoutFirmContext() must restore the original database-only outer context.'
        );
    }

}
