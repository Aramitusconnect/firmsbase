<?php

namespace App\Services;

use App\Enums\GovernanceMappingStatus;
use App\ValueObjects\GovernanceMappingResult;

/**
 * IdempotencyKeyCoverageMappingService — maps the 6 retry-sensitive
 * operation categories named in Section 26 to their EXISTING owning
 * idempotency/dedup mechanism, or the explicit absence of one. Purely
 * declarative — no schema change, no new idempotency column, no
 * behavior change to any payment/webhook/import model or service.
 */
class IdempotencyKeyCoverageMappingService
{
    /**
     * @return array<int, GovernanceMappingResult>
     */
    public function all(): array
    {
        return [
            new GovernanceMappingResult(
                item_key: 'payment_collection',
                item_label: 'Payment collection idempotency',
                owning_class: \App\Models\Payment::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'payments.idempotency_key exists, enforced by a partial unique index: CREATE UNIQUE INDEX payments_one_per_firm_idempotency_key ON payments (firm_id, idempotency_key) WHERE idempotency_key IS NOT NULL. A real, database-enforced mechanism.',
            ),
            new GovernanceMappingResult(
                item_key: 'payment_plan_installment_collection',
                item_label: 'Payment plan installment collection idempotency',
                owning_class: \App\Models\PaymentPlanInstallment::class,
                status: GovernanceMappingStatus::PartiallyImplemented,
                notes: 'payment_plan_installments carries no idempotency_key column of its own. Collecting an installment relies entirely on the linked Payment row\'s own idempotency_key/unique index for retry-safety — there is no installment-level key.',
            ),
            new GovernanceMappingResult(
                item_key: 'webhook_event_recording',
                item_label: 'Webhook event recording idempotency',
                owning_class: \App\Models\WebhookEvent::class,
                status: GovernanceMappingStatus::PartiallyImplemented,
                notes: 'webhook_events.uuid (unique) gives every recorded event a stable, unique identity, but this is a generated identifier, not a caller-supplied idempotency_key that would let a retried recording request be recognized as a duplicate of a specific prior attempt.',
            ),
            new GovernanceMappingResult(
                item_key: 'webhook_delivery_attempts',
                item_label: 'Webhook delivery attempt idempotency',
                owning_class: \App\Models\WebhookDeliveryAttempt::class,
                status: GovernanceMappingStatus::PartiallyImplemented,
                notes: 'webhook_delivery_attempts is append-only (mirrors trust_ledger_entries\' immutability shape) with an attempt_number column, but no uuid and no idempotency_key of any kind — confirmed by direct migration inspection.',
            ),
            new GovernanceMappingResult(
                item_key: 'import_apply',
                item_label: 'Import apply duplicate/idempotency protection',
                owning_class: \App\Services\ImportDuplicateDetectionService::class,
                status: GovernanceMappingStatus::PartiallyImplemented,
                notes: 'ImportDuplicateDetectionService performs natural-key duplicate detection for clients/contacts/matters/documents/parties (name/email/phone/hash matching). Its own docblock explicitly states invoices and payment_plans carry no external_reference/idempotency column and that this must not be added — duplicate detection there is out of scope by design, not an oversight.',
            ),
            new GovernanceMappingResult(
                item_key: 'retry_sensitive_jobs',
                item_label: 'Generic retry-sensitive job idempotency',
                owning_class: null,
                status: GovernanceMappingStatus::PartiallyImplemented,
                notes: 'DispatchNotificationJob, RunHealthChecksJob, ScanDocumentJob, and WebhookDispatchJob carry no job-level idempotency/dedup key of their own. Retry-safety, where it exists at all, is inherited from whatever downstream service-level mechanism the job calls into (e.g. payments.idempotency_key), not a generic job-layer guarantee.',
            ),
        ];
    }

    public function byKey(string $key): ?GovernanceMappingResult
    {
        foreach ($this->all() as $item) {
            if ($item->item_key === $key) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @return array<int, GovernanceMappingResult> items classified NotFound or PartiallyImplemented
     */
    public function gaps(): array
    {
        return array_values(array_filter(
            $this->all(),
            fn (GovernanceMappingResult $item) => in_array($item->status, [
                GovernanceMappingStatus::NotFound,
                GovernanceMappingStatus::PartiallyImplemented,
            ], true),
        ));
    }
}
