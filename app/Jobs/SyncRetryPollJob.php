<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Integrations\Contracts\SupportsPushSyncContract;
use App\Integrations\Core\ProviderRegistry;
use App\Integrations\Data\SanitizedHealthDiagnostic;
use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Enums\SyncDirection;
use App\Integrations\Enums\SyncItemStatus;
use App\Integrations\Exceptions\SanitizedProviderHttpException;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationExternalMapping;
use App\Integrations\Models\IntegrationSyncItem;
use App\Integrations\Services\HealthStateService;
use App\Integrations\Services\IntegrationExternalMappingService;
use App\Integrations\Services\SyncItemService;
use App\Integrations\Support\OutboundProviderHttpClient;
use App\Support\TenantAwareJobContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

/**
 * SyncRetryPollJob — Layer 2 of the two-layer sync retry-poller loop
 * (Checkpoint 8, agent-8h-architecture-security-review.md §1 item 1 /
 * §2 item 1: "a thin command enumerating firms and dispatching a small
 * per-firm job that loops SyncItemService::claimForRetry() over due
 * failed_retryable items for that firm, mirroring
 * DispatchOutboxEventsCommand's exact shape"). This is the FIRST
 * production caller of SyncItemService::claimForRetry() — the exact
 * primitive the CHECKPOINT 8 HARD PREREQUISITE (SyncItemService.php's
 * now() -> statement_timestamp() fix) exists to make safe to activate.
 *
 * For each successfully claimed (now `retrying`) item, this job
 * attempts an inline, single-item re-push for PUSH-shaped items
 * (local_type/local_id known — reuses the identical idempotency/
 * mapping discipline App\Jobs\PushSyncJob already established, without
 * re-running a whole new IntegrationSyncRun for a single retried item).
 * A PULL-shaped item (no local_type/local_id yet — the local record was
 * never resolved) has no generic single-item re-pull primitive in this
 * framework (App\Jobs\PullSyncJob is page/batch-oriented, not
 * single-item) — such an item is resolved FailedPermanent with a clear,
 * honest reason rather than silently left stuck `retrying` forever,
 * which would violate the claim's own guarantee. A future,
 * resource-type-specific pull-retry mechanism can supersede this
 * conservative behavior without changing this job's own contract.
 */
