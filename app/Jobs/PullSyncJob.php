<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Integrations\Contracts\SupportsPullSyncContract;
use App\Integrations\Core\ProviderRegistry;
use App\Integrations\Data\SanitizedHealthDiagnostic;
use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\CredentialType;
use App\Integrations\Enums\CursorStatus;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Enums\SyncDirection;
use App\Integrations\Enums\SyncItemStatus;
use App\Integrations\Enums\SyncRunStatus;
use App\Integrations\Enums\SyncTriggerSource;
use App\Integrations\Exceptions\SanitizedProviderHttpException;
use App\Integrations\Exceptions\SyncRunAlreadyInProgressException;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationCredential;
use App\Integrations\Models\IntegrationExternalMapping;
use App\Integrations\Models\IntegrationSyncCursor;
use App\Integrations\Models\IntegrationSyncRun;
use App\Integrations\Services\HealthStateService;
use App\Integrations\Services\IntegrationConflictService;
use App\Integrations\Services\IntegrationCredentialService;
use App\Integrations\Services\IntegrationExternalMappingService;
use App\Integrations\Services\SyncCursorService;
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
 * PullSyncJob — Checkpoint 8 (agent-8c-sync-job-design.md §1-§7;
 * agent-8h-architecture-security-review.md §2 item 16). Scalar-ID-only
 * constructor mirroring App\Jobs\WebhookDispatchJob exactly. Introduces
 * ZERO new locking/claiming primitives — every mechanism below reuses
 * an already-proven one: the connection lock is
 * App\Integrations\Services\IntegrationCredentialService::withRefreshLock()'s
 * exact lockForUpdate() shape; the cursor claim is
 * App\Integrations\Services\SyncCursorService::claim(); resume-vs-start
 * is SyncRunService::startRun()'s own attempt-then-catch discipline.
 */
