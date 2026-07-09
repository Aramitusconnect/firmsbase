<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Models\Client;
use App\Models\Deadline;
use App\Models\Document;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * DeadlinesForceRlsActivationTest — Section 39A-3D. Proves the fourth
 * staged FORCE ROW LEVEL SECURITY activation batch
 * (database/migrations/2026_08_02_900001_force_rls_on_deadlines_table.php)
 * is permanently active for deadlines and behaves correctly:
 * fail-closed with no context, correct cross-firm isolation, correct
 * same-firm access, and that clients (39A-3A), firm_users (39A-3B),
 * documents (39A-3C), and deadlines all remain forced simultaneously.
 */
class DeadlinesForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    public function test_clients_remains_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'clients'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relforcerowsecurity, 'clients must remain FORCE RLS enabled after this branch.');
    }

    public function test_firm_users_remains_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'firm_users'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relforcerowsecurity, 'firm_users must remain FORCE RLS enabled after this branch.');
    }

    public function test_documents_remains_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'documents'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relforcerowsecurity, 'documents must remain FORCE RLS enabled after this branch.');
    }

    public function test_deadlines_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'deadlines'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_deadlines_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'deadlines'");

        $this->assertNotNull($row);
        $this->assertTrue(
            (bool) $row->relforcerowsecurity,
            'deadlines must have permanent FORCE ROW LEVEL SECURITY active — this is not a transient, per-test setting.'
        );
    }

    public function test_missing_tenant_context_cannot_read_deadlines(): void
    {
        $firm = Firm::factory()->create();
        Deadline::factory()->create(['firm_id' => $firm->id]);

        (new TenantContextService())->clearDatabaseTenantContext();

        $this->assertSame(0, Deadline::withoutGlobalScopes()->count());
    }

    public function test_missing_tenant_context_cannot_write_deadlines(): void
    {
        $firm = Firm::factory()->create();

        (new TenantContextService())->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('deadlines')->insert([
            'firm_id' => $firm->id,
            'title' => 'No Context Insert',
            'deadline_type' => 'filing_deadline',
            'due_at' => now()->addDays(30),
            'status' => 'upcoming',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_firm_a_context_can_read_its_own_deadlines(): void
    {
        $firmA = Firm::factory()->create();
        $deadlineA = Deadline::factory()->create(['firm_id' => $firmA->id]);

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => Deadline::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertSame([$deadlineA->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_deadlines(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        Deadline::factory()->create(['firm_id' => $firmA->id]);
        $deadlineB = Deadline::factory()->create(['firm_id' => $firmB->id]);

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => Deadline::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertNotContains($deadlineB->id, $visibleIds);
    }

    public function test_firm_a_context_cannot_update_firm_b_deadlines(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $deadlineB = Deadline::factory()->create(['firm_id' => $firmB->id, 'title' => 'Original Title']);

        $this->runWithFirmContext($firmA, function () use ($deadlineB) {
            DB::table('deadlines')->where('id', $deadlineB->id)->update(['title' => 'Hacked Title']);
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => Deadline::withoutGlobalScopes()->find($deadlineB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame('Original Title', $reReadAsFirmB->title);
    }

    public function test_firm_a_context_cannot_delete_firm_b_deadlines(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $deadlineB = Deadline::factory()->create(['firm_id' => $firmB->id]);

        $this->runWithFirmContext($firmA, function () use ($deadlineB) {
            DB::table('deadlines')->where('id', $deadlineB->id)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => Deadline::withoutGlobalScopes()->find($deadlineB->id),
        );

        $this->assertNotNull($reReadAsFirmB, 'Firm A context must not be able to delete Firm B deadlines.');
    }

    public function test_firm_a_context_cannot_insert_a_deadline_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB) {
            DB::table('deadlines')->insert([
                'firm_id' => $firmB->id,
                'title' => 'Cross-Firm Insert Attempt',
                'deadline_type' => 'filing_deadline',
                'due_at' => now()->addDays(30),
                'status' => 'upcoming',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();

        $this->runWithFirmContext($firm, fn () => Deadline::factory()->create(['firm_id' => $firm->id]));

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
     * itself (those belong to the Phase 4 preparation migration).
     */
    public function test_migration_down_restores_the_not_forced_baseline(): void
    {
        $migration = require base_path('database/migrations/2026_08_02_900001_force_rls_on_deadlines_table.php');

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'deadlines'");

            $this->assertTrue((bool) $row->relrowsecurity, 'Rollback must not disable RLS itself.');
            $this->assertFalse((bool) $row->relforcerowsecurity, 'Rollback must clear FORCE.');
        } finally {
            $migration->up();
        }
    }

    /**
     * All four staged batches (clients, firm_users, documents,
     * deadlines) must be independently force-active and independently
     * isolated at the same time — proof this batch did not weaken or
     * interfere with Section 39A-3A/39A-3B/39A-3C's own enforcement.
     */
    public function test_clients_firm_users_documents_and_deadlines_are_isolated_independently_and_simultaneously(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $clientA = Client::factory()->forFirm($firmA)->create();
        $firmUserB = FirmUser::factory()->forFirm($firmB)->create();
        $documentA = Document::factory()->create(['firm_id' => $firmA->id]);
        $deadlineB = Deadline::factory()->create(['firm_id' => $firmB->id]);

        $resultA = $this->runWithFirmContext($firmA, fn () => [
            'clients' => Client::withoutGlobalScopes()->pluck('id')->all(),
            'firm_users' => FirmUser::withoutGlobalScopes()->pluck('id')->all(),
            'documents' => Document::withoutGlobalScopes()->pluck('id')->all(),
            'deadlines' => Deadline::withoutGlobalScopes()->pluck('id')->all(),
        ]);

        $this->assertSame([$clientA->id], $resultA['clients']);
        $this->assertSame([], $resultA['firm_users']);
        $this->assertNotContains($firmUserB->id, $resultA['firm_users']);
        $this->assertSame([$documentA->id], $resultA['documents']);
        $this->assertSame([], $resultA['deadlines']);
        $this->assertNotContains($deadlineB->id, $resultA['deadlines']);
    }
}