final class SyncRetryPollJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TenantAwareJobContext;

    public function __construct(
        public readonly int $firmId,
        public readonly int $batchSize = 25,
        // Checkpoint 12 addition (frozen-design-post-security-review.md
        // §2 F2 — a third hardcoded-[] call site, undercounted by 12C,
        // confirmed by 12H at the old SyncRetryPollJob.php:152): additive,
        // OPTIONAL, trailing nullable param — every existing caller that
        // omits it is completely unaffected and preserves today's exact
        // behavior (provider->push() continues to receive [] as its
        // context argument). Job-side context passthrough only — this
        // does NOT touch resolveOneRetry()'s retry-outcome/backoff logic
        // (see frozen design D2).
        //
        // Post-checkpoint-12 fix (JobConstructorsCarryOnlyScalarSecretSafeTypesTest):
        // declared ?string, not ?array — every ShouldQueue constructor
        // parameter in this codebase must be scalar/enum/DateTimeInterface
        // so Laravel never serializes an array into the queue payload.
        // Callers now pass a JSON-encoded string; decoded back to an
        // array in resolveOneRetry() below before use.
        public readonly ?string $providerContext = null,
    ) {
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('sync-retry-poll:'.$this->firmId))->releaseAfter(60)->expireAfter(900),
        ];
    }

    public function handle(
        SyncItemService $items,
        IntegrationExternalMappingService $mappings,
        ProviderRegistry $registry,
        OutboundProviderHttpClient $httpClient,
        HealthStateService $healthState,
    ): void {
        $this->runInFirmContext($this->firmId, function () use ($items, $mappings, $registry, $httpClient, $healthState) {
            $candidateIds = IntegrationSyncItem::query()
                ->where('firm_id', $this->firmId)
                ->where('status', SyncItemStatus::FailedRetryable->value)
                ->where('next_attempt_at', '<=', now())
                ->orderBy('next_attempt_at')
                ->limit($this->batchSize)
                ->pluck('id');

            foreach ($candidateIds as $itemId) {
                $claimed = $items->claimForRetry((int) $itemId);

                if ($claimed === null) {
                    // Lost the race to another poller, or the item was
                    // already resolved since the candidate scan —
                    // expected, safe, never an error.
                    continue;
                }

                $this->resolveOneRetry($claimed, $items, $mappings, $registry, $httpClient, $healthState);
            }
        });
    }

    private function resolveOneRetry(
        IntegrationSyncItem $item,
        SyncItemService $items,
        IntegrationExternalMappingService $mappings,
        ProviderRegistry $registry,
        OutboundProviderHttpClient $httpClient,
        HealthStateService $healthState,
    ): void {
        if ($item->local_type === null || $item->local_id === null) {
            $items->resolveRetryOutcome(
                $item->id,
                SyncItemStatus::FailedPermanent,
                lastError: 'pull_item_retry_not_supported_generically',
            );

            return;
        }

        // IntegrationSyncItem carries no firm_integration_id column of
        // its own — the connection is resolved via the owning
        // IntegrationSyncRun, exactly as every other per-item operation
        // in this checkpoint reaches its connection.
        $connection = $item->syncRun?->firmIntegration;

        if ($connection === null || $connection->status !== ConnectionStatus::Active) {
            $items->resolveRetryOutcome($item->id, SyncItemStatus::FailedRetryable, $this->nextAttemptAt());

            return;
        }

        $provider = $registry->get(ProviderKey::from($connection->integrationProvider->code));

        if (! $provider instanceof SupportsPushSyncContract) {
            $items->resolveRetryOutcome($item->id, SyncItemStatus::FailedPermanent, lastError: 'provider_does_not_support_push');

            return;
        }

        $existingMapping = IntegrationExternalMapping::query()
            ->where('firm_integration_id', $connection->id)
            ->where('resource_type', $item->resource_type)
            ->where('local_type', $item->local_type)
            ->where('local_id', $item->local_id)
            ->whereNull('tombstoned_at')
            ->first();

        // Checkpoint 12 fix (post-integration gap found by 12H, same as
        // App\Jobs\PushSyncJob::handle()): providerContext flows into
        // push()'s $context argument below, but TestProvider::push()
        // checks '__simulate_failure' against $payload, not $context. A
        // caller-supplied providerContext['__simulate_failure'] has to
        // be routed into $payload for the sentinel to actually be
        // reachable through a real job dispatch; every other
        // providerContext key keeps flowing into $context unchanged.
        // Unlike PushSyncJob, this job has no local_version_token
        // available (IntegrationSyncItem carries no such column), so it
        // computes no idempotency_key of its own — TestProvider::push()
        // simply falls back to reading $context['idempotency_key'] for
        // this call site, unchanged from before this fix.
        //
        // Decoded from the JSON-encoded constructor param — see this
        // job's constructor docblock (§ post-checkpoint-12 fix) for why
        // the constructor-declared type is ?string rather than ?array.
        // Only ever set by test code today (frozen design), so a
        // defensive is_array() fallback to [] on malformed input is
        // sufficient.
        $providerContext = [];
        if ($this->providerContext !== null) {
            $decoded = json_decode($this->providerContext, true);
            $providerContext = is_array($decoded) ? $decoded : [];
        }

        $pushPayload = [
            'local_type' => $item->local_type,
            'local_id' => $item->local_id,
            'existing_external_id' => $existingMapping?->external_id,
        ];

        if (array_key_exists('__simulate_failure', $providerContext)) {
            $pushPayload['__simulate_failure'] = $providerContext['__simulate_failure'];
        }

        try {
            $result = $httpClient->execute(
                fn () => $provider->push($providerContext, $item->resource_type, $pushPayload),
                'push',
            );
        } catch (SanitizedProviderHttpException $e) {
            $items->resolveRetryOutcome(
                $item->id,
                SyncItemStatus::FailedRetryable,
                $this->nextAttemptAt(),
                "retry_push_failed: {$e->category()}",
            );

            $this->recordHealthSignal($connection, $e->category(), $healthState);

            return;
        }

        $externalId = (string) ($result['external_id'] ?? '');
        $externalVersionToken = $result['version_token'] ?? null;

        if ($existingMapping !== null) {
            $mappings->refreshVersionTokens($existingMapping, $externalVersionToken, $existingMapping->local_version_token);
        } else {
            $mappings->recordMapping(
                $connection,
                $item->resource_type,
                $item->local_type,
                $item->local_id,
                $externalId,
                SyncDirection::Outbound,
                $externalVersionToken,
            );
        }

        $items->resolveRetryOutcome($item->id, SyncItemStatus::Succeeded);

        $healthState->recordSuccess($connection->id, $this->firmId);
    }

    /**
     * Frozen requirement (agent-8h-architecture-security-review.md §6,
     * "Call sites, frozen": "`SyncRetryPollJob` (new, §2 item 1) -> same
     * mapping, once built") — mirrors
     * App\Jobs\OutboxDispatchJob::dispatchOne()'s `recordHealthSignal()`
     * exactly (same category -> HealthStateService::record*() mapping,
     * same OPERATION_* constant substituted for this job's own
     * operation), called whenever a category is resolved and the
     * connection (firm_integration_id) is known for the item being
     * processed.
     */
    private function recordHealthSignal(FirmIntegration $connection, string $category, HealthStateService $healthState): void
    {
        $operation = SanitizedHealthDiagnostic::OPERATION_PUSH_SYNC;

        match (true) {
            $category === 'rate_limited' => $healthState->recordRateLimited(
                $connection->id,
                $this->firmId,
                now()->addMinutes(1),
                new SanitizedHealthDiagnostic(SanitizedHealthDiagnostic::CATEGORY_RATE_LIMITED, $operation),
            ),
            in_array($category, ['authentication_failed', 'invalid_grant'], true) => $healthState->recordCredentialError(
                $connection->id,
                $this->firmId,
                new SanitizedHealthDiagnostic(SanitizedHealthDiagnostic::CATEGORY_CREDENTIAL_ERROR, $operation),
            ),
            $category === 'authorization_failed' => $healthState->recordScopeError(
                $connection->id,
                $this->firmId,
                new SanitizedHealthDiagnostic(SanitizedHealthDiagnostic::CATEGORY_SCOPE_ERROR, $operation),
            ),
            default => $healthState->recordProviderError(
                $connection->id,
                $this->firmId,
                new SanitizedHealthDiagnostic(SanitizedHealthDiagnostic::CATEGORY_PROVIDER_ERROR, $operation),
            ),
        };
    }

    private function nextAttemptAt(): string
    {
        return now()->addMinutes(5)->toDateTimeString();
    }
}
