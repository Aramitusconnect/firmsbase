<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Models\Client;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * FirmUsersForceRlsActivationTest — Section 39A-3B. Proves the second
 * staged FORCE ROW LEVEL SECURITY activation batch
 * (database/migrations/2026_07_31_900001_force_rls_on_firm_users_table.php)
 * is permanently active for firm_users and behaves correctly:
 * fail-closed with no context, correct cross-firm isolation, correct
 * same-firm access, and that both clients (Section 39A-3A) and
 * firm_users remain forced simultaneously.
 */
class FirmUsersForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    public function test_clients_remains_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'clients'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relforcerowsecurity, 'clients must remain FORCE RLS enabled after this branch.');
    }

    public function test_firm_users_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'firm_users'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_firm_users_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'firm_users'");

        $this->assertNotNull($row);
        $this->assertTrue(
            (bool) $row->relforcerowsecurity,
            'firm_users must have permanent FORCE ROW LEVEL SECURITY active — this is not a transient, per-test setting.'
        );
    }

    public function test_missing_tenant_context_cannot_read_firm_users(): void
    {
        $firm = Firm::factory()->create();
        FirmUser::factory()->forFirm($firm)->create();

        (new TenantContextService())->clearDatabaseTenantContext();

        $this->assertSame(0, FirmUser::withoutGlobalScopes()->count());
    }

    public function test_missing_tenant_context_cannot_write_firm_users(): void
    {
        $firm = Firm::factory()->create();
        $user = \App\Models\User::factory()->create();

        (new TenantContextService())->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('firm_users')->insert([
            'uuid' => (string) Str::uuid7(),
            'user_id' => $user->id,
            'firm_id' => $firm->id,
            'role' => 'attorney',
            'status' => 'active',
            'is_primary' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_firm_a_context_can_read_its_own_firm_users(): void
    {
        $firmA = Firm::factory()->create();
        $firmUserA = FirmUser::factory()->forFirm($firmA)->create();

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => FirmUser::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertSame([$firmUserA->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_firm_users(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        FirmUser::factory()->forFirm($firmA)->create();
        $firmUserB = FirmUser::factory()->forFirm($firmB)->create();

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => FirmUser::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertNotContains($firmUserB->id, $visibleIds);
    }

    public function test_firm_a_context_cannot_update_firm_b_firm_users(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $firmUserB = FirmUser::factory()->forFirm($firmB)->create(['role' => 'attorney']);

        $this->runWithFirmContext($firmA, function () use ($firmUserB) {
            DB::table('firm_users')->where('id', $firmUserB->id)->update(['role' => 'firm_owner']);
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => FirmUser::withoutGlobalScopes()->find($firmUserB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame('attorney', $reReadAsFirmB->role->value);
    }

    public function test_firm_a_context_cannot_delete_firm_b_firm_users(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $firmUserB = FirmUser::factory()->forFirm($firmB)->create();

        $this->runWithFirmContext($firmA, function () use ($firmUserB) {
            DB::table('firm_users')->where('id', $firmUserB->id)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => FirmUser::withoutGlobalScopes()->find($firmUserB->id),
        );

        $this->assertNotNull($reReadAsFirmB, 'Firm A context must not be able to delete Firm B firm_users rows.');
    }

    public function test_firm_a_context_cannot_insert_a_firm_user_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $user = \App\Models\User::factory()->create();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB, $user) {
            DB::table('firm_users')->insert([
                'uuid' => (string) Str::uuid7(),
                'user_id' => $user->id,
                'firm_id' => $firmB->id,
                'role' => 'attorney',
                'status' => 'active',
                'is_primary' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();

        $this->runWithFirmContext($firm, fn () => FirmUser::factory()->forFirm($firm)->create());

        $this->assertNoDatabaseTenantContext();
    }

    public function test_tenant_context_clears_after_exception(): void
    {
        $firm = Firm::factory()->create();

        try {
            $this->runWithFirmContext($firm, function () {
                throw new \RuntimeException('simulated failure inside firm context');
            });
        } catch (\RuntimeException $e) {
            // expected
        }

        $this->assertNoDatabaseTenantContext();
    }

    /**
     * Rollback support: the migration's down() must genuinely restore
     * the Section 39A baseline — RLS still enabled, policy still
     * present, but NOT forced — never drop the policy or disable RLS
     * itself (those belong to the Phase 1 preparation migration).
     */
    public function test_migration_down_restores_the_not_forced_baseline(): void
    {
        $migration = require base_path('database/migrations/2026_07_31_900001_force_rls_on_firm_users_table.php');

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'firm_users'");

            $this->assertTrue((bool) $row->relrowsecurity, 'Rollback must not disable RLS itself.');
            $this->assertFalse((bool) $row->relforcerowsecurity, 'Rollback must clear FORCE.');
        } finally {
            $migration->up();
        }
    }

    /**
     * Both staged batches (clients, firm_users) must be independently
     * force-active and independently isolated at the same time — proof
     * this batch did not weaken or interfere with Section 39A-3A's own
     * clients enforcement.
     */
    public function test_clients_and_firm_users_are_isolated_independently_and_simultaneously(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $clientA = Client::factory()->forFirm($firmA)->create();
        $firmUserB = FirmUser::factory()->forFirm($firmB)->create();

        $resultA = $this->runWithFirmContext($firmA, fn () => [
            'clients' => Client::withoutGlobalScopes()->pluck('id')->all(),
            'firm_users' => FirmUser::withoutGlobalScopes()->pluck('id')->all(),
        ]);

        $this->assertSame([$clientA->id], $resultA['clients']);
        $this->assertSame([], $resultA['firm_users']);
        $this->assertNotContains($firmUserB->id, $resultA['firm_users']);
    }
}
