<?php

namespace Tests\Feature\MobilePortal;

use App\Enums\InvoiceStatus;
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

        DocumentRequest::factory()->create(['matter_id' => $matter->id]);

        $this->assertTrue($this->service->documentChecklistAvailable($matter->fresh()));
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
