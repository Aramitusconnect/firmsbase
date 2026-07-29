<?php

namespace Tests\Feature\Webhooks\Wiring;

use App\Enums\ImportEntityType;
use App\Enums\ImportRowStatus;
use App\Enums\ImportSourceType;
use App\Enums\WebhookEventType;
use App\Models\Firm;
use App\Models\FirmLead;
use App\Models\WebhookEvent;
use App\Services\ImportApplyService;
use App\Services\ImportAuditService;
use App\Services\ImportBatchService;
use App\Services\ImportDocumentSafetyService;
use App\Services\DocumentUploadPolicyService;
use App\Services\VirusScan\FakeVirusScanner;
use App\Services\WebhookEventRecorderService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\Feature\Webhooks\Concerns\SetsUpWebhookEntitledFirm;
use Tests\TestCase;

/**
 * lead.created is wired ONLY through ImportApplyService's FirmLead
 * branch (Phase 14b decision A) — no organic lead-intake service
 * exists.
 *
 * Uses DatabaseMigrations rather than the project's usual
 * RefreshDatabase: RefreshDatabase wraps the entire test body in one
 * outer transaction that is rolled back (never committed) at
 * teardown. DB::afterCommit() callbacks are deferred until the
 * connection's transaction level returns to 0 — under RefreshDatabase
 * that level never reaches 0 during the test, so the callback would
 * never run and this test would wrongly appear to fail. DatabaseMigrations
 * runs real migrations with no enclosing transaction, so afterCommit
 * callbacks fire exactly as they would in production (Phase 14b rule 13).
 */
class LeadCreatedWiringTest extends TestCase
{
    use DatabaseMigrations, SetsUpWebhookEntitledFirm;

    private ImportApplyService $service;
    private ImportBatchService $batchService;

    protected function setUp(): void
    {
        parent::setUp();
        $auditService = new ImportAuditService();
        $this->batchService = new ImportBatchService($auditService);
        $documentSafetyService = new ImportDocumentSafetyService(new DocumentUploadPolicyService(), new FakeVirusScanner());
        $this->service = new ImportApplyService($documentSafetyService, $auditService);
    }

    private function applyOneLeadRow(array $rowData): Firm
    {
        $firm = $this->makeWebhookEntitledFirm();
        $batch = $this->batchService->create($firm, ImportEntityType::FirmLead, ImportSourceType::CsvUpload);
        $this->batchService->stageRows($batch, [$rowData]);
        $batch->rows()->update(['status' => ImportRowStatus::Validated->value]);
        $this->service->confirmBatch($this->runWithFirmContext($firm, fn () => $batch->fresh()));
        $this->service->apply($this->runWithFirmContext($firm, fn () => $batch->fresh()));

        return $firm;
    }

    public function test_lead_created_fires_exactly_once_on_successful_import_apply(): void
    {
        $firm = $this->applyOneLeadRow(['name' => 'Imported Lead', 'email' => 'lead@example.com']);

        $this->assertDatabaseCount('webhook_events', 1);
        $this->assertDatabaseHas('webhook_events', ['event_type' => WebhookEventType::LeadCreated->value]);

        // firm_leads has permanent FORCE ROW LEVEL SECURITY (Section
        // 39A-3J) — ImportApplyService::createRecordFor()'s FirmLead
        // branch clears its own tenant context in a finally block
        // before returning, so this post-call read needs explicit
        // tenant context re-established.
        $lead = $this->runWithFirmContext($firm, fn () => FirmLead::query()->where('name', 'Imported Lead')->firstOrFail());
        $event = WebhookEvent::query()->where('event_type', WebhookEventType::LeadCreated->value)->firstOrFail();
        $this->assertSame($lead->id, $event->subject_id);
        $this->assertSame(FirmLead::class, $event->subject_type);
    }

    public function test_lead_created_does_not_fire_when_the_row_fails_to_apply(): void
    {
        // No 'name' provided -> createRecordFor() throws inside the
        // transaction, the whole row (including any webhook event
        // insert) rolls back together.
        $this->applyOneLeadRow([]);

        $this->assertDatabaseCount('webhook_events', 0);
    }

    public function test_recorder_exception_does_not_break_the_import_apply_workflow(): void
    {
        $this->mock(WebhookEventRecorderService::class, function ($mock) {
            $mock->shouldReceive('record')->andThrow(new \RuntimeException('simulated recorder failure'));
        });

        $firm = $this->applyOneLeadRow(['name' => 'Still Applied Lead']);

        // The business outcome (a real, Applied FirmLead row) must be
        // unaffected even though the mocked recorder throws.
        // firm_leads has permanent FORCE ROW LEVEL SECURITY (Section
        // 39A-3J) — ImportApplyService::createRecordFor()'s FirmLead
        // branch clears its own tenant context in a finally block
        // before returning, so this post-call read needs explicit
        // tenant context re-established.
        $this->runWithFirmContext($firm, fn () => $this->assertDatabaseHas('firm_leads', ['name' => 'Still Applied Lead']));
        $this->assertDatabaseHas('import_rows', ['status' => ImportRowStatus::Applied->value]);
    }
}
