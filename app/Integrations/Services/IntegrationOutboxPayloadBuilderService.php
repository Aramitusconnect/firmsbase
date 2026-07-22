<?php

declare(strict_types=1);

namespace App\Integrations\Services;

use App\Enums\PaymentClassification;
use App\Integrations\Data\SanitizedPayloadReference;
use App\Integrations\Enums\ResourceType;
use App\Models\CalendarEvent;
use App\Models\Contact;
use App\Models\Document;
use App\Models\EmailMessage;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Task;
use InvalidArgumentException;
use RuntimeException;

/**
 * IntegrationOutboxPayloadBuilderService — the ONLY place a domain
 * model becomes an outbox/sync payload column (Checkpoint 6,
 * reviews/checkpoint-06/frozen-design-post-review.md §11;
 * agent-6g-privacy-payload-retention-audit.md §1). Mirrors
 * App\Services\WebhookPayloadBuilderService's proven shape exactly:
 * one class, one match() dispatch by ResourceType, one private
 * ALLOWLIST builder method per resource type — never
 * `$model->toArray()`. Forbidden categories (credential material,
 * complete documents, complete email bodies, trust/IOLTA payment data)
 * are structurally impossible to leak because each builder only ever
 * reads the specific named fields it lists, never a generic dump.
 *
 * `build()`'s second parameter is `object $subject` (never `Model`
 * directly in the signature) purely to mirror
 * WebhookPayloadBuilderService's exact contract; every builder method
 * below still asserts the concrete expected class via
 * assertInstanceOf() before reading any field, exactly as that class
 * does. This does not weaken the "no Model accepted" structural claim
 * for the WRITE path (IntegrationOutboxEventService::recordOnce()) —
 * that claim is about the return type every write method accepts
 * (SanitizedPayloadReference only), not about this one, deliberately
 * narrow translation boundary between a real domain model and that
 * DTO.
 */
final class IntegrationOutboxPayloadBuilderService
{
    public function build(ResourceType $type, object $subject): SanitizedPayloadReference
    {
        return match ($type) {
            ResourceType::Contact => $this->buildContact($subject),
            ResourceType::CalendarEvent => $this->buildCalendarEvent($subject),
            ResourceType::Document => $this->buildDocument($subject),
            ResourceType::Task => $this->buildTask($subject),
            ResourceType::Message => $this->buildMessage($subject),
            ResourceType::Invoice => $this->buildInvoice($subject),
            ResourceType::Payment => $this->buildPayment($subject),
        };
    }

    private function assertInstanceOf(object $subject, string $class): void
    {
        if (! $subject instanceof $class) {
            throw new InvalidArgumentException('Expected subject of type '.$class.', got '.get_class($subject));
        }
    }

    private function buildContact(object $subject): SanitizedPayloadReference
    {
        $this->assertInstanceOf($subject, Contact::class);

        return new SanitizedPayloadReference(
            resourceType: ResourceType::Contact,
            resourceId: (string) $subject->uuid,
            fields: [
                'name' => $subject->name,
                'email' => $subject->email,
                'phone' => $subject->phone,
            ],
        );
    }

    /**
     * CalendarEvent carries no public uuid of its own (confirmed by
     * direct inspection). Anchors on the related Matter's uuid instead
     * — the identical substitution rule WebhookPayloadBuilderService
     * already applies for Task/MatterReadinessScore, not a new one.
     */
    private function buildCalendarEvent(object $subject): SanitizedPayloadReference
    {
        $this->assertInstanceOf($subject, CalendarEvent::class);

        return new SanitizedPayloadReference(
            resourceType: ResourceType::CalendarEvent,
            resourceId: (string) $subject->matter?->uuid,
            fields: [
                'title' => $subject->title,
                'starts_at' => optional($subject->starts_at)->toIso8601String(),
            ],
        );
    }

    private function buildDocument(object $subject): SanitizedPayloadReference
    {
        $this->assertInstanceOf($subject, Document::class);

        return new SanitizedPayloadReference(
            resourceType: ResourceType::Document,
            resourceId: (string) $subject->uuid,
            fields: [
                'matter_uuid' => $subject->matter?->uuid,
                'mime_type' => $subject->mime_type,
                'status' => $subject->status?->value,
            ],
        );
    }

    /**
     * Task carries no public uuid of its own (confirmed by direct
     * inspection) — anchors on the related Matter's uuid, mirroring
     * WebhookPayloadBuilderService::buildTaskCompleted() exactly.
     */
    private function buildTask(object $subject): SanitizedPayloadReference
    {
        $this->assertInstanceOf($subject, Task::class);

        return new SanitizedPayloadReference(
            resourceType: ResourceType::Task,
            resourceId: (string) $subject->matter?->uuid,
            fields: [
                'title' => $subject->title,
                'status' => $subject->status?->value,
            ],
        );
    }

    /**
     * NEVER reads encrypted_body_ciphertext/decrypted body content
     * (per agent-6g §3's never-list) — subject/status/timestamp-shaped
     * fields only.
     */
    private function buildMessage(object $subject): SanitizedPayloadReference
    {
        $this->assertInstanceOf($subject, EmailMessage::class);

        return new SanitizedPayloadReference(
            resourceType: ResourceType::Message,
            resourceId: (string) $subject->uuid,
            fields: [
                'subject' => $subject->subject,
                'direction' => $subject->direction?->value,
                'body_status' => $subject->body_status?->value,
            ],
        );
    }

    private function buildInvoice(object $subject): SanitizedPayloadReference
    {
        $this->assertInstanceOf($subject, Invoice::class);

        return new SanitizedPayloadReference(
            resourceType: ResourceType::Invoice,
            resourceId: (string) $subject->uuid,
            fields: [
                'status' => $subject->status?->value,
                'total_cents' => $subject->total_cents,
                'currency' => $subject->currency,
            ],
        );
    }

    /**
     * Defensive assertion, not merely an assumption (mirrors
     * WebhookPayloadBuilderService::buildPaymentRecorded() verbatim):
     * trust/IOLTA payments must never reach an outbox/sync payload even
     * if this were ever bypassed elsewhere.
     */
    private function buildPayment(object $subject): SanitizedPayloadReference
    {
        $this->assertInstanceOf($subject, Payment::class);

        if ($subject->payment_classification === PaymentClassification::TrustIoltaPayment) {
            throw new RuntimeException('A trust_iolta_payment must never be recorded as an outbox/sync payload.');
        }

        return new SanitizedPayloadReference(
            resourceType: ResourceType::Payment,
            resourceId: (string) $subject->uuid,
            fields: [
                'amount_cents' => $subject->amount_cents,
                'classification' => $subject->payment_classification?->value,
                'status' => $subject->status?->value,
            ],
        );
    }
}
