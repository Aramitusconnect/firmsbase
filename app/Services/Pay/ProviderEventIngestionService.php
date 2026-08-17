<?php

declare(strict_types=1);

namespace App\Services\Pay;

use App\Integrations\Enums\WebhookInboundEventStatus;
use App\Integrations\Models\IntegrationInboundWebhookEvent;
use App\Models\PaymentAttempt;
use App\Models\PaymentRefund;
use App\Services\Pay\Data\FakeProviderEvent;
use App\Services\Pay\Data\ProviderResult;
use App\Services\TenantContextService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * ProviderEventIngestionService — FirmsVault Pay Gate A3
 * (v1.4 §2/§24-§29). The provider-neutral inbound event path:
 *
 *     verified provider event
 *         ↓
 *     ProviderResourceLocator authority, reached ONLY through
 *     ProviderResourceOwnershipService (v1.4 §27) — this class never
 *     touches the locator table or model itself
 *         ↓
 *     canonical provider event (integration_inbound_webhook_events)
 *         ↓   ← REUSED table; UNIQUE(firm_integration_id, provider_key,
 *               provider_event_id) is the database dedupe (v1.4 §24)
 *     ProviderOutcomeApplierService
 *         ↓   ← the same exactly-once applier every other path uses
 *     tenant financial domain
 *
 * FAIL-CLOSED RULES, in resolution order:
 *   UNRESOLVED  — the locator knows nothing about the resource. The
 *                 event NEVER enters the tenant domain: no canonical
 *                 row, no guessed firm, no mutation (§26). The SAME
 *                 mechanical state also covers out-of-order arrival
 *                 (§25): if ownership is established later, re-ingesting
 *                 the identical event processes it exactly once. An
 *                 event that never resolves stays restricted forever —
 *                 deferral and quarantine differ only in how they end.
 *   CONNECTION_MISMATCH — the locator resolves, but the event arrived
 *                 through a DIFFERENT provider connection than the
 *                 owner. Fail closed (§28).
 *   ENVIRONMENT_MISMATCH — sandbox/live context mismatch. Fail closed
 *                 (§29).
 *   DUPLICATE   — the canonical event already exists and was processed.
 *                 The database unique constraint is the authority.
 *   DEFERRED    — ownership resolves but the internal aggregate this
 *                 event describes is not visible yet. The canonical row
 *                 is retained (status stays Verified) and re-ingestion
 *                 later completes it — never FAILED, never guessed
 *                 (§25).
 *   PROCESSED   — applied exactly once via the shared applier; a racing
 *                 sync response/recovery makes this a no-op, never a
 *                 double effect (§22/§23).
 */
class ProviderEventIngestionService
{
    public const PROCESSED = 'processed';

    public const DUPLICATE = 'duplicate';

    public const DEFERRED = 'deferred';

    public const UNRESOLVED = 'unresolved';

    public const CONNECTION_MISMATCH = 'connection_mismatch';

    public const ENVIRONMENT_MISMATCH = 'environment_mismatch';

    public function __construct(
        private readonly TenantContextService $tenantContext,
        private readonly ProviderResourceOwnershipService $ownership,
        private readonly ProviderOutcomeApplierService $applier,
        private readonly PayAuditRecorder $audit,
    ) {}

