<?php

namespace Tests\Feature\Webhooks\Wiring;

use App\Enums\ImportEntityType;
use App\Enums\ImportRowStatus;
use App\Enums\ImportSourceType;
use App\Enums\WebhookEventType;
use App\Models\Client;
use App\Models\FirmLead;
use App\Services\ImportApplyService;
use App\Services\ImportAuditService;
use App\Services\ImportBatchService;
use App\Services\ImportDocumentSafetyService;
use App\Services\DocumentUploadPolicyService;
use App\Services\LeadConversionService;
use App\Services\TimelineEventRecorder;
use App\Services\VirusScan\FakeVirusScanner;
use App\Services\WebhookEventRecorderService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\Feature\Webhooks\Concerns\SetsUpWebhookEntitledFirm;
use Tests\TestCase;

/**
 * client.created is wired at TWO real call sites (Phase 14b decision
 * B): LeadConversionService::convert() (organic lead->client) and
 * ImportApplyService's Client branch (bulk import, no lead attached).
 * DatabaseMigrations used instead of RefreshDatabase for the same
 * DB::afterCommit()-under-test reason documented in
 * LeadCreatedWiringTest.
 */
class ClientCreatedWiringTest extends TestCase
{
    use DatabaseMigrations, SetsUpWebhookEntitledFirm;

    public function test_client_created_fires_exactly_once_on_successful_lead_conversion(): void
    {
        $firm = $this->makeWebhookEntitledFirm();
        $lead = FirmLead::factory()->forFirm($firm)->create();
        $service = new LeadConversionService(new TimelineEventRecorder());

        $client = $service->convert($lead, ['display_name' => 'Converted Client']);

        $this->assertDatabaseCount('webhook_events', 1);
        $this->assertDatabaseHas('webhook_events', [
            'event_type' => WebhookEventType::ClientCreated->value,
            'subject_type' => Client::class,
            'subject_id' => $client->id,
        ]);
    }

    public function test_client_created_does_not_fire_when_the_lead_is_already_converted(): void
    {
        $firm = $this->makeWebhookEntitledFirm();
        $lead = FirmLead::factory()->forFirm($firm)->create();
        $service = new LeadConversionService(new TimelineEventRecorder());

        $service->convert($lead, ['display_name' => 'First Conversion']);

        $this->expectException(\RuntimeException::class);
        $service->convert($lead->fresh(), ['display_name' => 'Second Conversion Attempt']);
    }

    public function test_recorder_exception_does_not_break_lead_conversion(): void
    {
        $this->mock(WebhookEventRecorderService::class, function ($mock) {
            $mock->shouldReceive('record')->andThrow(new \RuntimeException('simulated recorder failure'));
        });

        $firm = $this->makeWebhookEntitledFirm();
        $lead = FirmLead::factory()->forFirm($firm)->create();
        $service = new LeadConversionService(new TimelineEventRecorder());

        $client = $service->convert($lead, ['display_name' => 'Still Converted']);

        $this->runWithFirmContext($firm, fn () => $this->assertDatabaseHas('clients', ['id' => $client->id, 'display_name' => 'Still Converted']));
        $this->assertTrue($lead->fresh()->isConverted());
    }

    public function test_client_created_fires_exactly_once_on_successful_bulk_import(): void
    {
        $firm = $this->makeWebhookEntitledFirm();
        $auditService = new ImportAuditService();
        $batchService = new ImportBatchService($auditService);
        $documentSafetyService = new ImportDocumentSafetyService(new DocumentUploadPolicyService(), new FakeVirusScanner());
        $service = new ImportApplyService($documentSafetyService, $auditService);

        $batch = $batchService->create($firm, ImportEntityType::Client, ImportSourceType::CsvUpload);
        $batchService->stageRows($batch, [['display_name' => 'Imported Client']]);
        $batch->rows()->update(['status' => ImportRowStatus::Validated->value]);
        $service->confirmBatch($batch->fresh());
        $service->apply($batch->fresh());

        $this->assertDatabaseCount('webhook_events', 1);
        $this->assertDatabaseHas('webhook_events', ['event_type' => WebhookEventType::ClientCreated->value]);
    }

    public function test_client_created_does_not_fire_when_the_import_row_fails(): void
    {
        $firm = $this->makeWebhookEntitledFirm();
        $auditService = new ImportAuditService();
        $batchService = new ImportBatchService($auditService);
        $documentSafetyService = new ImportDocumentSafetyService(new DocumentUploadPolicyService(), new FakeVirusScanner());
        $service = new ImportApplyService($documentSafetyService, $auditService);

        $batch = $batchService->create($firm, ImportEntityType::Client, ImportSourceType::CsvUpload);
        $batchService->stageRows($batch, [[]]); // no display_name -> throws inside the transaction
        $batch->rows()->update(['status' => ImportRowStatus::Validated->value]);
        $service->confirmBatch($batch->fresh());
        $service->apply($batch->fresh());

        $this->assertDatabaseCount('webhook_events', 0);
    }
}
