<?php

namespace Tests\Feature\Imports;

use App\Enums\ImportBatchStatus;
use App\Enums\ImportEntityType;
use App\Enums\ImportRowStatus;
use App\Enums\ImportSourceType;
use App\Models\Client;
use App\Models\Contact;
use App\Models\Firm;
use App\Models\Invoice;
use App\Models\Party;
use App\Models\PaymentPlan;
use App\Models\PaymentPlanInstallment;
use App\Services\DocumentUploadPolicyService;
use App\Services\ImportApplyService;
use App\Services\ImportAuditService;
use App\Services\ImportBatchService;
use App\Services\ImportDocumentSafetyService;
use App\Services\InvoiceDraftingService;
use App\Services\PaymentPlanService;
use App\Services\VirusScan\FakeVirusScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportApplyServiceTest extends TestCase
{
    use RefreshDatabase;

    private ImportApplyService $service;

    private ImportBatchService $batchService;

    protected function setUp(): void
    {
        parent::setUp();
        $auditService = new ImportAuditService;
        $this->batchService = new ImportBatchService($auditService);
        $documentSafetyService = new ImportDocumentSafetyService(new DocumentUploadPolicyService, new FakeVirusScanner);
        $this->service = new ImportApplyService(
            $documentSafetyService,
            $auditService,
            app(InvoiceDraftingService::class),
            app(PaymentPlanService::class),
        );
    }

    public function test_confirmed_rows_apply_and_create_a_production_client_record(): void
    {
        $firm = Firm::factory()->create();
        $batch = $this->batchService->create($firm, ImportEntityType::Client, ImportSourceType::CsvUpload);
        // import_batches now carries FORCE ROW LEVEL SECURITY (Wave 9).
        // Each writer service already restores database session context
        // to "none" once its own wrap exits, so a bare $batch->fresh()
        // call afterward would return null. Chain each service's own
        // already-fresh return value instead of re-fetching unwrapped.
        $batch = $this->batchService->stageRows($batch, [['display_name' => 'Applied Client']]);
        $batch->rows()->update(['status' => ImportRowStatus::Validated->value]);

        $confirmed = $this->service->confirmBatch($batch);
        $applied = $this->service->apply($confirmed);

        $this->assertSame(ImportBatchStatus::Applied, $applied->status);
        $this->runWithFirmContext($firm, fn () => $this->assertDatabaseHas('clients', ['firm_id' => $firm->id, 'display_name' => 'Applied Client']));

        $row = $batch->rows()->first();
        $this->assertSame(ImportRowStatus::Applied, $row->status);
        $this->assertNotNull($row->applied_record_id);
    }

    public function test_apply_never_runs_on_rows_that_are_not_confirmed(): void
    {
        $firm = Firm::factory()->create();
        $batch = $this->batchService->create($firm, ImportEntityType::Client, ImportSourceType::CsvUpload);
        // Chain stageRows()'s own already-fresh return value rather than
        // a bare, unwrapped $batch->fresh() re-fetch (see the comment on
        // the first test in this file for the full FORCE RLS rationale).
        $batch = $this->batchService->stageRows($batch, [['display_name' => 'Never Applied']]);
        // Deliberately left in Staged status — never validated/confirmed.

        $this->service->apply($batch);

        $this->assertDatabaseCount('clients', 0);
    }

    public function test_apply_creates_a_pending_rollback_record_for_each_applied_row(): void
    {
        $firm = Firm::factory()->create();
        $batch = $this->batchService->create($firm, ImportEntityType::Client, ImportSourceType::CsvUpload);
        $batch = $this->batchService->stageRows($batch, [['display_name' => 'Rollback Me']]);
        $batch->rows()->update(['status' => ImportRowStatus::Validated->value]);
        $confirmed = $this->service->confirmBatch($batch);

        $this->service->apply($confirmed);

        $this->assertDatabaseHas('import_rollback_records', ['import_batch_id' => $batch->id, 'status' => 'pending']);
    }

    public function test_missing_required_field_fails_the_row_instead_of_creating_a_broken_record(): void
    {
        $firm = Firm::factory()->create();
        $batch = $this->batchService->create($firm, ImportEntityType::Client, ImportSourceType::CsvUpload);
        $batch = $this->batchService->stageRows($batch, [[]]); // no display_name at all
        $batch->rows()->update(['status' => ImportRowStatus::Validated->value]);
        $confirmed = $this->service->confirmBatch($batch);

        $this->service->apply($confirmed);

        $row = $batch->rows()->first();
        $this->assertSame(ImportRowStatus::Failed, $row->status);
        $this->assertDatabaseCount('clients', 0);
    }

    /**
     * Section 39A-3L Phase B5 proof: createRecordFor()'s Contact/Party
     * create arms are each now wrapped in runWithFirmContext() (in
     * preparation for a future FORCE ROW LEVEL SECURITY activation on
     * those two tables, not yet active in this batch). No existing
     * test in this file exercised the Contact or Party entity types at
     * all before this batch — only Client. Assert the wrap did not
     * break normal apply behavior for either type.
     */
    public function test_confirmed_contact_row_applies_and_creates_a_production_contact_record(): void
    {
        $firm = Firm::factory()->create();
        $batch = $this->batchService->create($firm, ImportEntityType::Contact, ImportSourceType::CsvUpload);
        $batch = $this->batchService->stageRows($batch, [['name' => 'Applied Contact', 'email' => 'applied.contact@example.test']]);
        $batch->rows()->update(['status' => ImportRowStatus::Validated->value]);

        $confirmed = $this->service->confirmBatch($batch);
        $applied = $this->service->apply($confirmed);

        $this->assertSame(ImportBatchStatus::Applied, $applied->status);
        $this->runWithFirmContext($firm, fn () => $this->assertDatabaseHas('contacts', [
            'firm_id' => $firm->id,
            'name' => 'Applied Contact',
            'email' => 'applied.contact@example.test',
        ]));

        $row = $batch->rows()->first();
        $this->assertSame(ImportRowStatus::Applied, $row->status);
        $this->assertSame(Contact::class, $row->applied_record_type);
        $this->assertNotNull($row->applied_record_id);
    }

    public function test_confirmed_party_row_applies_and_creates_a_production_party_record(): void
    {
        $firm = Firm::factory()->create();
        $batch = $this->batchService->create($firm, ImportEntityType::Party, ImportSourceType::CsvUpload);
        $batch = $this->batchService->stageRows($batch, [['name' => 'Applied Party', 'entity_type' => 'individual']]);
        $batch->rows()->update(['status' => ImportRowStatus::Validated->value]);

        $confirmed = $this->service->confirmBatch($batch);
        $applied = $this->service->apply($confirmed);

        $this->assertSame(ImportBatchStatus::Applied, $applied->status);
        $this->runWithFirmContext($firm, fn () => $this->assertDatabaseHas('parties', [
            'firm_id' => $firm->id,
            'name' => 'Applied Party',
        ]));

        $row = $batch->rows()->first();
        $this->assertSame(ImportRowStatus::Applied, $row->status);
        $this->assertSame(Party::class, $row->applied_record_type);
        $this->assertNotNull($row->applied_record_id);
    }

    /**
     * Regression test: createRecordFor() previously wrote Invoice rows
     * via a raw Invoice::create() with no InvoiceLine rows at all,
     * leaving total_cents/subtotal_cents taken verbatim from import
     * data instead of derived the way every other invoice in the
     * system is. It must now route through
     * InvoiceDraftingService::createFlatFee(), which creates exactly
     * one FlatFee line and recomputes totals from it.
     */
    public function test_confirmed_invoice_row_applies_through_the_canonical_drafting_service_and_creates_a_line(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->create(['firm_id' => $firm->id]));

        $batch = $this->batchService->create($firm, ImportEntityType::Invoice, ImportSourceType::CsvUpload);
        $batch = $this->batchService->stageRows($batch, [[
            'client_id' => $client->id,
            'total_cents' => 50000,
            'description' => 'Imported balance',
        ]]);
        $batch->rows()->update(['status' => ImportRowStatus::Validated->value]);

        $confirmed = $this->service->confirmBatch($batch);
        $applied = $this->service->apply($confirmed);

        $this->assertSame(ImportBatchStatus::Applied, $applied->status);

        $row = $batch->rows()->first();
        $this->assertSame(ImportRowStatus::Applied, $row->status);
        $this->assertSame(Invoice::class, $row->applied_record_type);

        $this->runWithFirmContext($firm, function () use ($row) {
            $invoice = Invoice::query()->findOrFail($row->applied_record_id);
            $this->assertSame(50000, $invoice->total_cents);
            $this->assertSame(50000, $invoice->subtotal_cents);
            $this->assertSame(1, $invoice->lines()->count());
            $this->assertSame(50000, $invoice->lines()->first()->amount_cents);
        });
    }

    /**
     * Regression test: createRecordFor() previously wrote PaymentPlan
     * rows via a raw PaymentPlan::create() that stored
     * installment_count but created zero PaymentPlanInstallment rows —
     * which would silently break payment application against any
     * imported plan. It must now route through
     * PaymentPlanService::create(), which always generates real
     * installment rows summing to the plan total.
     */
    public function test_confirmed_payment_plan_row_applies_through_the_canonical_service_and_creates_installments(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->create(['firm_id' => $firm->id]));

        $batch = $this->batchService->create($firm, ImportEntityType::PaymentPlan, ImportSourceType::CsvUpload);
        $batch = $this->batchService->stageRows($batch, [[
            'client_id' => $client->id,
            'total_cents' => 10000,
            'installment_count' => 3,
        ]]);
        $batch->rows()->update(['status' => ImportRowStatus::Validated->value]);

        $confirmed = $this->service->confirmBatch($batch);
        $applied = $this->service->apply($confirmed);

        $this->assertSame(ImportBatchStatus::Applied, $applied->status);

        $row = $batch->rows()->first();
        $this->assertSame(ImportRowStatus::Applied, $row->status);
        $this->assertSame(PaymentPlan::class, $row->applied_record_type);

        $this->runWithFirmContext($firm, function () use ($row) {
            $plan = PaymentPlan::query()->findOrFail($row->applied_record_id);
            $this->assertSame(10000, $plan->total_cents);
            $this->assertSame(3, $plan->installment_count);

            $installments = PaymentPlanInstallment::query()->where('payment_plan_id', $plan->id)->get();
            $this->assertCount(3, $installments);
            $this->assertSame(10000, $installments->sum('amount_cents'));
        });
    }

    public function test_conflict_record_and_template_rows_are_skipped_not_fabricated(): void
    {
        $firm = Firm::factory()->create();
        $batch = $this->batchService->create($firm, ImportEntityType::Template, ImportSourceType::CsvUpload);
        $batch = $this->batchService->stageRows($batch, [['name' => 'Some template']]);
        $batch->rows()->update(['status' => ImportRowStatus::Validated->value]);
        $confirmed = $this->service->confirmBatch($batch);

        $this->service->apply($confirmed);

        $row = $batch->rows()->first();
        $this->assertSame(ImportRowStatus::Skipped, $row->status);
    }
}