    public function ingest(FakeProviderEvent $event): string
    {
        // 1. THE ownership authority — pre-tenant, bounded, no guessing
        //    (v1.4 §27). Null means the event stays restricted.
        $owner = $this->ownership->resolveOwner(
            $event->integrationProviderId,
            $event->resourceType,
            $event->resourceReference,
        );

        if ($owner === null) {
            // No tenant row of any kind may be written for an
            // unresolved event (§26) — not even quarantine evidence in a
            // tenant-visible table.
            return self::UNRESOLVED;
        }

        // 2. Wrong-connection presentation fails closed (§28).
        if ($event->presentedFirmIntegrationId !== null
            && $event->presentedFirmIntegrationId !== $owner->firmIntegrationId) {
            $this->audit->record('pay.provider_event.connection_mismatch', $owner->firmId, [
                'provider_event_id' => $event->eventId,
                'presented_firm_integration_id' => $event->presentedFirmIntegrationId,
            ]);

            return self::CONNECTION_MISMATCH;
        }

        // 3. Wrong-environment presentation fails closed (§29).
        if ($event->environment !== ProviderCommandExecutorService::ENVIRONMENT) {
            $this->audit->record('pay.provider_event.environment_mismatch', $owner->firmId, [
                'provider_event_id' => $event->eventId,
                'environment' => $event->environment,
            ]);

            return self::ENVIRONMENT_MISMATCH;
        }

        // 4. Canonical event + dedupe + apply, under the OWNER's context.
        return $this->tenantContext->runWithFirmContext($owner->firmId, function () use ($event, $owner): string {
            $canonical = $this->canonicalEventRow($event, $owner->firmId, $owner->firmIntegrationId);

            if ($canonical === null) {
                // The unique index arbitrated: this exact event was
                // already recorded AND fully processed (§24).
                return self::DUPLICATE;
            }

            $aggregate = $this->aggregateFor($event);

            if ($aggregate === null) {
                // Out-of-order: valid, owned, but its internal dependency
                // is not visible yet. Retained, retryable, never failed,
                // never guessed (§25).
                IntegrationInboundWebhookEvent::query()
                    ->whereKey($canonical->id)
                    ->update(['failure_code' => 'deferred_dependency_missing', 'processing_attempts' => DB::raw('processing_attempts + 1')]);

                return self::DEFERRED;
            }

            $result = new ProviderResult(
                providerCommandUuid: '',
                providerResourceReference: $event->resourceReference,
                outcome: $event->outcome,
                amountCents: $event->amountCents,
                currency: 'USD',
                occurredAt: new \DateTimeImmutable('now'),
                evidenceReference: 'event:'.$event->eventId,
                providerMetadata: ['source' => 'provider_event'],
            );

            if ($aggregate instanceof PaymentAttempt) {
                $this->applier->applyPaymentOutcome($aggregate, $result);
            } else {
                $this->applier->applyRefundOutcome($aggregate, $result);
            }

            IntegrationInboundWebhookEvent::query()
                ->whereKey($canonical->id)
                ->update([
                    'status' => WebhookInboundEventStatus::Processed->value,
                    'processed_at' => now(),
                    // The table's own CHECK: a terminal status requires
                    // terminal_at.
                    'terminal_at' => now(),
                    'failure_code' => null,
                ]);

            return self::PROCESSED;
        });
    }

    // ------------------------------------------------------------------

    /**
     * Insert-or-arbitrate the canonical event row. Returns null when the
     * event is a true duplicate of an already-PROCESSED event; returns
     * the existing row when it exists but is still pending (deferred
     * retry).
     */
    private function canonicalEventRow(FakeProviderEvent $event, int $firmId, int $firmIntegrationId): ?IntegrationInboundWebhookEvent
    {
        try {
            // Savepoint isolation — a unique violation must not poison
            // the caller's transaction (same reasoning as
            // ProviderCommandService::createOrReuse()).
            return DB::transaction(fn () => IntegrationInboundWebhookEvent::query()->create([
                'firm_id' => $firmId,
                'firm_integration_id' => $firmIntegrationId,
                'provider_key' => $event->providerKey,
                'provider_event_id' => $event->eventId,
                'event_type' => 'firmsvault_pay.'.$event->resourceType.'.'.$event->outcome->value,
                'payload_reference_json' => [
                    'resource_type' => $event->resourceType,
                    'resource_reference' => $event->resourceReference,
                    'amount_cents' => $event->amountCents,
                ],
                'status' => WebhookInboundEventStatus::Verified->value,
                'received_at' => now(),
                'retention_deadline' => now()->addDays(90),
            ]));
        } catch (UniqueConstraintViolationException) {
            /** @var IntegrationInboundWebhookEvent $existing */
            $existing = IntegrationInboundWebhookEvent::query()
                ->where('firm_integration_id', $firmIntegrationId)
                ->where('provider_key', $event->providerKey)
                ->where('provider_event_id', $event->eventId)
                ->firstOrFail();

            if ($existing->status === WebhookInboundEventStatus::Processed) {
                return null;
            }

            return $existing;
        }
    }

    private function aggregateFor(FakeProviderEvent $event): PaymentAttempt|PaymentRefund|null
    {
        if ($event->resourceType === 'refund') {
            return PaymentRefund::query()
                ->where('provider_reference', $event->resourceReference)
                ->first();
        }

        return PaymentAttempt::query()
            ->where('provider_reference', $event->resourceReference)
            ->first();
    }
}
