<?php

namespace Tests\Feature\MobilePortal;

use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\DocumentRequest;
use App\Models\Invoice;
use App\Models\Matter;
use App\Services\MobilePortalReadinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Backend-only readiness flags (approved decision) — no frontend
 * assets, no PWA manifest file, no routes/views/controllers/Filament/
 * Livewire are exercised or expected here.
 */
class MobilePortalReadinessServiceTest extends TestCase
{
    use RefreshDatabase;

    private MobilePortalReadinessService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new MobilePortalReadinessService();
    }

    public function test_camera_upload_is_supported(): void
    {
        $this->assertTrue($this->service->cameraUploadSupported());
    }

    public function test_save_and_continue_intake_is_supported(): void
    {
        $this->assertTrue($this->service->saveAndContinueIntakeSupported());
    }

    public function test_document_checklist_available_true_only_once_a_document_request_exists(): void
    {
        $matter = Matter::factory()->create();

        $this->assertFalse($this->service->documentChecklistAvailable($matter));

        // Section 39A-3L, Checkpoint 10: document_requests is now FORCE
        // RLS and documentChecklistAvailable() correctly wraps its
        // query in the matter's own firm context, so the created
        // DocumentRequest must genuinely belong to the matter's own
        // firm (via a Client that belongs to that firm) or it becomes
        // invisible to the query under test.
        $client = Client::factory()->forFirm($matter->firm)->create();
        DocumentRequest::factory()->forClient($client)->create(['matter_id' => $matter->id]);

        // The runWithFirmContext() wrap must surround the actual
        // service call under test, not merely the $matter->fresh()
        // fetch — otherwise context has already cleared by the time
        // documentChecklistAvailable() runs its own internal wrap
        // attempt (which itself doesn't need an ambient context, since
        // it establishes its own — but this call site must still prove
        // the real return value, not an artifact of a stale wrap).
        $this->assertTrue($this->runWithFirmContext($matter->firm, fn () => $this->service->documentChecklistAvailable($matter)));
    }

    public function test_payment_link_readiness_true_for_a_sent_invoice_with_an_outstanding_balance(): void
    {
        $invoice = Invoice::factory()->create([
            'status' => InvoiceStatus::Sent,
            'total_cents' => 50000,
            'amount_paid_cents' => 0,
        ]);

        $this->assertTrue($this->service->paymentLinkReadiness($invoice));
    }

    public function test_payment_link_readiness_false_once_fully_paid(): void
    {
        $invoice = Invoice::factory()->create([
            'status' => InvoiceStatus::Paid,
            'total_cents' => 50000,
            'amount_paid_cents' => 50000,
        ]);

        $this->assertFalse($this->service->paymentLinkReadiness($invoice));
    }

    public function test_payment_link_readiness_false_for_a_draft_invoice(): void
    {
        $invoice = Invoice::factory()->create([
            'status' => InvoiceStatus::Draft,
            'total_cents' => 50000,
            'amount_paid_cents' => 0,
        ]);

        $this->assertFalse($this->service->paymentLinkReadiness($invoice));
    }

    public function test_signature_flow_readiness_is_schema_readiness_only(): void
    {
        $this->assertTrue($this->service->signatureFlowReadiness());
    }

    public function test_pwa_install_supported(): void
    {
        $this->assertTrue($this->service->pwaInstallSupported());
    }
}
