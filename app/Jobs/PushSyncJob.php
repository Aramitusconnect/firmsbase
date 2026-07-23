<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Integrations\Contracts\SupportsPushSyncContract;
use App\Integrations\Core\ProviderRegistry;
use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Enums\SyncDirection;
use App\Integrations\Enums\SyncItemStatus;
use App\Integrations\Enums\SyncRunStatus;
use App\Integrations\Enums\SyncTriggerSource;
use App\Integrations\Exceptions\SanitizedProviderHttpException;
use App\Integrations\Exceptions\SyncRunAlreadyInProgressException;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationExternalMapping;
use App\Integrations\Services\IntegrationConflictService;
use App\Integrations\Services\IntegrationExternalMappingService;
use App\Integrations\Services\SyncItemService;
use App\Integrations\Services\SyncRunService;
use App\Integrations\Support\OutboundProviderHttpClient;
use App\Support\TenantAwareJobContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * PushSyncJob — Checkpoint 8 (agent-8c-sync-job-design.md §1/§8-§10;
 * agent-8h-architecture-security-review.md §2 item 16). Same
 * tenant-context/connection-lock/status-gate discipline as
 * App\Jobs\PullSyncJob. $tries = 1 is deliberate, not an oversight: the
 * idempotency key below is deterministic over LOCAL STATE (connection,
 * resource identity, local_version_token), so a re-dispatch is
 * inherently safe even if queue-level retries were enabled — they stay
 * disabled so "how many times has this local-record-at-this-version
 * been attempted" has exactly one source of truth,
 * IntegrationSyncItem.attempt_count, never a second competing counter
 * on the queue side.
 */
final class PushSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TenantAwareJobContext;

    public int $tries = 1;

    public function __construct(
        public readonly int $firmIntegrationId,
        public readonly int $firmId,
        public readonly string $resourceType,
        public readonly string $localType,
        public readonly int $localId,
        public readonly string $localVersionToken,
        public readonly ?int $triggeringWebhookEventId = null,
        public readonly ?int $retriedRunId = null,
    ) {
    }

    public function handle(
        SyncRunService $runs,
        SyncItemService $items,
        IntegrationExternalMappingService $mappings,
        IntegrationConflictService $conflicts,
        ProviderRegistry $registry,
        OutboundProviderHttpClient $httpClient,
    ): void {
        $this->runInFirmContext($this->firmId, function () use ($runs, $items, $mappings, $conflicts, $registry, $httpClient) {
            // Requirements 1-2 (tenant context restore, connection
            // validation) and requirement 3 (connection lock) — IDENTICAL
            // shape to PullSyncJob::handle().
            $connection = FirmIntegration::query()
                ->where('id', $this->firmIntegrationId)
                ->where('firm_id', $this->firmId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($connection->status !== ConnectionStatus::Active) {
                return;
            }

            $triggerSource = $this->retriedRunId !== null
                ? SyncTriggerSource::RetryPoller
                : ($this->triggeringWebhookEventId !== null ? SyncTriggerSource::Webhook : SyncTriggerSource::SchedulerPoller);

            try {
                $run = $runs->startRun(
                    $connection,
                    $this->resourceType,
                    SyncDirection::Outbound,
                    $triggerSource,
                    null,
                    $this->retriedRunId,
                    $this->triggeringWebhookEventId,
                );
            } catch (SyncRunAlreadyInProgressException $e) {
                $run = $e->existingRun;
            }

            $run = $runs->transitionStatus($run, SyncRunStatus::Running);

            $existingMapping = IntegrationExternalMapping::query()
                ->where('firm_integration_id', $connection->id)
                ->where('resource_type', $this->resourceType)
                ->where('local_type', $this->localType)
                ->where('local_id', $this->localId)
                ->whereNull('tombstoned_at')
                ->first();

            // Requirement 3 (agent-8c §8.3): reject a stale local
            // version rather than pushing anyway — the mapping's
            // last-known local_version_token disagreeing with what THIS
            // job is about to push means something else has moved the
            // local record since the mapping was last updated.
            if ($existingMapping !== null
                && $existingMapping->local_version_token !== null
                && $existingMapping->local_version_token !== $this->localVersionToken) {
                $conflicts->recordDetection(
                    $connection,
                    $this->resourceType,
                    $this->localType,
                    $this->localId,
                    'stale_local_version_push_rejected',
                    externalMappingId: $existingMapping->id,
                    localVersionToken: $this->localVersionToken,
                    externalVersionToken: $existingMapping->external_version_token,
                );

                $items->recordAttempt(
                    $connection->firm_id, $run->id, $this->resourceType, $this->localType, $this->localId,
                    $existingMapping->external_id, SyncItemStatus::Skipped,
                );

                $runs->transitionStatus($run, SyncRunStatus::PartialFailure, 'stale_local_version_push_rejected');

                return;
            }

            $provider = $registry->get(ProviderKey::from($connection->integrationProvider->code));

            if (! $provider instanceof SupportsPushSyncContract) {
                $items->recordAttempt(
                    $connection->firm_id, $run->id, $this->resourceType, $this->localType, $this->localId,
                    $existingMapping?->external_id, SyncItemStatus::FailedPermanent,
                    lastError: 'provider_does_not_support_push',
                );
                $runs->transitionStatus($run, SyncRunStatus::Failed, 'provider_does_not_support_push');

                return;
            }

            // Deterministic idempotency key (agent-8c §8.2) — over
            // (connection, resource identity, local version), so a
            // retried dispatch of the SAME local-record-at-the-same-
            // version produces the SAME key, while a legitimate
            // subsequent push of a CHANGED local record produces a NEW
            // one. Folded into the payload for a provider that honors
            // idempotency keys; TestProvider itself does not need it
            // (makes zero network calls, already synthetic).
            $idempotencyKey = hash(
                'sha256',
                "{$connection->id}:{$this->resourceType}:{$this->localType}:{$this->localId}:{$this->localVersionToken}",
            );

            $payload = [
                'local_type' => $this->localType,
                'local_id' => $this->localId,
                'idempotency_key' => $idempotencyKey,
                'existing_external_id' => $existingMapping?->external_id,
            ];

            try {
                $result = $httpClient->execute(fn () => $provider->push([], $this->resourceType, $payload), 'push');
            } catch (SanitizedProviderHttpException $e) {
                $items->recordAttempt(
                    $connection->firm_id, $run->id, $this->resourceType, $this->localType, $this->localId,
                    $existingMapping?->external_id, SyncItemStatus::FailedPermanent,
                    lastError: "push_failed: {$e->category()}",
                );
                $runs->transitionStatus($run, SyncRunStatus::Failed, "push_failed: {$e->category()}");

                return;
            }

            $externalId = (string) ($result['external_id'] ?? '');
            $externalVersionToken = $result['version_token'] ?? null;

            if ($existingMapping !== null) {
                $mappings->refreshVersionTokens($existingMapping, $externalVersionToken, $this->localVersionToken);
            } else {
                $mappings->recordMapping(
                    $connection,
                    $this->resourceType,
                    $this->localType,
                    $this->localId,
                    $externalId,
                    SyncDirection::Outbound,
                    $externalVersionToken,
                    $this->localVersionToken,
                );
            }

            $items->recordAttempt(
                $connection->firm_id, $run->id, $this->resourceType, $this->localType, $this->localId,
                $externalId === '' ? null : $externalId, SyncItemStatus::Succeeded,
            );

            $runs->transitionStatus($run, SyncRunStatus::Succeeded);
        });
    }
}
