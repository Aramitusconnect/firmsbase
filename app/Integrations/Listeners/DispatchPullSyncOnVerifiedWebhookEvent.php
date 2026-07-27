<?php

declare(strict_types=1);

namespace App\Integrations\Listeners;

use App\Integrations\Contracts\SupportsPullSyncContract;
use App\Integrations\Core\ProviderRegistry;
use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Enums\ResourceType;
use App\Integrations\Models\FirmIntegration;
use App\Jobs\PullSyncJob;
use App\Support\TenantAwareJobContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * DispatchPullSyncOnVerifiedWebhookEvent — FirmsVault Live Integrations,
 * Checkpoint 2 (checkpoint2-design-sync-webhooks.md §5.3, "nothing
 * consumes verified events into sync actions"; checkpoint2-combined-design.md
 * §2 P-21). Closes the one remaining wiring gap in the inbound-webhook
 * pipeline: as of Checkpoint 1, a verified
 * App\Integrations\Models\IntegrationInboundWebhookEvent row is durably
 * recorded and a firm timeline event is written, but nothing
 * automatically turns that into a dispatched
 * App\Jobs\PullSyncJob — webhooks were verified, deduped, and recorded,
 * but never actually triggered the near-real-time sync they exist to
 * trigger.
 *
 * DISPATCH-SITE DECISION (this class is named/shaped per the design
 * document's own "Listeners/" suggestion, but is dispatched directly,
 * not registered against a framework Illuminate event) — read
 * App\Integrations\Services\InboundWebhookEventService::recordVerifiedEvent()
 * in full before assuming otherwise: it writes the event row via a raw
 * `DB::table(...)->insertOrIgnoreReturning(...)`, never Eloquent's
 * `Model::create()`/`save()`. A raw query-builder INSERT never fires
 * Eloquent's `created` model event — so a listener registered against
 * `IntegrationInboundWebhookEvent::created` would NEVER fire in
 * production, silently inert from day one. The correct, actually-firing
 * hook point is therefore a DIRECT call, exactly where
 * App\Integrations\Http\Controllers\InboundWebhookController already
 * calls `recordFirmTimelineEvent()` — the ONE place in that controller
 * that already knows `$result['was_newly_created'] === true` (a
 * retried/duplicate delivery must never re-trigger a second sync for
 * the same event, mirroring that exact call's own duplicate-guard).
 * This class is therefore implemented as a queued ShouldQueue job,
 * dispatched via `::dispatch()` directly from that controller —
 * identical precedent to
 * App\Integrations\Jobs\RecordWebhookVerificationFailureJob, already
 * dispatched the same way, from the same controller, for the same
 * "fire-and-forget side effect off the timing-critical request path"
 * reason.
 *
 * Provider-agnostic by construction (never branches on provider
 * identity): resolves the provider via ProviderRegistry, checks
 * `instanceof SupportsPullSyncContract` before doing anything else, and
 * maps the event's `event_type` back to a
 * App\Integrations\Enums\ResourceType via a small, deliberately
 * conservative heuristic (see mapEventTypeToResourceType() below) —
 * best-effort only. A future provider's own `parseInboundEvent()`
 * should normalize `event_type` to either exactly match a ResourceType
 * value or lead with one (e.g. `"message.created"`,
 * `"calendar_event:updated"`) for this mapping to succeed; anything
 * else (including Graph's own lifecycle-notification event types, e.g.
 * `lifecycle:reauthorizationRequired` — design §3.4, left as a future
 * consumer-side concern, not built here) is logged and skipped rather
 * than guessed.
 *
 * Scalar-FK/string-only constructor — every ShouldQueue job in this
 * codebase must carry only scalar/enum/DateTimeInterface constructor
 * parameters (JobConstructorsCarryOnlyScalarSecretSafeTypesTest), never
 * a hydrated model.
 */
final class DispatchPullSyncOnVerifiedWebhookEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TenantAwareJobContext;

    public int $tries = 3;

    public function __construct(
        public readonly int $firmIntegrationId,
        public readonly int $firmId,
        public readonly string $providerKey,
        public readonly ?string $eventType,
        public readonly int $webhookEventId,
    ) {}

    public function handle(ProviderRegistry $registry): void
    {
        $this->runInFirmContext($this->firmId, function () use ($registry): void {
            // Re-verify fresh, past-dispatch-time — never trust
            // anything carried in the job payload itself (this
            // codebase's own established discipline, see
            // RefreshIntegrationToken/PullSyncJob's identical Gate 1).
            // A connection disconnected between webhook-receipt time
            // and this job's execution time must silently no-op, never
            // trigger a sync for a connection that no longer has
            // usable credentials.
            $connection = FirmIntegration::query()
                ->where('id', $this->firmIntegrationId)
                ->first();

            if ($connection === null || $connection->status !== ConnectionStatus::Active) {
                return;
            }

            $providerKey = ProviderKey::tryFrom($this->providerKey);

            if ($providerKey === null || ! $registry->has($providerKey)) {
                // Should be structurally impossible — the controller
                // only ever persists an event row after successfully
                // resolving this exact provider key — kept as a
                // defensive, never-throwing no-op in case the provider
                // was disabled between webhook-receipt time and this
                // job's execution time.
                return;
            }

            $provider = $registry->get($providerKey);

            if (! $provider instanceof SupportsPullSyncContract) {
                return;
            }

            $resourceType = $this->mapEventTypeToResourceType($this->eventType);

            if ($resourceType === null) {
                Log::info('DispatchPullSyncOnVerifiedWebhookEvent: no clean resource-type mapping for this event_type; skipping rather than guessing.', [
                    'firm_integration_id' => $this->firmIntegrationId,
                    'provider_key' => $this->providerKey,
                    'webhook_event_id' => $this->webhookEventId,
                ]);

                return;
            }

            if (! in_array($resourceType->value, $provider->pullableResourceTypes(), true)) {
                Log::info('DispatchPullSyncOnVerifiedWebhookEvent: provider does not declare this resource type as pullable; skipping.', [
                    'firm_integration_id' => $this->firmIntegrationId,
                    'provider_key' => $this->providerKey,
                    'resource_type' => $resourceType->value,
                    'webhook_event_id' => $this->webhookEventId,
                ]);

                return;
            }

            PullSyncJob::dispatch(
                $this->firmIntegrationId,
                $this->firmId,
                $resourceType->value,
                triggeringWebhookEventId: $this->webhookEventId,
            );
        });
    }

    /**
     * Deliberately conservative, provider-agnostic best-effort mapping
     * — never a substring/fuzzy match that could silently pick the
     * wrong resource type (design §5.3: "if no clean mapping exists for
     * a given event, log/skip rather than guess"). Two shapes only:
     *  1. `event_type` is EXACTLY one of ResourceType's own values
     *     (e.g. `"message"`, or `"calendar_event"`).
     *  2. `event_type` LEADS WITH one of those values, separated by one
     *     of a small set of conventional delimiters — `.`, `:`, `/`, or
     *     `-` (e.g. `"message.created"`, `"calendar_event:updated"`,
     *     `"document/uploaded"`). Deliberately excludes `_` from the
     *     delimiter set: `_` is itself part of a legitimate ResourceType
     *     value (`calendar_event`), so treating it as a compound-shape
     *     separator would incorrectly truncate that value's own exact
     *     match (case 1 above) into an unrecognizable `"calendar"`
     *     fragment before it ever gets there.
     * Anything else — including a compound value whose first segment
     * still doesn't match, or Graph's own lifecycle-notification event
     * types (e.g. `"lifecycle:reauthorizationRequired"`, whose first
     * segment `"lifecycle"` is not a ResourceType value) — returns
     * null, never a guess.
     */
    private function mapEventTypeToResourceType(?string $eventType): ?ResourceType
    {
        if ($eventType === null || trim($eventType) === '') {
            return null;
        }

        $normalized = strtolower(trim($eventType));

        $direct = ResourceType::tryFrom($normalized);

        if ($direct !== null) {
            return $direct;
        }

        $segments = preg_split('/[.:\/-]/', $normalized, 2);
        $firstSegment = $segments[0] ?? null;

        if ($firstSegment === null || $firstSegment === '') {
            return null;
        }

        return ResourceType::tryFrom($firstSegment);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('DispatchPullSyncOnVerifiedWebhookEvent: failed to dispatch a webhook-triggered pull sync.', [
            'firm_integration_id' => $this->firmIntegrationId,
            'provider_key' => $this->providerKey,
            'webhook_event_id' => $this->webhookEventId,
            'exception' => $exception?->getMessage(),
        ]);
    }
}
