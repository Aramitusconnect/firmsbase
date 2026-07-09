<?php

namespace Tests\Feature\Security\RlsContextRollout;

use App\Models\Client;
use App\Models\Deadline;
use App\Models\Document;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Invoice;
use App\Models\Matter;
use App\Models\Payment;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * PriorityTenantSurfaceReadinessTest — Section 39A-2. Proves the 8
 * priority tenant-owned surfaces named in scope (FirmUser, Client,
 * Matter, Document, Invoice, Payment, Task, Deadline) are ready for a
 * future FORCE ROW LEVEL SECURITY activation: their existing factories
 * (unmodified — all already accept an explicit Firm via forFirm() or a
 * default Firm::factory(), confirmed by direct inspection) can create
 * rows and have those rows read back correctly under explicit
 * TenantContextService-backed context, and missing context is
 * detectable (zero rows visible) once FORCE is scoped on for the test.
 *
 * Deliberately does NOT rewrite any of these models' existing test
 * files — per the approved "do not rewrite hundreds of tests blindly"
 * scope, this proves the pattern works for each priority surface via
 * new, dedicated tests instead.
 *
 * FORCE ROW LEVEL SECURITY is applied per-test, AFTER fixture rows are
 * created (exactly the RlsForceEnforcementTest pattern from Section
 * 39A) — Postgres DDL is transactional and RefreshDatabase rolls back
 * every test's transaction at teardown, so no table is left permanently
 * forced.
 */
class PriorityTenantSurfaceReadinessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<int, array{0: string}>
     */
    public static function priorityTableProvider(): array
    {
        return [
            ['firm_users'],
            ['clients'],
            ['matters'],
            ['documents'],
            ['invoices'],
            ['payments'],
            ['tasks'],
            ['deadlines'],
        ];
    }

    #[DataProvider('priorityTableProvider')]
    public function test_priority_table_has_rls_prepared_and_ready_for_force_activation(string $table): void
    {
        $row = DB::selectOne('select relrowsecurity, relforcerowsecurity from pg_class where relname = ?', [$table]);

        $this->assertNotNull($row, "Table {$table} not found in pg_class.");
        $this->assertTrue((bool) $row->relrowsecurity, "RLS is not enabled on priority table {$table}.");
        $this->assertFalse((bool) $row->relforcerowsecurity, "FORCE must remain unset in this branch for {$table}.");
    }

    public function test_firm_user_can_be_created_and_read_under_explicit_context(): void
    {
        $firm = Firm::factory()->create();
        $firmUser = FirmUser::factory()->forFirm($firm)->create();

        DB::statement('ALTER TABLE firm_users FORCE ROW LEVEL SECURITY');

        $this->assertSame(0, FirmUser::withoutGlobalScopes()->count());

        $found = $this->runWithFirmContext($firm, fn () => FirmUser::withoutGlobalScopes()->find($firmUser->id));
        $this->assertNotNull($found);
    }

    public function test_client_can_be_created_and_read_under_explicit_context(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->createWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());

        DB::statement('ALTER TABLE clients FORCE ROW LEVEL SECURITY');

        $this->assertSame(0, Client::withoutGlobalScopes()->count());

        $found = $this->runWithFirmContext($firm, fn () => Client::withoutGlobalScopes()->find($client->id));
        $this->assertNotNull($found);
    }

    public function test_matter_can_be_created_and_read_under_explicit_context(): void
    {
        $firm = Firm::factory()->create();
        $matter = Matter::factory()->forFirm($firm)->create();

        DB::statement('ALTER TABLE matters FORCE ROW LEVEL SECURITY');

        $this->assertSame(0, Matter::withoutGlobalScopes()->count());

        $found = $this->runWithFirmContext($firm, fn () => Matter::withoutGlobalScopes()->find($matter->id));
        $this->assertNotNull($found);
    }

    public function test_document_can_be_created_and_read_under_explicit_context(): void
    {
        $firm = Firm::factory()->create();
        $document = Document::factory()->create(['firm_id' => $firm->id]);

        DB::statement('ALTER TABLE documents FORCE ROW LEVEL SECURITY');

        $this->assertSame(0, Document::withoutGlobalScopes()->count());

        $found = $this->runWithFirmContext($firm, fn () => Document::withoutGlobalScopes()->find($document->id));
        $this->assertNotNull($found);
    }

    public function test_invoice_can_be_created_and_read_under_explicit_context(): void
    {
        $firm = Firm::factory()->create();
        $invoice = Invoice::factory()->forFirm($firm)->create();

        DB::statement('ALTER TABLE invoices FORCE ROW LEVEL SECURITY');

        $this->assertSame(0, Invoice::withoutGlobalScopes()->count());

        $found = $this->runWithFirmContext($firm, fn () => Invoice::withoutGlobalScopes()->find($invoice->id));
        $this->assertNotNull($found);
    }

    public function test_payment_can_be_created_and_read_under_explicit_context(): void
    {
        $firm = Firm::factory()->create();
        $payment = Payment::factory()->forFirm($firm)->create();

        DB::statement('ALTER TABLE payments FORCE ROW LEVEL SECURITY');

        $this->assertSame(0, Payment::withoutGlobalScopes()->count());

        $found = $this->runWithFirmContext($firm, fn () => Payment::withoutGlobalScopes()->find($payment->id));
        $this->assertNotNull($found);
    }

    public function test_task_can_be_created_and_read_under_explicit_context(): void
    {
        $firm = Firm::factory()->create();
        $task = Task::factory()->create(['firm_id' => $firm->id]);

        DB::statement('ALTER TABLE tasks FORCE ROW LEVEL SECURITY');

        $this->assertSame(0, Task::withoutGlobalScopes()->count());

        $found = $this->runWithFirmContext($firm, fn () => Task::withoutGlobalScopes()->find($task->id));
        $this->assertNotNull($found);
    }

    public function test_deadline_can_be_created_and_read_under_explicit_context(): void
    {
        $firm = Firm::factory()->create();
        $deadline = Deadline::factory()->create(['firm_id' => $firm->id]);

        DB::statement('ALTER TABLE deadlines FORCE ROW LEVEL SECURITY');

        $this->assertSame(0, Deadline::withoutGlobalScopes()->count());

        $found = $this->runWithFirmContext($firm, fn () => Deadline::withoutGlobalScopes()->find($deadline->id));
        $this->assertNotNull($found);
    }

    public function test_missing_context_is_detectable_across_all_priority_surfaces(): void
    {
        $firm = Firm::factory()->create();
        Client::factory()->forFirm($firm)->create();
        Matter::factory()->forFirm($firm)->create();

        DB::statement('ALTER TABLE clients FORCE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE matters FORCE ROW LEVEL SECURITY');

        $this->assertNoDatabaseTenantContext();
        $this->assertSame(0, Client::withoutGlobalScopes()->count());
        $this->assertSame(0, Matter::withoutGlobalScopes()->count());
    }
}
