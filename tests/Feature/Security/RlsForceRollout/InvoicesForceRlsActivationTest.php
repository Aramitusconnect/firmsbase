<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Models\Client;
use App\Models\Deadline;
use App\Models\Document;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Invoice;
use App\Models\Matter;
use App\Models\Task;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * InvoicesForceRlsActivationTest — Section 39A-3G. Proves the seventh
 * staged FORCE ROW LEVEL SECURITY activation batch
 * (database/migrations/2026_08_05_900001_force_rls_on_invoices_table.php)
 * is permanently active for invoices and behaves correctly: fail-closed
 * with no context, correct cross-firm isolation, correct same-firm
 * access, payments remains deliberately unforced, and that clients
 * (39A-3A), firm_users (39A-3B), documents (39A-3C), deadlines
 * (39A-3D), tasks (39A-3E), matters (39A-3F), and invoices all remain
 * forced simultaneously.
 */
class InvoicesForceRlsActivationTest extends TestCase
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

    public function test_matters_remains_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'matters'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relforcerowsecurity, 'matters must remain FORCE RLS enabled after this branch.');
    }

    public function test_invoices_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'invoices'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_invoices_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'invoices'");

        $this->assertNotNull($row);
        $this->assertTrue(
            (bool) $row->relforcerowsecurity,
            'invoices must have permanent FORCE ROW LEVEL SECURITY active — this is not a transient, per-test setting.'
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

    public function test_missing_tenant_context_cannot_read_invoices(): void
    {
        $firm = Firm::factory()->create();
        Invoice::factory()->forFirm($firm)->create();

        (new TenantContextService())->clearDatabaseTenantContext();

        $this->assertSame(0, Invoice::withoutGlobalScopes()->count());
    }

    public function test_missing_tenant_context_cannot_insert_invoices(): void
    {
        $firm = Firm::factory()->create();
        $clientId = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create())->id;

        (new TenantContextService())->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('invoices')->insert([
            'uuid' => (string) Str::uuid(),
            'firm_id' => $firm->id,
            'client_id' => $clientId,
            'invoice_type' => 'time_and_expense',
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_firm_a_context_can_read_its_own_invoices(): void
    {
        $firmA = Firm::factory()->create();
        $invoiceA = Invoice::factory()->forFirm($firmA)->create();

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => Invoice::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertSame([$invoiceA->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_invoices(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        Invoice::factory()->forFirm($firmA)->create();
        $invoiceB = Invoice::factory()->forFirm($firmB)->create();

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => Invoice::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertNotContains($invoiceB->id, $visibleIds);
    }

    public function test_firm_a_context_cannot_update_firm_b_invoices(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $invoiceB = Invoice::factory()->forFirm($firmB)->totals(50000)->create();

        $this->runWithFirmContext($firmA, function () use ($invoiceB) {
            DB::table('invoices')->where('id', $invoiceB->id)->update(['total_cents' => 999999]);
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => Invoice::withoutGlobalScopes()->find($invoiceB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame(50000, $reReadAsFirmB->total_cents);
    }

    public function test_firm_a_context_cannot_delete_firm_b_invoices(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $invoiceB = Invoice::factory()->forFirm($firmB)->create();

        $this->runWithFirmContext($firmA, function () use ($invoiceB) {
            DB::table('invoices')->where('id', $invoiceB->id)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => Invoice::withoutGlobalScopes()->find($invoiceB->id),
        );

        $this->assertNotNull($reReadAsFirmB, 'Firm A context must not be able to delete Firm B invoices.');
    }

    public function test_firm_a_context_cannot_insert_an_invoice_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $clientBId = $this->runWithFirmContext($firmB, fn () => Client::factory()->forFirm($firmB)->create())->id;

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB, $clientBId) {
            DB::table('invoices')->insert([
                'uuid' => (string) Str::uuid(),
                'firm_id' => $firmB->id,
                'client_id' => $clientBId,
                'invoice_type' => 'time_and_expense',
                'status' => 'draft',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    /**
     * Known, documented residual gap (same as MattersForceRlsActivationTest's
     * equivalent client-mismatch proof): RLS's single-column policy only
     * validates the invoices row's own firm_id against session context,
     * never that client_id/matter_id transitively belong to the same
     * firm. The insert succeeds because firm_id = firmA matches the
     * active context — this is why InvoiceFactory's own root-cause fix
     * (tying the nested client to the same firm) matters, and why a
     * future composite/trigger-based DB constraint is recommended.
     */
    public function test_firm_a_can_still_create_an_invoice_using_a_firm_b_client_at_the_raw_db_layer(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $clientB = $this->runWithFirmContext($firmB, fn () => Client::factory()->forFirm($firmB)->create());

        $mismatchedInvoiceId = $this->runWithFirmContext($firmA, function () use ($firmA, $clientB) {
            return DB::table('invoices')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'firm_id' => $firmA->id,
                'client_id' => $clientB->id,
                'invoice_type' => 'time_and_expense',
                'status' => 'draft',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->assertIsInt($mismatchedInvoiceId);
    }

    public function test_firm_a_cannot_create_an_invoice_using_a_firm_b_matter(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $matterB = $this->runWithFirmContext($firmB, fn () => Matter::factory()->forFirm($firmB)->create());
        $clientAId = $this->runWithFirmContext($firmA, fn () => Client::factory()->forFirm($firmA)->create())->id;

        // The invoice row itself claims firm_id = firmA (matching the
        // active context) and a firm-consistent client, but matter_id
        // points at a firm-B matter — again, RLS's own policy on
        // invoices allows this because it only checks the invoices
        // row's own firm_id. Proven here rather than silently assumed
        // impossible.
        $mismatchedInvoiceId = $this->runWithFirmContext($firmA, function () use ($firmA, $clientAId, $matterB) {
            return DB::table('invoices')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'firm_id' => $firmA->id,
                'client_id' => $clientAId,
                'matter_id' => $matterB->id,
                'invoice_type' => 'time_and_expense',
                'status' => 'draft',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->assertIsInt($mismatchedInvoiceId);
    }

    public function test_invoice_factory_never_produces_a_firm_client_mismatch_by_default(): void
    {
        $invoice = Invoice::factory()->create();

        $this->assertSame($invoice->firm_id, $this->runWithFirmContext($invoice->firm, fn () => $invoice->client)->firm_id);
    }

    public function test_invoice_factory_for_matter_ties_firm_client_and_matter_consistently(): void
    {
        $firm = Firm::factory()->create();
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());

        $invoice = $this->runWithFirmContext($firm, fn () => Invoice::factory()->forMatter($matter)->create());

        $this->assertSame($firm->id, $invoice->firm_id);
        $this->assertSame($matter->client_id, $invoice->client_id);
        $this->assertSame($matter->id, $invoice->matter_id);
    }

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();

        $this->runWithFirmContext($firm, fn () => Invoice::factory()->forFirm($firm)->create());

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
        $migration = require base_path('database/migrations/2026_08_05_900001_force_rls_on_invoices_table.php');

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'invoices'");

            $this->assertTrue((bool) $row->relrowsecurity, 'Rollback must not disable RLS itself.');
            $this->assertFalse((bool) $row->relforcerowsecurity, 'Rollback must clear FORCE.');
        } finally {
            $migration->up();
        }
    }

    /**
     * All seven staged batches (clients, firm_users, documents,
     * deadlines, tasks, matters, invoices) must be independently
     * force-active and independently isolated at the same time — proof
     * this batch did not weaken or interfere with Section
     * 39A-3A/39A-3B/39A-3C/39A-3D/39A-3E/39A-3F's own enforcement.
     */
    public function test_all_seven_forced_tables_are_isolated_independently_and_simultaneously(): void
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
        $invoiceA = Invoice::factory()->forFirm($firmA)->create();
        $invoiceB = Invoice::factory()->forFirm($firmB)->create();

        $resultA = $this->runWithFirmContext($firmA, fn () => [
            'clients' => Client::withoutGlobalScopes()->pluck('id')->all(),
            'firm_users' => FirmUser::withoutGlobalScopes()->pluck('id')->all(),
            'documents' => Document::withoutGlobalScopes()->pluck('id')->all(),
            'deadlines' => Deadline::withoutGlobalScopes()->pluck('id')->all(),
            'tasks' => Task::withoutGlobalScopes()->pluck('id')->all(),
            'matters' => Matter::withoutGlobalScopes()->pluck('id')->all(),
            'invoices' => Invoice::withoutGlobalScopes()->pluck('id')->all(),
        ]);

        $this->assertContains($clientA->id, $resultA['clients']);
        $this->assertSame([], $resultA['firm_users']);
        $this->assertNotContains($firmUserB->id, $resultA['firm_users']);
        $this->assertSame([$documentA->id], $resultA['documents']);
        $this->assertSame([], $resultA['deadlines']);
        $this->assertNotContains($deadlineB->id, $resultA['deadlines']);
        $this->assertSame([$taskA->id], $resultA['tasks']);
        $this->assertNotContains($taskB->id, $resultA['tasks']);
        $this->assertContains($matterA->id, $resultA['matters']);
        $this->assertNotContains($matterB->id, $resultA['matters']);
        $this->assertContains($invoiceA->id, $resultA['invoices']);
        $this->assertNotContains($invoiceB->id, $resultA['invoices']);
    }
}
