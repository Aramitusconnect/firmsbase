<?php

namespace App\Services;

use App\Enums\PaymentClassification;
use App\Enums\WebhookEventType;
use App\Models\Client;
use App\Models\Document;
use App\Models\FirmLead;
use App\Models\FormDraft;
use App\Models\Invoice;
use App\Models\Matter;
use App\Models\MatterReadinessScore;
use App\Models\Payment;
use App\Models\PaymentPlanInstallment;
use App\Models\SignatureRequest;
use App\Models\Task;

/**
 * WebhookPayloadBuilderService — the ONLY place a domain model becomes
 * webhook_events.payload_json. Every event type has its own private
 * ALLOWLIST builder method — never $model->toArray() (correction #14).
 * Forbidden categories are structurally impossible to leak because each
 * builder only ever reads the specific named fields it lists, never a
 * generic dump: document storage_path/storage_disk/file_hash/any URL,
 * encrypted secret ciphertext, OAuth tokens, AI prompt/response text,
 * trust ledger/balance/account data, platform billing/payment data, and
 * raw free-text notes are never read by any builder below.
 *
 * Two of the eleven event subjects (Task, MatterReadinessScore) do not
 * carry a public uuid as of Phase 13 (confirmed by direct inspection);
 * Task.php and MatterReadinessScore.php are not in Phase 14's
 * allowed-to-modify list, so no uuid column is added to either. Their
 * payloads instead anchor on the related Matter's uuid, which both
 * models are always scoped to — never their own internal bigint id.
 *
 * event_uuid/delivery_uuid/timestamp/signature are NEVER included
 * inside payload_json itself — those are transmitted as the
 * X-FirmsBase-Event-Id / X-FirmsBase-Delivery-Id / X-FirmsBase-Timestamp
 * / X-FirmsBase-Signature headers (correction #6), assembled by
 * WebhookDispatchJob at send time, not stored redundantly in the
 * payload body.
 */
class WebhookPayloadBuilderService
{
    /**
     * @return array<string, mixed>
     */
    public function build(WebhookEventType $type, object $subject): array
    {
        return match ($type) {
            WebhookEventType::LeadCreated => $this->buildLeadCreated($subject),
            WebhookEventType::ClientCreated => $this->buildClientCreated($subject),
            WebhookEventType::MatterCreated => $this->buildMatterCreated($subject),
            WebhookEventType::DocumentUploaded => $this->buildDocumentUploaded($subject),
            WebhookEventType::InvoiceCreated => $this->buildInvoiceCreated($subject),
            WebhookEventType::PaymentPlanInstallmentDue => $this->buildPaymentPlanInstallmentDue($subject),
            WebhookEventType::PaymentRecorded => $this->buildPaymentRecorded($subject),
            WebhookEventType::TaskCompleted => $this->buildTaskCompleted($subject),
            WebhookEventType::FormApproved => $this->buildFormApproved($subject),
            WebhookEventType::SignatureCompleted => $this->buildSignatureCompleted($subject),
            WebhookEventType::MatterReadinessChanged => $this->buildMatterReadinessChanged($subject),
        };
    }

    private function assertInstanceOf(object $subject, string $class): void
    {
        if (! $subject instanceof $class) {
            throw new \InvalidArgumentException('Expected subject of type '.$class.', got '.get_class($subject));
        }
    }

    private function buildLeadCreated(object $subject): array
    {
        $this->assertInstanceOf($subject, FirmLead::class);

        return [
            'firm_uuid' => $subject->firm->uuid,
            'lead_uuid' => $subject->uuid,
            'name' => $subject->name,
            'email' => $subject->email,
            'phone' => $subject->phone,
            'status' => $subject->status?->value,
        ];
    }

    private function buildClientCreated(object $subject): array
    {
        $this->assertInstanceOf($subject, Client::class);

        return [
            'firm_uuid' => $subject->firm->uuid,
            'client_uuid' => $subject->uuid,
            'display_name' => $subject->display_name,
            'legal_name' => $subject->legal_name,
            'email' => $subject->email,
            'phone' => $subject->phone,
            'portal_status' => $subject->portal_status?->value,
        ];
    }

