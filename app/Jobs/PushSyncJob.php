<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Integrations\Billing\ProviderOperationAttemptService;
use App\Integrations\Contracts\SupportsPushSyncContract;
use App\Integrations\Core\ProviderRegistry;
use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Enums\ProviderOperationAttemptState;
use App\Integrations\Enums\ProviderOperationClaimDecision;
use App\Integrations\Enums\SyncDirection;
use App\Integrations\Enums\SyncItemStatus;
use App\Integrations\Enums\SyncRunStatus;
use App\Integrations\Enums\SyncTriggerSource;
use App\Integrations\Exceptions\ProviderOperationRequiresReconciliationException;
use App\Integrations\Exceptions\SanitizedProviderHttpException;
use App\Integrations\Exceptions\SyncRunAlreadyInProgressException;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationExternalMapping;
use App\Integrations\Models\IntegrationSyncRun;
use App\Integrations\Models\ProviderOperationAttempt;
use App\Integrations\Services\IntegrationConflictService;
use App\Integrations\Services\IntegrationExternalMappingService;
use App\Integrations\Services\SyncItemService;
use App\Integrations\Services\SyncRunService;
use App\Integrations\Support\OutboundProviderHttpClient;
use App\Services\WebhookRetryPolicyService;
use App\Support\TenantAwareJobContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * PushSyncJob — Checkpoint 8 (agent-8c-sync-job-design.md §1/§8-§10;
 * agent-8h-architecture-security-review.md §2 item 16). $tries = 1 is
 * deliberate, not an oversight: the idempotency key below is
 * deterministic over LOCAL STATE (connection, resource identity,
 * local_version_token), so a re-dispatch is inherently safe even if
 * queue-level retries were enabled — they stay disabled so "how many
 * times has this local-record-at-this-version been attempted" has
 * exactly one source of truth, IntegrationSyncItem.attempt_count, never
 * a second competing counter on the queue side.
 *
 * ---------------------------------------------------------------------
 * CHECKPOINT 8.2 (§A-push) — NO TRANSACTION AND NO ROW LOCK ACROSS A
 * PROVIDER CALL, WITH A DURABLE AT-MOST-ONCE GATE
 * ---------------------------------------------------------------------
 *
 * WHAT WAS WRONG. This job used to run its whole body — the outbound
 * push included — inside ONE `runInFirmContext()` transaction, while
 * holding `->lockForUpdate()` on its own `firm_integrations` row. Same
 * two defects `PullSyncJob` and `RenewGraphSubscriptionJob` already had
 * fixed for their own provider calls (CP8.2 §A6/§A7):
 *
 *   1. A cross-session durable write referencing this connection (or
 *      this job's own eventual `provider_operation_attempts` row) had
 *      to wait for a transaction that could not commit until the
 *      provider answered — the Checkpoint 8.1 deadlock shape.
 *   2. SUCCESS-THEN-ROLLBACK. The provider genuinely accepts the push,
 *      then anything after it throws, and the transaction rolls back.
 *      Nothing local records that the push happened, so a later retry
 *      of the identical (connection, resource, local_version_token)
 *      pushes AGAIN — for a provider whose own idempotency-key handling
 *      is imperfect or absent, a duplicate remote object.
 *
 * No provider that PushSyncJob calls implements
 * `RequiresBillableCallPipelineContract` (only Plaid does, and Plaid
 * never implements `SupportsPushSyncContract`), so this call never went
 * through `ProviderBillableCallPipeline` and had no at-most-once
 * protection at all before this fix — exactly `RenewGraphSubscriptionJob`'s
 * own non-pipeline gap, closed here the identical way: a direct
 * `ProviderOperationAttemptService` gate around the call.
 *
 * THE PHASING NOW.
 *
 *   CLAIM   `claimPushOperation()` — ONE short transaction. Re-reads the
 *           connection fresh (still never trusting the payload, just
 *           without a lock), starts or resumes the run, rejects a stale
 *           local version and an unsupported provider exactly as before,
 *           and computes the deterministic idempotency key. Committed
 *           before any request leaves.
 *   CALL    `callGatedPush()` — `runInFirmContextWithoutTransaction()`.
 *           Tenant context is session-scoped (no transaction, no row
 *           lock) so the durable gate's own writes (on the independent
 *           `pgsql_audit` connection) and the outbound request both run
 *           with nothing held over the network window. The gate is
 *           claimed, `attempt_started` is recorded BEFORE the request
 *           leaves, and the outcome is recorded immediately after.
 *   APPLY   `applyPushResult()` — one short transaction, nested inside
 *           the session-scoped phase, for the local mapping/item/run
 *           write.
 *   RECOVER Built into the gate's own state machine
 *           (`ProviderOperationAttemptService`): a provider success
 *           followed by a local apply failure resumes from durable
 *           evidence rather than re-pushing; an ambiguous provider
 *           outcome (timeout, connection reset, unknown/malformed
 *           response) never auto-retries and instead demands an
 *           explicit reconciliation; a definite provider rejection
 *           stays retryable exactly as it always has.
 *
 * WHY THE RUN-STATUS TRANSITIONS ARE NOW GUARDED. Removing the
 * connection lock means two concurrent dispatches for the SAME
 * (connection, resource_type) scope can both reach `claimPushOperation()`
 * before either finishes — `SyncRunService::startRun()`'s own
 * "AlreadyInProgress" exception hands the SECOND caller the SAME run
 * row the FIRST is using, exactly as designed. Blindly transitioning
 * that shared row to `Running` (or to a terminal status) a second time
 * would throw — `SyncRunStatus`'s own transition table has no entry for
 * "running -> running" or for transitioning out of any terminal status
 * at all. This was structurally impossible before (the lock serialized
 * every dispatch for one connection), so it is a genuinely NEW race
 * this fix must close, not a pre-existing gap: every status transition
 * below is guarded by the run's own current status first. The true
 * single-winner primitive for the PROVIDER CALL itself is the durable
 * gate, not this bookkeeping — a losing concurrent claim simply leaves
 * the run's terminal transition to whichever worker actually owns the
 * gate.
 */
