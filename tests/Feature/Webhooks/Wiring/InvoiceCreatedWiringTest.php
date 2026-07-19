<?php

namespace Tests\Feature\Webhooks\Wiring;

use App\Enums\ImportEntityType;
use App\Enums\ImportRowStatus;
use App\Enums\ImportSourceType;
use App\Enums\TimeEntryStatus;
use App\Enums\WebhookEventType;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\TimeEntry;
use App\Services\EmployeeRateService;
use App\Services\ImportApplyService;
use App\Services\ImportAuditService;
use App\Services\ImportBatchService;
use App\Services\ImportDocumentSafetyService;
use App\Services\DocumentUploadPolicyService;
use App\Services\InvoiceDraftingService;
use App\Services\TimeEntryApprovalService;
use App\Services\TimelineEventRecorder;
use App\Services\VirusScan\FakeVirusScanner;
use App\Services\WebhookEventRecorderService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\Feature\Webhooks\Concerns\SetsUpWebhookEntitledFirm;
use Tests\TestCase;

/**
 * invoice.created is wired at THREE real call sites (Phase 14b decision
 * E): InvoiceDraftingService::draftFromTimeEntries(), ::createFlatFee(),
 * and ImportApplyService's Invoice branch (bulk import).
 * PlatformInvoiceService creates a different model (PlatformInvoice)
 * and is correctly out of scope.
 */
class InvoiceCreatedWiringTest extends TestCase
{
    use DatabaseMigrations, SetsUpWebhookEntitledFirm;

    public function test_invoice_created_fires_exactly_once_from_create_flat_fee(): void
    {
        $firm = $this->makeWebhookEntitledFirm();
        $client = Client::factory()->forFirm($firm)->create();
        $service = new InvoiceDraftingService(new TimeEntryApprovalService(new EmployeeRateService()), new TimelineEventRecorder());

        $invoice = $service->createFlatFee($firm, $client, 'Flat fee for filing', 50000);

        $this->assertDatabaseCount('webhook_events', 1);
        $this->assertDatabaseHas('webhook_events', [
            'event_type' => WebhookEventType::InvoiceCreated->value,
            'subject_type' => Invoice::class,
            'subject_id' => $invoice->id,
        ]);
    }

    public function test_invoice_created_fires_exactly_once_from_draft_from_time_entries(): void
    {
        $firm = $this->makeWebhookEntitledFirm();
        $client = Client::factory()->forFirm($firm)->create();
        $entry = TimeEntry::factory()->forFirm($firm)->create([
            'client_id' => $client->id,
            'status' => TimeEntryStatus::Approved,
            'is_billable' => true,
            'seconds' => 3600,
            'billing_rate_cents_snapshot' => 20000,
        ]);
        $service = new InvoiceDraftingService(new TimeEntryApprovalService(new EmployeeRateService()), new TimelineEventRecorder());

        $invoice = $service->draftFromTimeEntries($firm, $client, [$entry]);

        $this->assertDatabaseCount('webhook_events', 1);
        $this->assertDatabaseHas('webhook_events', [
            'event_type' => WebhookEventType::InvoiceCreated->value,
            'subject_type' => Invoice::class,
            'subject_id' => $invoice->id,
        ]);
    }

    public function test_invoice_created_does_not_fire_when_draft_from_time_entries_throws(): void
    {
        $firm = $this->makeWebhookEntitledFirm();
        $client = Client::factory()->forFirm($firm)->create();
        $entry = TimeEntry::factory()->forFirm($firm)->create(['client_id' => $client->id]); // still draft, not approved
        $service = new InvoiceDraftingService(new TimeEntryApprovalService(new EmployeeRateService()), new TimelineEventRecorder());

        try {
            $service->draftFromTimeEntries($firm, $client, [$entry]);
            $this->fail('Expected a RuntimeException for a non-approved time entry.');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertDatabaseCount('webhook_events', 0);
    }

    public function test_recorder_exception_does_not_break_invoice_drafting(): void
    {
        $this->mock(WebhookEventRecorderService::class, function ($mock) {
            $mock->shouldReceive('record')->andThrow(new \RuntimeException('simulated recorder failure'));
        });

        $firm = $this->makeWebhookEntitledFirm();
        $client = Client::factory()->forFirm($firm)->create();
        $service = new InvoiceDraftingService(new TimeEntryApprovalService(new EmployeeRateService()), new TimelineEventRecorder());

        $invoice = $service->createFlatFee($firm, $client, 'Still drafted', 10000);

        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'total_cents' => 10000]);
    }

    public function test_invoice_created_fires_exactly_once_from_bulk_import(): void
    {
        $firm = $this->makeWebhookEntitledFirm();
        $client = Client::factory()->forFirm($firm)->create();
        $auditService = new ImportAuditService();
        $batchService = new ImportBatchService($auditService);
        $documentSafetyService = new ImportDocumentSafetyService(new DocumentUploadPolicyService(), new FakeVirusScanner());
        $service = new ImportApplyService($documentSafetyService, $auditService);

        $batch = $batchService->create($firm, ImportEntityType::Invoice, ImportSourceType::CsvUpload);
        $batchService->stageRows($batch, [['client_id' => $client->id, 'total_cents' => 15000]]);
        $batch->rows()->update(['status' => ImportRowStatus::Validated->value]);
        $confirmed = $service->confirmBatch($batch);
        $service->apply($confirmed);

        $this->assertDatabaseCount('webhook_events', 1);
        $this->assertDatabaseHas('webhook_events', ['event_type' => WebhookEventType::InvoiceCreated->value]);
    }
}