final class PullSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TenantAwareJobContext;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        public readonly int $firmIntegrationId,
        public readonly int $firmId,
        public readonly string $resourceType,
        public readonly ?int $triggeringWebhookEventId = null,
        public readonly ?int $retriedRunId = null,
    ) {
    }

    public function handle(
        SyncRunService $runs,
        SyncItemService $items,
        SyncCursorService $cursors,
        IntegrationExternalMappingService $mappings,
        IntegrationConflictService $conflicts,
        ProviderRegistry $registry,
        OutboundProviderHttpClient $httpClient,
    ): void {
        // Resolved via the container (not added as new handle() parameters)
        // — App\Jobs\PullSyncJob's constructor docblock requires this
        // class's public signature to stay scalar-ID-only/stable, and
        // App\Integrations\Jobs\RefreshIntegrationToken::failed() already
        // establishes this exact app()-resolution pattern for the same
        // two services on the equivalent failure path.
        $credentials = app(IntegrationCredentialService::class);
        $healthState = app(HealthStateService::class);

        $this->runInFirmContext($this->firmId, function () use ($runs, $items, $cursors, $mappings, $conflicts, $registry, $httpClient, $credentials, $healthState) {
            // Requirement 2: re-verify fresh, past-dispatch-time —
            // never trust anything carried in the job payload itself.
            // ->lockForUpdate() here does double duty: fresh-read
            // re-validation AND requirement 3's connection lock, the
            // SAME shape IntegrationCredentialService::withRefreshLock()
            // already uses.
            $connection = FirmIntegration::query()
                ->where('id', $this->firmIntegrationId)
                ->where('firm_id', $this->firmId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($connection->status !== ConnectionStatus::Active) {
                return;
            }

            $cursor = $cursors->firstOrCreate($connection, $this->resourceType, SyncDirection::Inbound);

            if ($cursor->status === CursorStatus::Invalid && $this->retriedRunId === null) {
                // An Incremental run must refuse to claim an Invalid
                // cursor at all — fail closed rather than starting a
                // Pending run for a scope that structurally cannot
                // proceed as an ordinary incremental pull.
                return;
            }

            $triggerSource = $this->retriedRunId !== null
                ? SyncTriggerSource::RetryPoller
                : ($this->triggeringWebhookEventId !== null ? SyncTriggerSource::Webhook : SyncTriggerSource::SchedulerPoller);

            try {
                $run = $runs->startRun(
                    $connection,
                    $this->resourceType,
                    SyncDirection::Inbound,
                    $triggerSource,
                    $cursor,
                    $this->retriedRunId,
                    $this->triggeringWebhookEventId,
                );
            } catch (SyncRunAlreadyInProgressException $e) {
                $run = $e->existingRun;
            }

            $claimedCursor = $cursors->claim($cursor->id, $run->id);

            if ($claimedCursor === null) {
                // Another run genuinely already holds this cursor —
                // skip-duplicate, never busy-wait or double-process.
                return;
            }

            $this->runBatchLoop($run, $claimedCursor, $connection, $runs, $items, $cursors, $mappings, $conflicts, $registry, $httpClient, $credentials, $healthState);
        });
    }

    private function runBatchLoop(
        IntegrationSyncRun $run,
        IntegrationSyncCursor $cursor,
        FirmIntegration $connection,
        SyncRunService $runs,
        SyncItemService $items,
        SyncCursorService $cursors,
        IntegrationExternalMappingService $mappings,
        IntegrationConflictService $conflicts,
        ProviderRegistry $registry,
        OutboundProviderHttpClient $httpClient,
        IntegrationCredentialService $credentials,
        HealthStateService $healthState,
    ): void {
        $run = $runs->transitionStatus($run, SyncRunStatus::Running);

        $provider = $registry->get(ProviderKey::from($connection->integrationProvider->code));

        if (! $provider instanceof SupportsPullSyncContract) {
            $runs->transitionStatus($run, SyncRunStatus::Failed, 'provider_does_not_support_pull');
            $cursors->markFailed($cursor->id);

            return;
        }

        // Requirement (data-consistency edge case): FirmIntegration.status
        // === Active alone does not prove this connection actually holds
        // usable credential material — a connection whose OAuth
        // credentials have all been revoked/rotated away, but whose
        // status column has not (yet) transitioned off Active, must never
        // be allowed to complete a normal pull using no real credential
        // at all. Mirrors the "does an Active credential of the required
        // type exist" pattern IntegrationCredentialService::findActiveCredential()
        // already establishes (see WebhookConnectionResolverService's
        // identical use for webhook-signing-secret resolution) — reused
        // directly here rather than reimplemented.
        //
        // The existence check below scopes this to connections that have
        // actually had OAuth credentials provisioned at some point: a
        // provider that never requires OAuth credentials at all (e.g.
        // AuthMethod::None/ApiKey-only) legitimately has zero
        // integration_credentials rows of either OAuth type and must not
        // be denied a sync it was always going to run without one.
        $hasProvisionedOauthCredential = IntegrationCredential::query()
            ->where('firm_integration_id', $connection->id)
            ->whereIn('credential_type', [
                CredentialType::OauthAccessToken->value,
                CredentialType::OauthRefreshToken->value,
            ])
            ->exists();

        if ($hasProvisionedOauthCredential
            && $credentials->findActiveCredential($connection, CredentialType::OauthAccessToken) === null) {
            // Fail safe: no provider call is ever attempted, the cursor
            // is left un-advanced (markFailed(), never advance()), and
            // the sanitized category reused here is 8E's own closed
            // taxonomy value for exactly this class of failure —
            // SanitizedProviderHttpException::CATEGORY_AUTHENTICATION_FAILED
            // — formatted identically to the existing pull_failed
            // summary this method already writes from the catch block
            // below.
            $runs->transitionStatus(
                $run,
                SyncRunStatus::Failed,
                'pull_failed: '.SanitizedProviderHttpException::CATEGORY_AUTHENTICATION_FAILED
            );
            $cursors->markFailed($cursor->id);

            $healthState->recordCredentialError(
                $connection->id,
                $connection->firm_id,
                new SanitizedHealthDiagnostic(
                    SanitizedHealthDiagnostic::CATEGORY_CREDENTIAL_ERROR,
                    SanitizedHealthDiagnostic::OPERATION_PULL_SYNC,
                ),
            );

            return;
        }

        $pageCursor = $cursor->cursor_value;
        $itemsTotal = 0;
        $itemsSucceeded = 0;
        $itemsFailed = 0;
        $itemsSkipped = 0;
        $sawBlockingFailure = false;
        $sanitizedErrorSummary = null;

        do {
            try {
                $page = $httpClient->execute(fn () => $provider->pull([], $this->resourceType, $pageCursor), 'pull');
            } catch (SanitizedProviderHttpException $e) {
                $sanitizedErrorSummary = "pull_failed: {$e->category()}";
                $sawBlockingFailure = true;
                break;
            }

            $batchBlocked = false;

            foreach (($page['items'] ?? []) as $externalItem) {
                $itemsTotal++;

                $externalId = (string) ($externalItem['external_id'] ?? '');
                $versionToken = $externalItem['version_token'] ?? null;

                $mapping = IntegrationExternalMapping::query()
                    ->where('firm_integration_id', $connection->id)
                    ->where('resource_type', $this->resourceType)
                    ->where('external_id', $externalId)
                    ->whereNull('tombstoned_at')
                    ->first();

                if ($mapping === null) {
                    // No FirmsBase-side local record exists for this
                    // external object yet. Creating one is resource-
                    // type-specific business logic this provider-neutral
                    // framework layer does not own (no generic hook
                    // exists in this codebase for it yet) — recorded
                    // Skipped, never a silently-invented local record.
                    $status = SyncItemStatus::Skipped;
                } elseif ($mapping->external_version_token !== null && $mapping->external_version_token !== $versionToken) {
                    // The remote object has moved since we last saw it —
                    // explicit conflict, never a silent overwrite.
                    $conflicts->recordDetection(
                        $connection,
                        $this->resourceType,
                        (string) ($mapping->local_type ?? 'unknown'),
                        (int) ($mapping->local_id ?? 0),
                        'remote_version_changed',
                        externalMappingId: $mapping->id,
                        localVersionToken: $mapping->local_version_token,
                        externalVersionToken: $versionToken,
                    );
                    $status = SyncItemStatus::Skipped;
                } else {
                    $mappings->refreshVersionTokens($mapping, $versionToken, $mapping->local_version_token);
                    $status = SyncItemStatus::Succeeded;
                }

                $item = $items->recordAttempt(
                    $connection->firm_id,
                    $run->id,
                    $this->resourceType,
                    $mapping?->local_type,
                    $mapping?->local_id,
                    $externalId === '' ? null : $externalId,
                    $status,
                    payloadHash: hash('sha256', json_encode($externalItem, JSON_THROW_ON_ERROR)),
                );

                match ($item->status) {
                    SyncItemStatus::Succeeded => $itemsSucceeded++,
                    SyncItemStatus::Skipped => $itemsSkipped++,
                    default => $itemsFailed++,
                };

                if ($item->blocksCursorAdvancement()) {
                    $batchBlocked = true;
                }
            }

            $nextCursor = $page['next_cursor'] ?? null;

            if (! $batchBlocked) {
                $cursor = $cursors->advance($cursor->id, $cursor->cursor_version, $nextCursor);
            } else {
                $sawBlockingFailure = true;
                break;
            }

            $pageCursor = $nextCursor;
        } while ($pageCursor !== null && ! $this->cancellationRequested($run));

        $terminalStatus = $sawBlockingFailure
            ? ($itemsSucceeded > 0 ? SyncRunStatus::PartialFailure : SyncRunStatus::Failed)
            : $runs->determineTerminalStatus($itemsTotal, $itemsSucceeded, $itemsFailed);

        $runs->transitionStatus($run, $terminalStatus, $sanitizedErrorSummary);

        if ($sawBlockingFailure) {
            $cursors->markFailed($cursor->id);
        }
    }

    private function cancellationRequested(IntegrationSyncRun $run): bool
    {
        return $run->fresh()?->cancel_requested_at !== null;
    }
}