final class PushSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TenantAwareJobContext;

    public int $tries = 1;

    private const AMBIGUOUS_CATEGORIES = [
        SanitizedProviderHttpException::CATEGORY_TIMEOUT,
        SanitizedProviderHttpException::CATEGORY_NETWORK_ERROR,
        SanitizedProviderHttpException::CATEGORY_CONNECTION_UNAVAILABLE,
        SanitizedProviderHttpException::CATEGORY_UNKNOWN,
        SanitizedProviderHttpException::CATEGORY_MALFORMED_RESPONSE,
    ];

    private const TERMINAL_RUN_STATUSES = [
        SyncRunStatus::Succeeded,
        SyncRunStatus::PartialFailure,
        SyncRunStatus::Failed,
        SyncRunStatus::Cancelled,
    ];

    public function __construct(
        public readonly int $firmIntegrationId,
        public readonly int $firmId,
        public readonly string $resourceType,
        public readonly string $localType,
        public readonly int $localId,
        public readonly string $localVersionToken,
        public readonly ?int $triggeringWebhookEventId = null,
        public readonly ?int $retriedRunId = null,
        // Checkpoint 12 addition (frozen-design-post-security-review.md
        // §2 F2): additive, OPTIONAL, trailing nullable param — every
        // existing caller that omits it is completely unaffected and
        // preserves today's exact behavior (provider->push() continues
        // to receive [] as its context argument). Exists so a test
        // harness (or a future real caller) can drive knobs the
        // provider's push() reads out of its $context parameter (e.g.
        // TestProvider's idempotency_key-honoring knob) without this
        // job inventing any provider-specific behavior of its own.
        //
        // Post-checkpoint-12 fix (JobConstructorsCarryOnlyScalarSecretSafeTypesTest):
        // declared ?string, not ?array — every ShouldQueue constructor
        // parameter in this codebase must be scalar/enum/DateTimeInterface
        // so Laravel never serializes an array into the queue payload.
        // Callers now pass a JSON-encoded string; decoded back to an
        // array in handle() below before use.
        public readonly ?string $providerContext = null,
    ) {}

    public function handle(
        SyncRunService $runs,
        SyncItemService $items,
        IntegrationExternalMappingService $mappings,
        IntegrationConflictService $conflicts,
        ProviderRegistry $registry,
        OutboundProviderHttpClient $httpClient,
    ): void {
        // PHASE 1 — CLAIM. One short transaction, committed before any
        // provider request can leave this process. Returns the ids the
        // later phase re-reads from, never a live model carried across
        // the transaction boundary.
        $claim = $this->runInFirmContext($this->firmId, fn () => $this->claimPushOperation($runs, $items, $conflicts, $registry));

        if ($claim === null) {
            return;
        }

        // PHASE 2/3 — PROVIDER CALL (no transaction, no row lock) with
        // the local apply transaction nested inside. Tenant context is
        // session-scoped here so the durable gate's own writes (on the
        // independent connection) and this job's local writes both work
        // under RLS while nothing is held across the network window.
        $this->runInFirmContextWithoutTransaction($this->firmId, function () use ($claim, $runs, $items, $mappings, $registry, $httpClient) {
            $connection = FirmIntegration::query()
                ->where('id', $this->firmIntegrationId)
                ->where('firm_id', $this->firmId)
                ->firstOrFail();

            $provider = $registry->get(ProviderKey::from($claim['provider_code']));

            $this->callGatedPush($claim, $connection, $provider, $runs, $items, $mappings, $httpClient);
        });
    }

    /**
     * PHASE 1 — every check that can end this push before a single
     * provider request is made, in ONE transaction. Returns null for
     * every silent no-op the original method had (inactive connection,
     * stale local version, unsupported provider, or a run another
     * concurrent claim already finalized).
     *
     * @return array{run_id: int, existing_mapping_id: ?int, provider_code: string, idempotency_key: string}|null
     */
    private function claimPushOperation(
        SyncRunService $runs,
        SyncItemService $items,
        IntegrationConflictService $conflicts,
        ProviderRegistry $registry,
    ): ?array {
        $connection = FirmIntegration::query()
            ->where('id', $this->firmIntegrationId)
            ->where('firm_id', $this->firmId)
            ->firstOrFail();

        if ($connection->status !== ConnectionStatus::Active) {
            return null;
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

        if (in_array($run->status, self::TERMINAL_RUN_STATUSES, true)) {
            // A concurrent claim for this exact scope already finalized
            // this run between its own startRun() call and this check —
            // nothing left for this claim to do. See this class's
            // docblock for why this guard is new.
            return null;
        }

        if ($run->status !== SyncRunStatus::Running) {
            $run = $runs->transitionStatus($run, SyncRunStatus::Running);
        }

        $existingMapping = IntegrationExternalMapping::query()
            ->where('firm_integration_id', $connection->id)
            ->where('resource_type', $this->resourceType)
            ->where('local_type', $this->localType)
            ->where('local_id', $this->localId)
            ->whereNull('tombstoned_at')
            ->first();

        // Requirement 3 (agent-8c §8.3): reject a stale local version
        // rather than pushing anyway — the mapping's last-known
        // local_version_token disagreeing with what THIS job is about
        // to push means something else has moved the local record since
        // the mapping was last updated.
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

            return null;
        }

        $providerCode = $connection->integrationProvider->code;
        $provider = $registry->get(ProviderKey::from($providerCode));

        if (! $provider instanceof SupportsPushSyncContract) {
            $items->recordAttempt(
                $connection->firm_id, $run->id, $this->resourceType, $this->localType, $this->localId,
                $existingMapping?->external_id, SyncItemStatus::FailedPermanent,
                lastError: 'provider_does_not_support_push',
            );
            $runs->transitionStatus($run, SyncRunStatus::Failed, 'provider_does_not_support_push');

            return null;
        }

        // Deterministic idempotency key (agent-8c §8.2) — over
        // (connection, resource identity, local version), so a retried
        // dispatch of the SAME local-record-at-the-same-version
        // produces the SAME key, while a legitimate subsequent push of a
        // CHANGED local record produces a NEW one. This is also the
        // logical operation key the durable gate below claims against —
        // one deterministic identity, never two.
        $idempotencyKey = hash(
            'sha256',
            "{$connection->id}:{$this->resourceType}:{$this->localType}:{$this->localId}:{$this->localVersionToken}",
        );

        return [
            'run_id' => (int) $run->id,
            'existing_mapping_id' => $existingMapping?->id,
            'provider_code' => $providerCode,
            'idempotency_key' => $idempotencyKey,
        ];
    }

    /**
     * PHASE 2/3. The durable at-most-once gate for a provider whose call
     * does NOT go through `ProviderBillableCallPipeline` — i.e. every
     * real push. Same phases `RenewGraphSubscriptionJob::callGatedProviderOperation()`
     * applies to the non-pipeline webhook-renewal path, applied here to
     * push. No transaction is open and no row lock is held while a
     * request is in flight.
     */
    private function callGatedPush(
        array $claim,
        FirmIntegration $connection,
        object $provider,
        SyncRunService $runs,
        SyncItemService $items,
        IntegrationExternalMappingService $mappings,
        OutboundProviderHttpClient $httpClient,
    ): void {
        $run = IntegrationSyncRun::query()
            ->where('id', $claim['run_id'])
            ->where('firm_integration_id', $connection->id)
            ->firstOrFail();

        $existingMapping = $claim['existing_mapping_id'] !== null
            ? IntegrationExternalMapping::query()->where('id', $claim['existing_mapping_id'])->first()
            : null;

        $attempts = app(ProviderOperationAttemptService::class);
        $logicalOperationKey = 'push_sync:'.$claim['idempotency_key'];

        $operationClaim = $attempts->claim(
            logicalOperationKey: $logicalOperationKey,
            providerKey: $provider->key()->value,
            firmId: $this->firmId,
            firmIntegrationId: (int) $connection->id,
            operationType: 'push_sync',
        );

        if (! $operationClaim->maySendProviderRequest()) {
            if ($operationClaim->decision === ProviderOperationClaimDecision::ReconciliationRequired) {
                $this->runInFirmContext($this->firmId, function () use ($connection, $run, $existingMapping, $items, $runs) {
                    if (in_array($run->fresh()?->status, self::TERMINAL_RUN_STATUSES, true)) {
                        return;
                    }

                    $items->recordAttempt(
                        $connection->firm_id, $run->id, $this->resourceType, $this->localType, $this->localId,
                        $existingMapping?->external_id, SyncItemStatus::FailedPermanent,
                        lastError: 'push_failed: reconciliation_required',
                    );
                    $runs->transitionStatus($run, SyncRunStatus::Failed, 'push_failed: reconciliation_required');
                });

                throw new ProviderOperationRequiresReconciliationException(
                    $operationClaim->attempt->logical_operation_key,
                    $operationClaim->attempt->attempt_state->value,
                    $operationClaim->attempt->reconciliation_reason,
                );
            }

            if ($operationClaim->decision === ProviderOperationClaimDecision::ResumeLocalProcessing) {
                $this->resumeFromRecordedEvidence(
                    $operationClaim->attempt, $operationClaim->ownerTokenOrFail(),
                    $connection, $run, $existingMapping, $mappings, $items, $runs, $attempts,
                );

                return;
            }

            if ($operationClaim->decision === ProviderOperationClaimDecision::AlreadyComplete) {
                $this->applyAlreadyCompleteOutcome($connection, $run, $existingMapping, $items, $runs);

                return;
            }

            // InFlightElsewhere — another worker currently owns this
            // exact logical operation. This worker must not send and
            // must not touch the run's status; the owning worker's own
            // cycle finalizes it.
            return;
        }

        $ownerToken = $operationClaim->ownerTokenOrFail();
        $attempt = $attempts->markAttemptStarted($operationClaim->attempt, $ownerToken, $logicalOperationKey);

        $payload = [
            'local_type' => $this->localType,
            'local_id' => $this->localId,
            'idempotency_key' => $claim['idempotency_key'],
            'existing_external_id' => $existingMapping?->external_id,
        ];

        // Checkpoint 2 (FirmsVault Live Integrations, Microsoft 365
        // provider) fix: a real provider's push() must reach
        // ProviderRequestExecutor::send(), which requires a full
        // FirmIntegration object — the pre-Checkpoint-2 $providerContext
        // (test-only, JSON-encoded scalar bag) never carried one. Merged
        // in unconditionally, after decoding, so any caller-supplied
        // test keys (including '__simulate_failure', handled separately
        // below) are preserved and 'connection' is always present.
        $providerContext = $this->decodeProviderContext();
        $providerContext['connection'] = $connection;

        if (array_key_exists('__simulate_failure', $providerContext)) {
            $payload['__simulate_failure'] = $providerContext['__simulate_failure'];
        }

        try {
            $result = $httpClient->execute(fn () => $provider->push($providerContext, $this->resourceType, $payload), 'push');
        } catch (SanitizedProviderHttpException $e) {
            if (in_array($e->category(), self::AMBIGUOUS_CATEGORIES, true)) {
                // We genuinely cannot tell whether the provider accepted
                // the push. Never auto-retried from here on — the NEXT
                // claim attempt for this same logical operation will see
                // ReconciliationRequired and refuse to send again.
                $uncertain = $attempts->recordProviderOutcomeUncertain($attempt, $ownerToken, 'provider_outcome_uncertain:'.$e->category());
                $attempts->markReconciliationRequired($uncertain, 'uncertain_provider_outcome:'.$e->category());
            } else {
                // A definite provider refusal BEFORE any work was done —
                // positive knowledge that nothing was pushed, so this
                // logical operation stays retryable.
                $attempts->recordProviderRejected($attempt, $ownerToken, 'provider_rejected:'.$e->category(), $e->category());
            }

            // Existing terminal/non-terminal SyncItem/Run bookkeeping,
            // UNCHANGED from before this checkpoint.
            $this->recordPushFailure($e, $connection, $run, $existingMapping, $items, $runs);

            return;
        }

        $attempts->recordProviderSucceeded(
            $attempt,
            $ownerToken,
            providerOutcome: 'success',
            redactedResultMetadata: $this->recoveryEvidenceFor($result),
        );

        // The local write is its own short transaction; a failure in it
        // is recorded as a local failure with the provider-success
        // evidence preserved, so a retry resumes instead of pushing
        // again.
        try {
            $this->runInFirmContext($this->firmId, fn () => $this->applyPushResult(
                $result, $run, $existingMapping, $connection, $mappings, $items, $runs,
            ));
        } catch (Throwable $localFailure) {
            $attempts->markLocalProcessingFailed($attempt, $ownerToken, 'push_local_apply_threw', $attempt->local_processing_state);

            throw $localFailure;
        }

        $attempts->markLocalProcessingComplete($attempt, $ownerToken, $attempt->local_processing_state);
    }

    /**
     * RECOVER — resume a push the provider already performed, using the
     * durable evidence recorded before this side failed (or before a
     * different worker's own local apply failed). Never calls the
     * provider again.
     */
    private function resumeFromRecordedEvidence(
        ProviderOperationAttempt $attempt,
        string $ownerToken,
        FirmIntegration $connection,
        IntegrationSyncRun $run,
        ?IntegrationExternalMapping $existingMapping,
        IntegrationExternalMappingService $mappings,
        SyncItemService $items,
        SyncRunService $runs,
        ProviderOperationAttemptService $attempts,
    ): void {
        $evidence = json_decode((string) $attempt->redacted_result_metadata, true);

        $externalId = is_array($evidence) ? ($evidence['external_id'] ?? null) : null;
        $externalVersionToken = is_array($evidence) ? ($evidence['version_token'] ?? null) : null;

        if (! is_string($externalId) || $externalId === '') {
            $failed = $attempt->attempt_state === ProviderOperationAttemptState::LocalProcessingFailed
                ? $attempt
                : $attempts->markLocalProcessingFailed($attempt, $ownerToken, 'push_evidence_unusable', $attempt->local_processing_state);

            $attempts->markReconciliationRequired($failed, 'push_succeeded_but_evidence_unusable');

            $this->runInFirmContext($this->firmId, function () use ($connection, $run, $existingMapping, $items, $runs) {
                if (in_array($run->fresh()?->status, self::TERMINAL_RUN_STATUSES, true)) {
                    return;
                }

                $items->recordAttempt(
                    $connection->firm_id, $run->id, $this->resourceType, $this->localType, $this->localId,
                    $existingMapping?->external_id, SyncItemStatus::FailedPermanent,
                    lastError: 'push_failed: evidence_unusable',
                );
                $runs->transitionStatus($run, SyncRunStatus::Failed, 'push_failed: evidence_unusable');
            });

            throw new ProviderOperationRequiresReconciliationException(
                $attempt->logical_operation_key,
                ProviderOperationAttemptState::ReconciliationRequired->value,
                'push_succeeded_but_evidence_unusable',
            );
        }

        try {
            $this->runInFirmContext($this->firmId, fn () => $this->applyPushResult(
                ['external_id' => $externalId, 'version_token' => $externalVersionToken],
                $run, $existingMapping, $connection, $mappings, $items, $runs,
            ));
        } catch (Throwable $localFailure) {
            $attempts->markLocalProcessingFailed($attempt, $ownerToken, 'push_local_apply_threw', $attempt->local_processing_state);

            throw $localFailure;
        }

        $attempts->markLocalProcessingComplete($attempt, $ownerToken, $attempt->local_processing_state);
    }

    /**
     * A duplicate delivery of an already-fully-settled logical
     * operation. Idempotent no-op on the mapping (an earlier, already
     * finalized attempt owns its current state — never re-derived or
     * invented here); only this run's own bookkeeping is finalized.
     */
    private function applyAlreadyCompleteOutcome(
        FirmIntegration $connection,
        IntegrationSyncRun $run,
        ?IntegrationExternalMapping $existingMapping,
        SyncItemService $items,
        SyncRunService $runs,
    ): void {
        $this->runInFirmContext($this->firmId, function () use ($connection, $run, $existingMapping, $items, $runs) {
            if (in_array($run->fresh()?->status, self::TERMINAL_RUN_STATUSES, true)) {
                return;
            }

            $fresh = $existingMapping !== null
                ? IntegrationExternalMapping::query()->where('id', $existingMapping->id)->first()
                : IntegrationExternalMapping::query()
                    ->where('firm_integration_id', $connection->id)
                    ->where('resource_type', $this->resourceType)
                    ->where('local_type', $this->localType)
                    ->where('local_id', $this->localId)
                    ->whereNull('tombstoned_at')
                    ->first();

            $items->recordAttempt(
                $connection->firm_id, $run->id, $this->resourceType, $this->localType, $this->localId,
                $fresh?->external_id, SyncItemStatus::Succeeded,
            );

            $runs->transitionStatus($run, SyncRunStatus::Succeeded);
        });
    }

    /**
     * APPLY — one page's, or one push's, local write in ONE transaction.
     * Nothing here touches the network.
     *
     * Version-guarded: re-locks the mapping fresh and refuses to
     * overwrite it if another (newer) push already advanced
     * local_version_token past what THIS attempt observed at claim time
     * — the provider call already happened (and is durably recorded as
     * such either way), but applying it here would regress local state
     * backwards. Never overwrites; leaves the newer state alone.
     *
     * @param  array<string, mixed>  $result
     */
    private function applyPushResult(
        array $result,
        IntegrationSyncRun $run,
        ?IntegrationExternalMapping $existingMapping,
        FirmIntegration $connection,
        IntegrationExternalMappingService $mappings,
        SyncItemService $items,
        SyncRunService $runs,
    ): void {
        if (in_array($run->fresh()?->status, self::TERMINAL_RUN_STATUSES, true)) {
            return;
        }

        $externalId = (string) ($result['external_id'] ?? '');
        $externalVersionToken = $result['version_token'] ?? null;

        if ($existingMapping !== null) {
            $fresh = IntegrationExternalMapping::query()->where('id', $existingMapping->id)->lockForUpdate()->first();

            if ($fresh === null) {
                // Tombstoned/deleted between claim and apply — nothing
                // legitimate left to overwrite.
                $items->recordAttempt(
                    $connection->firm_id, $run->id, $this->resourceType, $this->localType, $this->localId,
                    $existingMapping->external_id, SyncItemStatus::Skipped,
                    lastError: 'push_apply_skipped: mapping_no_longer_live',
                );
                $runs->transitionStatus($run, SyncRunStatus::PartialFailure, 'push_apply_skipped: mapping_no_longer_live');

                return;
            }

            $refreshed = $mappings->refreshVersionTokensIfCurrent(
                $fresh, $existingMapping->local_version_token, $externalVersionToken, $this->localVersionToken,
            );

            if ($refreshed === null) {
                $items->recordAttempt(
                    $connection->firm_id, $run->id, $this->resourceType, $this->localType, $this->localId,
                    $fresh->external_id, SyncItemStatus::Skipped,
                    lastError: 'push_apply_skipped: superseded_by_newer_local_version',
                );
                $runs->transitionStatus($run, SyncRunStatus::PartialFailure, 'push_apply_skipped: superseded_by_newer_local_version');

                return;
            }
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
    }

    /**
     * Existing terminal/non-terminal classification, UNCHANGED from
     * before this checkpoint: Checkpoint 1 (FirmsVault Live Integrations,
     * checkpoint1-design-http-ratelimit-usage.md §4.4) — a merely
     * rate-limited (or otherwise transient) connection must not be
     * permanently failed the same way a genuinely terminal failure is.
     */
    private function recordPushFailure(
        SanitizedProviderHttpException $e,
        FirmIntegration $connection,
        IntegrationSyncRun $run,
        ?IntegrationExternalMapping $existingMapping,
        SyncItemService $items,
        SyncRunService $runs,
    ): void {
        $this->runInFirmContext($this->firmId, function () use ($e, $connection, $run, $existingMapping, $items, $runs) {
            if (in_array($run->fresh()?->status, self::TERMINAL_RUN_STATUSES, true)) {
                return;
            }

            if (in_array($e->category(), WebhookRetryPolicyService::TERMINAL_CATEGORIES, true)) {
                $items->recordAttempt(
                    $connection->firm_id, $run->id, $this->resourceType, $this->localType, $this->localId,
                    $existingMapping?->external_id, SyncItemStatus::FailedPermanent,
                    lastError: "push_failed: {$e->category()}",
                );
                $runs->transitionStatus($run, SyncRunStatus::Failed, "push_failed: {$e->category()}");
            } else {
                $items->recordAttempt(
                    $connection->firm_id, $run->id, $this->resourceType, $this->localType, $this->localId,
                    $existingMapping?->external_id, SyncItemStatus::FailedRetryable,
                    lastError: "push_failed: {$e->category()}",
                    nextAttemptAt: now()->addSeconds($e->retryAfterSeconds() ?? 60)->toDateTimeString(),
                );
                $runs->transitionStatus($run, SyncRunStatus::PartialFailure, "push_failed: {$e->category()}");
            }
        });
    }

    /**
     * The only push-response fields kept for recovery: the external id
     * and version token, both already stored unencrypted in this
     * system's own `integration_external_mappings` table for exactly
     * this purpose (§A8). No token, no credential, no request body, no
     * other response field. Returns null when the response carries no
     * usable external id, so nothing is ever half-recorded.
     */
    private function recoveryEvidenceFor(mixed $response): ?string
    {
        if (! is_array($response)) {
            return null;
        }

        $externalId = $response['external_id'] ?? null;
        $versionToken = $response['version_token'] ?? null;

        if (! is_string($externalId) || $externalId === '') {
            return null;
        }

        return json_encode([
            'external_id' => $externalId,
            'version_token' => is_string($versionToken) ? $versionToken : null,
        ]) ?: null;
    }

    /**
     * Decode the JSON-encoded providerContext string back into an array
     * — see this job's constructor docblock (§ post-checkpoint-12 fix)
     * for why the constructor-declared type is ?string rather than
     * ?array. This parameter is only ever set by test code today (frozen
     * design), so a defensive is_array() fallback to [] on malformed
     * input is sufficient — no need to throw.
     *
     * @return array<string, mixed>
     */
    private function decodeProviderContext(): array
    {
        if ($this->providerContext === null) {
            return [];
        }

        $decoded = json_decode($this->providerContext, true);

        return is_array($decoded) ? $decoded : [];
    }
}
