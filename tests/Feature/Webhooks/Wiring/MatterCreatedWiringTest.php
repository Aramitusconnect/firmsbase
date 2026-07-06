<?php

namespace Tests\Feature\Webhooks\Wiring;

use App\Enums\ImportEntityType;
use App\Enums\ImportRowStatus;
use App\Enums\ImportSourceType;
use App\Enums\WebhookEventType;
use App\Models\Client;
use App\Models\Matter;
use App\Models\MatterType;
use App\Models\PracticeArea;
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
 * matter.created is wired ONLY through ImportApplyService's Matter
 * branch (Phase 14b decision C). ProductionPilotWorkflowService is
 * deliberately NOT wired — it is referenced only by its own test
 * (ProductionPilotWorkflowServiceTest) and is not a real production
 * matter-creation owner. No new matter-intake service is built here.
 */
class MatterCreatedWiringTest extends TestCase
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

    public function test_matter_created_fires_exactly_once_on_successful_import_apply(): void
    {
        $firm = $this->makeWebhookEntitledFirm();
        $client = Client::factory()->forFirm($firm)->create();
        $practiceArea = PracticeArea::factory()->create();
        $matterType = MatterType::factory()->create();

        $batch = $this->batchService->create($firm, ImportEntityType::Matter, ImportSourceType::CsvUpload);
        $this->batchService->stageRows($batch, [[
            'client_id' => $client->id,
            'primary_practice_area_id' => $practiceArea->id,
            'matter_type_id' => $matterType->id,
        ]]);
        $batch->rows()->update(['status' => ImportRowStatus::Validated->value]);
        $this->service->confirmBatch($batch->fresh());
        $this->service->apply($batch->fresh());

        $this->assertDatabaseCount('webhook_events', 1);
        $this->assertDatabaseHas('webhook_events', ['event_type' => WebhookEventType::MatterCreated->value]);

        $matter = Matter::query()->where('client_id', $client->id)->firstOrFail();
        $this->assertDatabaseHas('webhook_events', ['subject_type' => Matter::class, 'subject_id' => $matter->id]);
    }

    public function test_matter_created_does_not_fire_when_the_import_row_fails(): void
    {
        $firm = $this->makeWebhookEntitledFirm();

        $batch = $this->batchService->create($firm, ImportEntityType::Matter, ImportSourceType::CsvUpload);
        $this->batchService->stageRows($batch, [[]]); // missing every required field -> throws
        $batch->rows()->update(['status' => ImportRowStatus::Validated->value]);
        $this->service->confirmBatch($batch->fresh());
        $this->service->apply($batch->fresh());

        $this->assertDatabaseCount('webhook_events', 0);
    }

    public function test_recorder_exception_does_not_break_matter_import_apply(): void
    {
        $this->mock(WebhookEventRecorderService::class, function ($mock) {
            $mock->shouldReceive('record')->andThrow(new \RuntimeException('simulated recorder failure'));
        });

        $firm = $this->makeWebhookEntitledFirm();
        $client = Client::factory()->forFirm($firm)->create();
        $practiceArea = PracticeArea::factory()->create();
        $matterType = MatterType::factory()->create();

        $batch = $this->batchService->create($firm, ImportEntityType::Matter, ImportSourceType::CsvUpload);
        $this->batchService->stageRows($batch, [[
            'client_id' => $client->id,
            'primary_practice_area_id' => $practiceArea->id,
            'matter_type_id' => $matterType->id,
        ]]);
        $batch->rows()->update(['status' => ImportRowStatus::Validated->value]);
        $this->service->confirmBatch($batch->fresh());
        $this->service->apply($batch->fresh());

        $this->assertDatabaseHas('matters', ['client_id' => $client->id]);
        $this->assertDatabaseHas('import_rows', ['status' => ImportRowStatus::Applied->value]);
    }
}
