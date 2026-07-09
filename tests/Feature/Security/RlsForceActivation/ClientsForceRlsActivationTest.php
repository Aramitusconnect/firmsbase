<?php

namespace Tests\Feature\Security\RlsForceActivation;

use App\Models\Client;
use App\Models\Firm;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ClientsForceRlsActivationTest — Section 39A-3A. Proves the staged
 * FORCE ROW LEVEL SECURITY activation for `clients`
 * (database/migrations/2026_07_30_900001_force_rls_on_clients_table.php)
 * is permanently active (not the transient, per-test FORCE used by
 * Section 39A/39A-2's own proof tests) and behaves correctly:
 * fail-closed with no context, correct cross-firm isolation, correct
 * same-firm access — and that rollback support genuinely restores the
 * Section 39A baseline (RLS enabled, policy present, NOT forced).
 */
class ClientsForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    public function test_clients_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'clients'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_clients_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'clients'");

        $this->assertNotNull($row);
        $this->assertTrue(
            (bool) $row->relforcerowsecurity,
            'clients must have permanent FORCE ROW LEVEL SECURITY active — this is not a transient, per-test setting.'
        );
    }

    public function test_missing_tenant_context_cannot_read_clients(): void
    {
        $firm = Firm::factory()->create();
        Client::factory()->forFirm($firm)->create();

        (new TenantContextService())->clearDatabaseTenantContext();

        $this->assertSame(0, Client::withoutGlobalScopes()->count());
    }

    public function test_missing_tenant_context_cannot_write_clients(): void
    {
        $firm = Firm::factory()->create();

        (new TenantContextService())->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('clients')->insert([
            'uuid' => (string) Str::uuid7(),
            'firm_id' => $firm->id,
            'display_name' => 'No Context Insert',
            'preferred_language' => 'en',
            'portal_status' => 'not_invited',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_firm_a_context_can_read_its_own_clients(): void
    {
        $firmA = Firm::factory()->create();
        $clientA = Client::factory()->forFirm($firmA)->create();

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => Client::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertSame([$clientA->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_clients(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        Client::factory()->forFirm($firmA)->create();
        $clientB = Client::factory()->forFirm($firmB)->create();

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => Client::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertNotContains($clientB->id, $visibleIds);
    }

    public function test_firm_a_context_cannot_update_firm_b_clients(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $clientB = Client::factory()->forFirm($firmB)->create(['display_name' => 'Original Name']);

        $this->runWithFirmContext($firmA, function () use ($clientB) {
            DB::table('clients')->where('id', $clientB->id)->update(['display_name' => 'Hacked By Firm A']);
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => Client::withoutGlobalScopes()->find($clientB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame('Original Name', $reReadAsFirmB->display_name);
    }

    public function test_firm_a_context_cannot_delete_firm_b_clients(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $clientB = Client::factory()->forFirm($firmB)->create();

        $this->runWithFirmContext($firmA, function () use ($clientB) {
            DB::table('clients')->where('id', $clientB->id)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => Client::withoutGlobalScopes()->find($clientB->id),
        );

        $this->assertNotNull($reReadAsFirmB, 'Firm A context must not be able to delete Firm B clients.');
    }

    public function test_firm_a_context_cannot_insert_a_client_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB) {
            DB::table('clients')->insert([
                'uuid' => (string) Str::uuid7(),
                'firm_id' => $firmB->id,
                'display_name' => 'Cross-Firm Insert Attempt',
                'preferred_language' => 'en',
                'portal_status' => 'not_invited',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    /**
     * Rollback support: the migration's down() must genuinely restore
     * the Section 39A baseline — RLS still enabled, policy still
     * present, but NOT forced — never drop the policy or disable RLS
     * itself (those belong to the Phase 2 preparation migration).
     */
    public function test_migration_down_restores_the_not_forced_baseline(): void
    {
        $migration = require base_path('database/migrations/2026_07_30_900001_force_rls_on_clients_table.php');

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'clients'");

            $this->assertTrue((bool) $row->relrowsecurity, 'Rollback must not disable RLS itself.');
            $this->assertFalse((bool) $row->relforcerowsecurity, 'Rollback must clear FORCE.');
        } finally {
            // Restore FORCE so this test does not leak a change to the
            // rest of this run's shared connection beyond its own
            // (rolled-back) RefreshDatabase transaction.
            $migration->up();
        }
    }
}