    private function buildMatterCreated(object $subject): array
    {
        $this->assertInstanceOf($subject, Matter::class);

        return [
            'firm_uuid' => $subject->firm->uuid,
            'matter_uuid' => $subject->uuid,
            'client_uuid' => $subject->client?->uuid,
            'status' => $subject->status?->value,
            'opened_at' => optional($subject->opened_at)->toIso8601String(),
        ];
    }

    private function buildDocumentUploaded(object $subject): array
    {
        $this->assertInstanceOf($subject, Document::class);

        return [
            'firm_uuid' => $subject->firm->uuid,
            'document_uuid' => $subject->uuid,
            'matter_uuid' => $subject->matter?->uuid,
            'mime_type' => $subject->mime_type,
        ];
    }

    private function buildInvoiceCreated(object $subject): array
    {
        $this->assertInstanceOf($subject, Invoice::class);

        return [
            'firm_uuid' => $subject->firm->uuid,
            'invoice_uuid' => $subject->uuid,
            'client_uuid' => $subject->client?->uuid,
            'matter_uuid' => $subject->matter?->uuid,
            'status' => $subject->status?->value,
            'total_cents' => $subject->total_cents,
            'currency' => $subject->currency,
        ];
    }

    private function buildPaymentPlanInstallmentDue(object $subject): array
    {
        $this->assertInstanceOf($subject, PaymentPlanInstallment::class);

        $firm = $subject->paymentPlan?->firm;

        return [
            'firm_uuid' => $firm?->uuid,
            'installment_uuid' => $subject->uuid,
            'amount_cents' => $subject->amount_cents,
            'due_at' => optional($subject->due_at)->toIso8601String(),
            'status' => $subject->status?->value,
        ];
    }

    private function buildPaymentRecorded(object $subject): array
    {
        $this->assertInstanceOf($subject, Payment::class);

        // Defensive assertion, not merely an assumption: trust/IOLTA
        // payments are hard-blocked at classification time (Phase 3,
        // reaffirmed by Phase 13) and must never reach a webhook
        // payload even if that block were ever bypassed elsewhere.
        if ($subject->payment_classification === PaymentClassification::TrustIoltaPayment) {
            throw new \RuntimeException('A trust_iolta_payment must never be recorded as a payment.recorded webhook payload.');
        }

        return [
            'firm_uuid' => $subject->firm->uuid,
            'payment_uuid' => $subject->uuid,
            'invoice_uuid' => $subject->invoice?->uuid,
            'amount_cents' => $subject->amount_cents,
            'classification' => $subject->payment_classification?->value,
            'status' => $subject->status?->value,
        ];
    }

    private function buildTaskCompleted(object $subject): array
    {
        $this->assertInstanceOf($subject, Task::class);

        return [
            'firm_uuid' => $subject->firm->uuid,
            'matter_uuid' => $subject->matter?->uuid,
            'title' => $subject->title,
            'completed_at' => optional($subject->completed_at)->toIso8601String(),
        ];
    }

    private function buildFormApproved(object $subject): array
    {
        $this->assertInstanceOf($subject, FormDraft::class);

        return [
            'firm_uuid' => $subject->firm->uuid,
            'form_draft_uuid' => $subject->uuid,
            'matter_uuid' => $subject->matter?->uuid,
            'status' => $subject->status?->value,
            'approved_at' => optional($subject->approved_at)->toIso8601String(),
        ];
    }

    private function buildSignatureCompleted(object $subject): array
    {
        $this->assertInstanceOf($subject, SignatureRequest::class);

        return [
            'firm_uuid' => $subject->firm->uuid,
            'signature_request_uuid' => $subject->uuid,
            'matter_uuid' => $subject->matter?->uuid,
            'title' => $subject->title,
            'completed_at' => optional($subject->completed_at)->toIso8601String(),
        ];
    }

    private function buildMatterReadinessChanged(object $subject): array
    {
        $this->assertInstanceOf($subject, MatterReadinessScore::class);

        return [
            'firm_uuid' => $subject->firm->uuid,
            'matter_uuid' => $subject->matter?->uuid,
            'status' => $subject->status?->value,
            'satisfied_count' => $subject->satisfied_count,
            'total_count' => $subject->total_count,
        ];
    }
}
