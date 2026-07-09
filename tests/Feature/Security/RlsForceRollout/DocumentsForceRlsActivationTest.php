<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Models\Client;
use App\Models\Document;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * DocumentsForceRlsActivationTest — Section 39A-3C. Proves the third
 * staged FORCE ROW LEVEL SECURITY activation batch
 * (database/migrations/2026_08_01_900001_force_rls_on_documents_table.php)
 * is permanently active for documents and behaves correctly:
 * fail-closed with no context, correct cross-firm isolation, correct
 * same-firm access, and that clients (39A-3A), firm_users (39A-3B),
 * and documents all remain forced simultaneously.
 */
class DocumentsForceRlsActivationTest extends TestCase
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

    public function test_documents_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'documents'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_documents_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'documents'");

        $this->assertNotNull($row);
        $this->assertTrue(
            (bool) $row->relforcerowsecurity,
            'documents must have permanent FORCE ROW LEVEL SECURITY active — this is not a transient, per-test setting.'
        );
    }

    public function test_missing_tenant_context_cannot_read_documents(): void
    {
        $firm = Firm::factory()->create();
        Document::factory()->create(['firm_id' => $firm->id]);

        (new TenantContextService())->clearDatabaseTenantContext();

        $this->assertSame(0, Document::withoutGlobalScopes()->count());
    }

    public function test_missing_tenant_context_cannot_write_documents(): void
    {
        $firm = Firm::factory()->create();

        (new TenantContextService())->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('documents')->insert([
            'firm_id' => $firm->id,
            'status' => 'uploaded',
            'scan_status' => 'pending',
            'storage_disk' => 'local',
            'storage_path' => 'documents/no-context.pdf',
            'original_filename' => 'no-context.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 100,
            'file_hash' => hash('sha256', 'no-context'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_firm_a_context_can_read_its_own_documents(): void
    {
        $firmA = Firm::factory()->create();
        $documentA = Document::factory()->create(['firm_id' => $firmA->id]);

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => Document::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertSame([$documentA->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_documents(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        Document::factory()->create(['firm_id' => $firmA->id]);
        $documentB = Document::factory()->create(['firm_id' => $firmB->id]);

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => Document::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertNotContains($documentB->id, $visibleIds);
    }

    public function test_firm_a_context_cannot_update_firm_b_documents(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $documentB = Document::factory()->create(['firm_id' => $firmB->id, 'original_filename' => 'original.pdf']);

        $this->runWithFirmContext($firmA, function () use ($documentB) {
            DB::table('documents')->where('id', $documentB->id)->update(['original_filename' => 'hacked.pdf']);
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => Document::withoutGlobalScopes()->find($documentB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame('original.pdf', $reReadAsFirmB->original_filename);
    }

    public function test_firm_a_context_cannot_delete_firm_b_documents(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $documentB = Document::factory()->create(['firm_id' => $firmB->id]);

        $this->runWithFirmContext($firmA, function () use ($documentB) {
            DB::table('documents')->where('id', $documentB->id)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => Document::withoutGlobalScopes()->find($documentB->id),
        );

        $this->assertNotNull($reReadAsFirmB, 'Firm A context must not be able to delete Firm B documents.');
    }

    public function test_firm_a_context_cannot_insert_a_document_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB) {
            DB::table('documents')->insert([
                'firm_id' => $firmB->id,
                'status' => 'uploaded',
                'scan_status' => 'pending',
                'storage_disk' => 'local',
                'storage_path' => 'documents/cross-firm.pdf',
                'original_filename' => 'cross-firm.pdf',
                'mime_type' => 'application/pdf',
                'size_bytes' => 100,
                'file_hash' => hash('sha256', 'cross-firm'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();

        $this->runWithFirmContext($firm, fn () => Document::factory()->create(['firm_id' => $firm->id]));

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
        $migration = require base_path('database/migrations/2026_08_01_900001_force_rls_on_documents_table.php');

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'documents'");

            $this->assertTrue((bool) $row->relrowsecurity, 'Rollback must not disable RLS itself.');
            $this->assertFalse((bool) $row->relforcerowsecurity, 'Rollback must clear FORCE.');
        } finally {
            $migration->up();
        }
    }

    /**
     * All three staged batches (clients, firm_users, documents) must
     * be independently force-active and independently isolated at the
     * same time — proof this batch did not weaken or interfere with
     * Section 39A-3A/39A-3B's own enforcement.
     */
    public function test_clients_firm_users_and_documents_are_isolated_independently_and_simultaneously(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $clientA = Client::factory()->forFirm($firmA)->create();
        $firmUserB = FirmUser::factory()->forFirm($firmB)->create();
        $documentA = Document::factory()->create(['firm_id' => $firmA->id]);

        $resultA = $this->runWithFirmContext($firmA, fn () => [
            'clients' => Client::withoutGlobalScopes()->pluck('id')->all(),
            'firm_users' => FirmUser::withoutGlobalScopes()->pluck('id')->all(),
            'documents' => Document::withoutGlobalScopes()->pluck('id')->all(),
        ]);

        $this->assertSame([$clientA->id], $resultA['clients']);
        $this->assertSame([], $resultA['firm_users']);
        $this->assertNotContains($firmUserB->id, $resultA['firm_users']);
        $this->assertSame([$documentA->id], $resultA['documents']);
    }
}
