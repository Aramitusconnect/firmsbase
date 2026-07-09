<?php

namespace Tests\Feature\Security\RlsEnforcement;

use App\Models\Client;
use App\Models\Firm;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * RlsForceEnforcementTest — Section 39A. Proves the FORCE ROW LEVEL
 * SECURITY enforcement mechanism itself is correct against the REAL,
 * already-prepared `clients` table (Phase 2) — fail-closed with no
 * context, correct cross-firm read/write isolation, correct same-firm
 * access — using a self-contained, scoped `ALTER TABLE ... FORCE ROW
 * LEVEL SECURITY` inside each test's own transaction, applied only
 * AFTER fixture rows are created (factories create rows with no
 * tenant context established, exactly like the rest of today's test
 * suite — FORCE is switched on afterward, purely to test the read/
 * write enforcement mechanism itself).
 *
 * This does NOT permanently enable FORCE on the live schema: Postgres
 * DDL is transactional, and RefreshDatabase wraps every test in a
 * transaction that is rolled back at teardown, so each test's FORCE
 * statement is automatically undone — confirmed empirically (see
 * RlsPreparationCoverageTest, which runs in the same suite and still
 * finds FORCE unset on every prepared table). This is the approved
 * Section 39A scope: prove the mechanism works, without flipping
 * enforcement on for the ~120+ existing tests that create tenant-owned
 * rows with no tenant context established.
 */
class RlsForceEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private TenantContextService $tenantContext;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantContext = new TenantContextService();
    }

    private function forceRls(): void
    {
        DB::statement('ALTER TABLE clients FORCE ROW LEVEL SECURITY');
    }

    public function test_missing_tenant_context_cannot_read_tenant_owned_rows(): void
    {
        $firm = Firm::factory()->create();
        Client::factory()->forFirm($firm)->create();

        // Section 39A-3A — ClientFactory now activates DB tenant
        // context for its own INSERT (clients has permanent FORCE RLS)
        // and deliberately leaves it set afterward for the common
        // "create then read" test pattern. This test is specifically
        // about the OPPOSITE case — no context at all — so it must
        // explicitly clear back to a clean slate first.
        $this->tenantContext->clearDatabaseTenantContext();

        $this->forceRls();

        $this->assertSame(0, Client::withoutGlobalScopes()->count());
    }

    public function test_missing_tenant_context_cannot_write_tenant_owned_rows(): void
    {
        $firm = Firm::factory()->create();

        $this->forceRls();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('clients')->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid7(),
            'firm_id' => $firm->id,
            'display_name' => 'No Context Insert',
            'preferred_language' => 'en',
            'portal_status' => 'not_invited',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_firm_a_context_cannot_read_firm_b_tenant_owned_rows(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        Client::factory()->forFirm($firmA)->create();
        $clientB = Client::factory()->forFirm($firmB)->create();

        $this->forceRls();

        $visibleIds = $this->tenantContext->runWithFirmContext(
            $firmA,
            fn () => Client::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertNotContains($clientB->id, $visibleIds);
    }

    public function test_firm_a_context_can_read_its_own_rows(): void
    {
        $firmA = Firm::factory()->create();
        $clientA = Client::factory()->forFirm($firmA)->create();

        $this->forceRls();

        $visibleIds = $this->tenantContext->runWithFirmContext(
            $firmA,
            fn () => Client::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertSame([$clientA->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_update_firm_b_rows(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $clientB = Client::factory()->forFirm($firmB)->create(['display_name' => 'Original Name']);

        $this->forceRls();

        $this->tenantContext->runWithFirmContext($firmA, function () use ($clientB) {
            DB::table('clients')->where('id', $clientB->id)->update(['display_name' => 'Hacked By Firm A']);
        });

        // A plain unscoped read after context is cleared returns
        // nothing at all (FORCE RLS blocks every unscoped read) — the
        // only way to verify the row's REAL state is to read it back
        // through firm B's own context.
        $reReadAsFirmB = $this->tenantContext->runWithFirmContext(
            $firmB,
            fn () => Client::withoutGlobalScopes()->find($clientB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame('Original Name', $reReadAsFirmB->display_name);
    }

    public function test_firm_a_context_cannot_delete_firm_b_rows(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $clientB = Client::factory()->forFirm($firmB)->create();

        $this->forceRls();

        $this->tenantContext->runWithFirmContext($firmA, function () use ($clientB) {
            DB::table('clients')->where('id', $clientB->id)->delete();
        });

        $reReadAsFirmB = $this->tenantContext->runWithFirmContext(
            $firmB,
            fn () => Client::withoutGlobalScopes()->find($clientB->id),
        );

        $this->assertNotNull($reReadAsFirmB, 'Firm A context must not be able to delete Firm B rows.');
    }

    public function test_firm_a_context_cannot_insert_a_row_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $this->forceRls();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->tenantContext->runWithFirmContext($firmA, function () use ($firmB) {
            DB::table('clients')->insert([
                'uuid' => (string) \Illuminate\Support\Str::uuid7(),
                'firm_id' => $firmB->id,
                'display_name' => 'Cross-Firm Insert Attempt',
                'preferred_language' => 'en',
                'portal_status' => 'not_invited',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }
}
