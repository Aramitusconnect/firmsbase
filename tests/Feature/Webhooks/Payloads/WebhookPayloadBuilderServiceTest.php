<?php

namespace Tests\Feature\Webhooks\Payloads;

use App\Enums\WebhookEventType;
use App\Models\Client;
use App\Models\Document;
use App\Models\Firm;
use App\Models\FirmLead;
use App\Models\FormDraft;
use App\Models\Invoice;
use App\Models\Matter;
use App\Models\MatterReadinessScore;
use App\Models\Payment;
use App\Models\PaymentPlan;
use App\Models\PaymentPlanInstallment;
use App\Models\SignatureRequest;
use App\Models\Task;
use App\Services\WebhookPayloadBuilderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Correction #14 + payload-source-model-audit corrections (2026-07-05):
 * allowlists only, one test per approved event type, asserting the EXACT
 * allowed key set AND the explicit, individually-named absence of every
 * forbidden field the audit identified per model — not just a category
 * check. Named assertions are kept alongside the assertSame allowlist
 * check deliberately: assertSame already structurally guarantees
 * absence of everything outside the allowlist, but explicit per-field
 * assertions document exactly which leak each test is defending against
 * and keep failing even if a future edit relaxes the allowlist check
 * itself (e.g. to assertEqualsCanonicalizing).
 */
class WebhookPayloadBuilderServiceTest extends TestCase
{
    use RefreshDatabase;

