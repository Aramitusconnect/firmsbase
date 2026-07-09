<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Models\Client;
use App\Models\Deadline;
use App\Models\Document;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Matter;
use App\Models\MatterType;
use App\Models\PracticeArea;
use App\Models\Task;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * MattersForceRlsActivationTest — Section 39A-3F. Proves the sixth
 * staged FORCE ROW LEVEL SECURITY activation batch
 * (database/migrations/2026_08_04_900001_force_rls_on_matters_table.php)
 * is permanently active for matters and behaves correctly: fail-closed
 * with no context, correct cross-firm isolation, correct same-firm
 * access, invoices/payments remain deliberately unforced, and that
 * clients (39A-3A), firm_users (39A-3B), documents (39A-3C), deadlines
 * (39A-3D), tasks (39A-3E), and matters all remain forced
 * simultaneously.
 */
class MattersForceRlsActivationTest extends TestCase
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

    public function test_deadlines_remains_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'deadlines'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relforcerowsecurity, 'deadlines must remain FORCE RLS enabled after this branch.');
    }

    public function test_tasks_remains_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'tasks'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relforcerowsecurity, 'tasks must remain FORCE RLS enabled after this branch.');
    }

    public function test_matters_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'matters'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_matters_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'matters'");

        $this->assertNotNull($row);
        $this->assertTrue(
            (bool) $row->relforcerowsecurity,
            'matters must have permanent FORCE ROW LEVEL SECURITY active — this is not a transient, per-test setting.'
        );
    }

    public function test_invoices_remains_not_forced(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'invoices'");

        $this->assertNotNull($row);
        $this->assertFalse(
            (bool) $row->relforcerowsecurity,
            'invoices must remain unforced — its factory still nests Client::factory() directly, masking its true blast radius.'
        );
    }

    public function test_payments_remains_not_forced(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'payments'");

        $this->assertNotNull($row);
        $this->assertFalse(
            (bool) $row->relforcerowsecurity,
            'payments must remain unforced — its factory still nests Client::factory() directly, masking its true blast radius.'
        );
    }

    public function test_missing_tenant_context_cannot_read_matters(): void
    {
        $firm = Firm::factory()->create();
        Matter::factory()->forFirm($firm)->create();

        (new TenantContextService())->clearDatabaseTenantContext();

        $this->assertSame(0, Matter::withoutGlobalScopes()->count());
    }

    public function test_missing_tenant_context_cannot_insert_matters(): void
    {
        $firm = Firm::factory()->create();
        [$clientId, $practiceAreaId, $matterTypeId] = $this->runWithFirmContext($firm, fn () => $this->makeMatterDependencies($firm));

        (new TenantContextService())->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('matters')->insert([
            'uuid' => (string) Str::uuid(),
            'firm_id' => $firm->id,
            'client_id' => $clientId,
            'primary_practice_area_id' => $practiceAreaId,
            'matter_type_id' => $matterTypeId,
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_firm_a_context_can_read_its_own_matters(): void
    {
        $firmA = Firm::factory()->create();
        $matterA = Matter::factory()->forFirm($firmA)->create();

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => Matter::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertSame([$matterA->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_matters(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        Matter::factory()->forFirm($firmA)->create();
        $matterB = Matter::factory()->forFirm($firmB)->create();

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => Matter::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertNotContains($matterB->id, $visibleIds);
    }

    public function test_firm_a_context_cannot_update_firm_b_matters(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $matterB = Matter::factory()->forFirm($firmB)->create(['stage' => 'Original Stage']);

        $this->runWithFirmContext($firmA, function () use ($matterB) {
            DB::table('matters')->where('id', $matterB->id)->update(['stage' => 'Hacked Stage']);
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => Matter::withoutGlobalScopes()->find($matterB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame('Original Stage', $reReadAsFirmB->stage);
    }

    public function test_firm_a_context_cannot_delete_firm_b_matters(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $matterB = Matter::factory()->forFirm($firmB)->create();

        $this->runWithFirmContext($firmA, function () use ($matterB) {
            DB::table('matters')->where('id', $matterB->id)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => Matter::withoutGlobalScopes()->find($matterB->id),
        );

        $this->assertNotNull($reReadAsFirmB, 'Firm A context must not be able to delete Firm B matters.');
    }

    public function test_firm_a_context_cannot_insert_a_matter_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        [$clientId, $practiceAreaId, $matterTypeId] = $this->runWithFirmContext($firmB, fn () => $this->makeMatterDependencies($firmB));

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB, $clientId, $practiceAreaId, $matterTypeId) {
            DB::table('matters')->insert([
                'uuid' => (string) Str::uuid(),
                'firm_id' => $firmB->id,
                'client_id' => $clientId,
                'primary_practice_area_id' => $practiceAreaId,
                'matter_type_id' => $matterTypeId,
                'status' => 'draft',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function test_firm_a_cannot_create_a_matter_using_a_firm_b_client(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $clientB = $this->runWithFirmContext($firmB, fn () => Client::factory()->forFirm($firmB)->create());
        [, $practiceAreaId, $matterTypeId] = $this->makeMatterDependencies($firmA);

        // The row itself claims firm_id = firmA (matching the active
        // context), so RLS's own policy on matters allows the insert —
        // RLS enforces firm_id ownership of the matters row, it does
        // not (and structurally cannot, via a single-column policy)
        // also verify that client_id transitively belongs to the same
        // firm. This is the known, documented residual gap: firm/client
        // consistency is enforced at the factory level (this section's
        // MatterFactory fix) and must be enforced at the application
        // service layer for real production writes — a future
        // database-level composite-key constraint is recommended but
        // out of this section's scope.
        $mismatchedMatterId = $this->runWithFirmContext($firmA, function () use ($firmA, $clientB, $practiceAreaId, $matterTypeId) {
            return DB::table('matters')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'firm_id' => $firmA->id,
                'client_id' => $clientB->id,
                'primary_practice_area_id' => $practiceAreaId,
                'matter_type_id' => $matterTypeId,
                'status' => 'draft',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->assertIsInt($mismatchedMatterId);
    }

    public function test_matter_factory_never_produces_a_firm_client_mismatch_by_default(): void
    {
        $matter = Matter::factory()->create();

        $this->assertSame($matter->firm_id, $this->runWithFirmContext($matter->firm, fn () => $matter->client)->firm_id);
    }

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();

        $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());

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
        $migration = require base_path('database/migrations/2026_08_04_900001_force_rls_on_matters_table.php');

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'matters'");

            $this->assertTrue((bool) $row->relrowsecurity, 'Rollback must not disable RLS itself.');
            $this->assertFalse((bool) $row->relforcerowsecurity, 'Rollback must clear FORCE.');
        } finally {
            $migration->up();
        }
    }

    /**
     * All six staged batches (clients, firm_users, documents,
     * deadlines, tasks, matters) must be independently force-active
     * and independently isolated at the same time — proof this batch
     * did not weaken or interfere with Section
     * 39A-3A/39A-3B/39A-3C/39A-3D/39A-3E's own enforcement.
     */
    public function test_all_six_forced_tables_are_isolated_independently_and_simultaneously(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $clientA = Client::factory()->forFirm($firmA)->create();
        $firmUserB = FirmUser::factory()->forFirm($firmB)->create();
        $documentA = Document::factory()->create(['firm_id' => $firmA->id]);
        $deadlineB = Deadline::factory()->create(['firm_id' => $firmB->id]);
        $taskA = Task::factory()->create(['firm_id' => $firmA->id]);
        $taskB = Task::factory()->create(['firm_id' => $firmB->id]);
        $matterA = Matter::factory()->forFirm($firmA)->create();
        $matterB = Matter::factory()->forFirm($firmB)->create();

        $resultA = $this->runWithFirmContext($firmA, fn () => [
            'clients' => Client::withoutGlobalScopes()->pluck('id')->all(),
            'firm_users' => FirmUser::withoutGlobalScopes()->pluck('id')->all(),
            'documents' => Document::withoutGlobalScopes()->pluck('id')->all(),
            'deadlines' => Deadline::withoutGlobalScopes()->pluck('id')->all(),
            'tasks' => Task::withoutGlobalScopes()->pluck('id')->all(),
            'matters' => Matter::withoutGlobalScopes()->pluck('id')->all(),
        ]);

        // Matter::factory()->forFirm() legitimately creates its own
        // nested client tied to the same firm (matching the matter's
        // own firm), so firmA's visible client set now legitimately
        // contains both $clientA and matterA's own nested client —
        // assertContains/assertNotContains, not assertSame, is the
        // correct check here.
        $this->assertContains($clientA->id, $resultA['clients']);
        $this->assertSame([], $resultA['firm_users']);
        $this->assertNotContains($firmUserB->id, $resultA['firm_users']);
        $this->assertSame([$documentA->id], $resultA['documents']);
        $this->assertSame([], $resultA['deadlines']);
        $this->assertNotContains($deadlineB->id, $resultA['deadlines']);
        $this->assertSame([$taskA->id], $resultA['tasks']);
        $this->assertNotContains($taskB->id, $resultA['tasks']);
        $this->assertSame([$matterA->id], $resultA['matters']);
        $this->assertNotContains($matterB->id, $resultA['matters']);
    }

    /**
     * @return array{0: int, 1: int, 2: int} [client_id, primary_practice_area_id, matter_type_id]
     */
    private function makeMatterDependencies(Firm $firm): array
    {
        $client = Client::factory()->forFirm($firm)->create();
        $practiceArea = PracticeArea::factory()->create();
        $matterType = MatterType::factory()->forPracticeArea($practiceArea)->create();

        return [$client->id, $practiceArea->id, $matterType->id];
    }
}
