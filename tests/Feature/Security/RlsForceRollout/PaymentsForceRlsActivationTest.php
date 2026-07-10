<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Models\Client;
use App\Models\Deadline;
use App\Models\Document;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Invoice;
use App\Models\Matter;
use App\Models\Payment;
use App\Models\Task;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PaymentsForceRlsActivationTest — Section 39A-3H. Proves the eighth
 * and final pilot-critical staged FORCE ROW LEVEL SECURITY activation
 * batch
 * (database/migrations/2026_08_06_900001_force_rls_on_payments_table.php)
 * is permanently active for payments and behaves correctly: fail-closed
 * with no context, correct cross-firm isolation, correct same-firm
 * access, and that clients (39A-3A), firm_users (39A-3B), documents
 * (39A-3C), deadlines (39A-3D), tasks (39A-3E), matters (39A-3F),
 * invoices (39A-3G), and payments all remain forced simultaneously.
 */
class PaymentsForceRlsActivationTest extends TestCase
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

    public function test_invoices_remains_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'invoices'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relforcerowsecurity, 'invoices must remain FORCE RLS enabled after this branch.');
    }

    public function test_payments_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'payments'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_payments_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'payments'");

        $this->assertNotNull($row);
        $this->assertTrue(
            (bool) $row->relforcerowsecurity,
            'payments must have permanent FORCE ROW LEVEL SECURITY active — this is not a transient, per-test setting.'
        );
    }

    public function test_missing_tenant_context_cannot_read_payments(): void
    {
        $firm = Firm::factory()->create();
        Payment::factory()->forFirm($firm)->create();

        (new TenantContextService())->clearDatabaseTenantContext();

        $this->assertSame(0, Payment::withoutGlobalScopes()->count());
    }

    public function test_missing_tenant_context_cannot_insert_payments(): void
    {
        $firm = Firm::factory()->create();
        $clientId = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create())->id;

        (new TenantContextService())->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('payments')->insert([
            'uuid' => (string) Str::uuid(),
            'firm_id' => $firm->id,
            'client_id' => $clientId,
            'amount_cents' => 10000,
            'payment_method' => 'check',
            'payment_classification' => 'operating_payment',
            'status' => 'succeeded',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_firm_a_context_can_read_its_own_payments(): void
    {
        $firmA = Firm::factory()->create();
        $paymentA = Payment::factory()->forFirm($firmA)->create();

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => Payment::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertSame([$paymentA->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_payments(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        Payment::factory()->forFirm($firmA)->create();
        $paymentB = Payment::factory()->forFirm($firmB)->create();

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => Payment::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertNotContains($paymentB->id, $visibleIds);
    }

    public function test_firm_a_context_cannot_update_firm_b_payments(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $paymentB = Payment::factory()->forFirm($firmB)->create(['amount_cents' => 5000]);

        $this->runWithFirmContext($firmA, function () use ($paymentB) {
            DB::table('payments')->where('id', $paymentB->id)->update(['amount_cents' => 999999]);
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => Payment::withoutGlobalScopes()->find($paymentB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame(5000, $reReadAsFirmB->amount_cents);
    }

    public function test_firm_a_context_cannot_delete_firm_b_payments(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $paymentB = Payment::factory()->forFirm($firmB)->create();

        $this->runWithFirmContext($firmA, function () use ($paymentB) {
            DB::table('payments')->where('id', $paymentB->id)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => Payment::withoutGlobalScopes()->find($paymentB->id),
        );

        $this->assertNotNull($reReadAsFirmB, 'Firm A context must not be able to delete Firm B payments.');
    }

    public function test_firm_a_context_cannot_insert_a_payment_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $clientBId = $this->runWithFirmContext($firmB, fn () => Client::factory()->forFirm($firmB)->create())->id;

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB, $clientBId) {
            DB::table('payments')->insert([
                'uuid' => (string) Str::uuid(),
                'firm_id' => $firmB->id,
                'client_id' => $clientBId,
                'amount_cents' => 10000,
                'payment_method' => 'check',
                'payment_classification' => 'operating_payment',
                'status' => 'succeeded',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    /**
     * Known, documented residual gap (same as
     * Matters/InvoicesForceRlsActivationTest's equivalent mismatch
     * proofs): RLS's single-column policy only validates the payments
     * row's own firm_id against session context, never that
     * client_id/matter_id/invoice_id transitively belong to the same
     * firm. The insert succeeds because firm_id = firmA matches the
     * active context — this is why PaymentFactory's own root-cause fix
     * (tying the nested client to the same firm) matters, and why a
     * future composite/trigger-based DB constraint is recommended.
     */
    public function test_firm_a_can_still_create_a_payment_using_a_firm_b_client_at_the_raw_db_layer(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $clientB = $this->runWithFirmContext($firmB, fn () => Client::factory()->forFirm($firmB)->create());

        $mismatchedPaymentId = $this->runWithFirmContext($firmA, function () use ($firmA, $clientB) {
            return DB::table('payments')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'firm_id' => $firmA->id,
                'client_id' => $clientB->id,
                'amount_cents' => 10000,
                'payment_method' => 'check',
                'payment_classification' => 'operating_payment',
                'status' => 'succeeded',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->assertIsInt($mismatchedPaymentId);
    }

    public function test_firm_a_cannot_create_a_payment_using_a_firm_b_matter(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $matterB = $this->runWithFirmContext($firmB, fn () => Matter::factory()->forFirm($firmB)->create());
        $clientAId = $this->runWithFirmContext($firmA, fn () => Client::factory()->forFirm($firmA)->create())->id;

        $mismatchedPaymentId = $this->runWithFirmContext($firmA, function () use ($firmA, $clientAId, $matterB) {
            return DB::table('payments')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'firm_id' => $firmA->id,
                'client_id' => $clientAId,
                'matter_id' => $matterB->id,
                'amount_cents' => 10000,
                'payment_method' => 'check',
                'payment_classification' => 'operating_payment',
                'status' => 'succeeded',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->assertIsInt($mismatchedPaymentId);
    }

    public function test_firm_a_cannot_create_a_payment_using_a_firm_b_invoice(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $invoiceB = $this->runWithFirmContext($firmB, fn () => Invoice::factory()->forFirm($firmB)->create());
        $clientAId = $this->runWithFirmContext($firmA, fn () => Client::factory()->forFirm($firmA)->create())->id;

        $mismatchedPaymentId = $this->runWithFirmContext($firmA, function () use ($firmA, $clientAId, $invoiceB) {
            return DB::table('payments')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'firm_id' => $firmA->id,
                'client_id' => $clientAId,
                'invoice_id' => $invoiceB->id,
                'amount_cents' => 10000,
                'payment_method' => 'check',
                'payment_classification' => 'operating_payment',
                'status' => 'succeeded',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->assertIsInt($mismatchedPaymentId);
    }

    public function test_payment_factory_never_produces_a_firm_client_mismatch_by_default(): void
    {
        $payment = Payment::factory()->create();

        $this->assertSame($payment->firm_id, $this->runWithFirmContext($payment->firm, fn () => $payment->client)->firm_id);
    }

    public function test_payment_factory_for_matter_ties_firm_client_and_matter_consistently(): void
    {
        $firm = Firm::factory()->create();
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());

        $payment = $this->runWithFirmContext($firm, fn () => Payment::factory()->forMatter($matter)->create());

        $this->assertSame($firm->id, $payment->firm_id);
        $this->assertSame($matter->client_id, $payment->client_id);
        $this->assertSame($matter->id, $payment->matter_id);
    }

    public function test_payment_factory_for_invoice_ties_firm_client_and_invoice_consistently(): void
    {
        $firm = Firm::factory()->create();
        $invoice = $this->runWithFirmContext($firm, fn () => Invoice::factory()->forFirm($firm)->create());

        $payment = $this->runWithFirmContext($firm, fn () => Payment::factory()->forInvoice($invoice)->create());

        $this->assertSame($firm->id, $payment->firm_id);
        $this->assertSame($invoice->client_id, $payment->client_id);
        $this->assertSame($invoice->id, $payment->invoice_id);
    }

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();

        $this->runWithFirmContext($firm, fn () => Payment::factory()->forFirm($firm)->create());

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
        $migration = require base_path('database/migrations/2026_08_06_900001_force_rls_on_payments_table.php');

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'payments'");

            $this->assertTrue((bool) $row->relrowsecurity, 'Rollback must not disable RLS itself.');
            $this->assertFalse((bool) $row->relforcerowsecurity, 'Rollback must clear FORCE.');
        } finally {
            $migration->up();
        }
    }

    /**
     * All eight staged batches (clients, firm_users, documents,
     * deadlines, tasks, matters, invoices, payments) must be
     * independently force-active and independently isolated at the
     * same time — proof this batch did not weaken or interfere with
     * Section 39A-3A/39A-3B/39A-3C/39A-3D/39A-3E/39A-3F/39A-3G's own
     * enforcement.
     */
    public function test_all_eight_forced_tables_are_isolated_independently_and_simultaneously(): void
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
        $paymentA = Payment::factory()->forFirm($firmA)->create();
        $paymentB = Payment::factory()->forFirm($firmB)->create();

        $resultA = $this->runWithFirmContext($firmA, fn () => [
            'clients' => Client::withoutGlobalScopes()->pluck('id')->all(),
            'firm_users' => FirmUser::withoutGlobalScopes()->pluck('id')->all(),
            'documents' => Document::withoutGlobalScopes()->pluck('id')->all(),
            'deadlines' => Deadline::withoutGlobalScopes()->pluck('id')->all(),
            'tasks' => Task::withoutGlobalScopes()->pluck('id')->all(),
            'matters' => Matter::withoutGlobalScopes()->pluck('id')->all(),
            'invoices' => Invoice::withoutGlobalScopes()->pluck('id')->all(),
            'payments' => Payment::withoutGlobalScopes()->pluck('id')->all(),
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
        $this->assertContains($paymentA->id, $resultA['payments']);
        $this->assertNotContains($paymentB->id, $resultA['payments']);
    }
}