    private WebhookPayloadBuilderService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(WebhookPayloadBuilderService::class);
    }

    private function assertNoneOfTheseKeys(array $forbidden, array $payload): void
    {
        foreach ($forbidden as $key) {
            $this->assertArrayNotHasKey($key, $payload, "Forbidden key '{$key}' leaked into webhook payload.");
        }
    }

    public function test_lead_created_payload_has_exactly_the_allowed_keys(): void
    {
        $lead = FirmLead::factory()->create();

        $payload = $this->service->build(WebhookEventType::LeadCreated, $lead);

        $this->assertSame(['firm_uuid', 'lead_uuid', 'name', 'email', 'phone', 'status'], array_keys($payload));
        $this->assertNoneOfTheseKeys(
            ['id', 'converted_client_id', 'converted_at', 'lead_source_id', 'assigned_to'],
            $payload
        );
    }

    public function test_client_created_payload_has_exactly_the_allowed_keys_and_no_invitation_token(): void
    {
        $client = Client::factory()->create(['portal_invitation_token' => 'super-secret-token']);

        $payload = $this->service->build(WebhookEventType::ClientCreated, $client);

        $this->assertSame(['firm_uuid', 'client_uuid', 'display_name', 'legal_name', 'email', 'phone', 'portal_status'], array_keys($payload));
        $this->assertNoneOfTheseKeys(
            ['id', 'portal_invitation_token', 'portal_invitation_sent_at', 'portal_invitation_accepted_at', 'communication_preferences_id', 'created_by'],
            $payload
        );
    }

    public function test_matter_created_payload_has_exactly_the_allowed_keys(): void
    {
        $matter = Matter::factory()->create();

        $payload = $this->service->build(WebhookEventType::MatterCreated, $matter);

        $this->assertSame(['firm_uuid', 'matter_uuid', 'client_uuid', 'status', 'opened_at'], array_keys($payload));
        $this->assertNoneOfTheseKeys(
            ['id', 'assigned_attorney_id', 'pinned_template_pack_version_id', 'matter_type_id', 'stage'],
            $payload
        );
    }

    public function test_document_uploaded_payload_never_includes_storage_path_disk_hash_or_any_url(): void
    {
        $document = Document::factory()->create([
            'storage_path' => 'documents/super-secret-path.pdf',
            'storage_disk' => 's3-private',
            'file_hash' => hash('sha256', 'contents'),
        ]);

        $payload = $this->service->build(WebhookEventType::DocumentUploaded, $document);

        $this->assertSame(['firm_uuid', 'document_uuid', 'matter_uuid', 'mime_type'], array_keys($payload));
        $this->assertNoneOfTheseKeys(
            ['id', 'storage_disk', 'storage_path', 'file_hash', 'original_filename', 'size_bytes', 'scan_status', 'encryption_key_id', 'url', 'signed_url', 'temporary_url', 'private_url'],
            $payload
        );

        foreach ($payload as $value) {
            if (is_string($value)) {
                $this->assertStringNotContainsString('super-secret-path', $value);
            }
        }
    }

    public function test_document_uploaded_payload_never_includes_original_filename_or_size_bytes(): void
    {
        // Separate, dedicated test per the payload-source-model audit:
        // original_filename/size_bytes are distinct forbidden columns
        // from storage_path/storage_disk/file_hash and deserve their own
        // named regression coverage rather than being folded silently
        // into the storage-path test above.
        $document = Document::factory()->create([
            'original_filename' => 'passport-scan-confidential.pdf',
            'size_bytes' => 4096,
        ]);

        $payload = $this->service->build(WebhookEventType::DocumentUploaded, $document);

        $this->assertArrayNotHasKey('original_filename', $payload);
        $this->assertArrayNotHasKey('size_bytes', $payload);

        foreach ($payload as $value) {
            if (is_string($value)) {
                $this->assertStringNotContainsString('passport-scan-confidential', $value);
            }
        }
    }

    public function test_invoice_created_payload_has_exactly_the_allowed_keys(): void
    {
        $invoice = Invoice::factory()->create();

        $payload = $this->service->build(WebhookEventType::InvoiceCreated, $invoice);

        $this->assertSame(['firm_uuid', 'invoice_uuid', 'client_uuid', 'matter_uuid', 'status', 'total_cents', 'currency'], array_keys($payload));
        $this->assertNoneOfTheseKeys(
            ['id', 'amount_paid_cents', 'subtotal_cents', 'created_by'],
            $payload
        );
    }

    public function test_payment_plan_installment_due_payload_has_exactly_the_allowed_keys(): void
    {
        $plan = PaymentPlan::factory()->create();
        $installment = PaymentPlanInstallment::factory()->forPlan($plan)->create();

        $payload = $this->service->build(WebhookEventType::PaymentPlanInstallmentDue, $installment);

        $this->assertSame(['firm_uuid', 'installment_uuid', 'amount_cents', 'due_at', 'status'], array_keys($payload));
        $this->assertNoneOfTheseKeys(
            ['id', 'paid_amount_cents', 'dunning_state', 'sequence'],
            $payload
        );
    }

    public function test_payment_recorded_payload_never_includes_idempotency_key_or_external_reference(): void
    {
        $payment = Payment::factory()->create([
            'idempotency_key' => 'secret-idempotency-key',
            'external_reference' => 'secret-external-ref',
        ]);

        $payload = $this->service->build(WebhookEventType::PaymentRecorded, $payment);

        $this->assertSame(['firm_uuid', 'payment_uuid', 'invoice_uuid', 'amount_cents', 'classification', 'status'], array_keys($payload));
        $this->assertNoneOfTheseKeys(
            ['id', 'external_reference', 'idempotency_key', 'rejection_reason', 'recorded_by', 'client_id', 'matter_id', 'payment_plan_installment_id'],
            $payload
        );
    }

    public function test_payment_recorded_never_carries_trust_iolta_classification(): void
    {
        $payment = Payment::factory()->make(['payment_classification' => \App\Enums\PaymentClassification::TrustIoltaPayment]);
        $payment->id = 999999;
        $payment->setRelation('firm', Firm::factory()->create());

        $this->expectException(\RuntimeException::class);
        $this->service->build(WebhookEventType::PaymentRecorded, $payment);
    }

    public function test_task_completed_payload_has_exactly_the_allowed_keys_and_no_description(): void
    {
        $task = Task::factory()->completed()->create(['description' => 'Private free-text notes about the client.']);

        $payload = $this->service->build(WebhookEventType::TaskCompleted, $task);

        $this->assertSame(['firm_uuid', 'matter_uuid', 'title', 'completed_at'], array_keys($payload));
        $this->assertNoneOfTheseKeys(
            ['id', 'assigned_to', 'client_id', 'created_by', 'description', 'task_uuid'],
            $payload
        );
    }

    public function test_task_completed_payload_anchors_on_matter_uuid_not_an_invented_task_uuid(): void
    {
        $task = Task::factory()->completed()->create();

        $payload = $this->service->build(WebhookEventType::TaskCompleted, $task);

        $this->assertArrayHasKey('matter_uuid', $payload);
        $this->assertArrayNotHasKey('task_uuid', $payload);
        $this->assertArrayNotHasKey('task_id', $payload);
    }

    public function test_form_approved_payload_has_exactly_the_allowed_keys(): void
    {
        $formDraft = FormDraft::factory()->create(['status' => 'approved', 'approved_at' => now()]);

        $payload = $this->service->build(WebhookEventType::FormApproved, $formDraft);

        $this->assertSame(['firm_uuid', 'form_draft_uuid', 'matter_uuid', 'status', 'approved_at'], array_keys($payload));
        $this->assertNoneOfTheseKeys(
            ['id', 'form_template_version_id', 'used_sample_mapping', 'generated_by_firm_user_id', 'reviewed_by_firm_user_id', 'reviewed_at'],
            $payload
        );
    }

    public function test_signature_completed_payload_has_exactly_the_allowed_keys_and_no_review_notes(): void
    {
        $signatureRequest = SignatureRequest::factory()->create([
            'completed_at' => now(),
            'attorney_review_notes' => 'Private attorney notes.',
        ]);

        $payload = $this->service->build(WebhookEventType::SignatureCompleted, $signatureRequest);

        $this->assertSame(['firm_uuid', 'signature_request_uuid', 'matter_uuid', 'title', 'completed_at'], array_keys($payload));
        $this->assertNoneOfTheseKeys(
            ['id', 'document_id', 'generated_document_id', 'attorney_review_notes', 'declined_reason', 'requested_by_firm_user_id'],
            $payload
        );
    }

    public function test_matter_readiness_changed_payload_has_exactly_the_allowed_keys_and_no_breakdown(): void
    {
        $matter = Matter::factory()->create();
        $score = MatterReadinessScore::factory()->forMatter($matter)->create(['breakdown_json' => ['secret_component' => true]]);

        $payload = $this->service->build(WebhookEventType::MatterReadinessChanged, $score);

        $this->assertSame(['firm_uuid', 'matter_uuid', 'status', 'satisfied_count', 'total_count'], array_keys($payload));
        $this->assertNoneOfTheseKeys(
            ['id', 'breakdown_json', 'readiness_score_uuid'],
            $payload
        );
    }

    public function test_matter_readiness_changed_payload_anchors_on_matter_uuid_not_an_invented_readiness_score_uuid(): void
    {
        $matter = Matter::factory()->create();
        $score = MatterReadinessScore::factory()->forMatter($matter)->create();

        $payload = $this->service->build(WebhookEventType::MatterReadinessChanged, $score);

        $this->assertArrayHasKey('matter_uuid', $payload);
        $this->assertArrayNotHasKey('readiness_score_uuid', $payload);
        $this->assertArrayNotHasKey('readiness_score_id', $payload);
    }

    public function test_payment_recorded_payload_never_contains_any_trust_terminology(): void
    {
        $payment = Payment::factory()->create([
            'payment_classification' => \App\Enums\PaymentClassification::OperatingPayment,
        ]);

        $payload = $this->service->build(WebhookEventType::PaymentRecorded, $payment);

        $forbiddenSubstrings = ['trust_ledger', 'trust_balance', 'trust_account', 'trust_transfer', 'trust_refund', 'trust_reconciliation'];

        foreach (array_keys($payload) as $key) {
            foreach ($forbiddenSubstrings as $substring) {
                $this->assertStringNotContainsString($substring, $key, "Payload key '{$key}' unexpectedly references trust terminology.");
            }
        }

        foreach ($payload as $value) {
            if (is_string($value)) {
                foreach ($forbiddenSubstrings as $substring) {
                    $this->assertStringNotContainsString($substring, $value);
                }
            }
        }
    }
}
