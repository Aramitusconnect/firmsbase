<?php

declare(strict_types=1);

namespace App\Integrations\Jobs;

use App\Integrations\Billing\ProviderBillableCallPipeline;
use App\Integrations\Billing\ProviderBillableCallResult;
use App\Integrations\Billing\ProviderOperationAttemptService;
use App\Integrations\Contracts\RequiresBillableCallPipelineContract;
use App\Integrations\Contracts\SupportsWebhooksContract;
use App\Integrations\Core\ProviderRegistry;
use App\Integrations\Data\SanitizedHealthDiagnostic;
use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Enums\ProviderOperationAttemptState;
use App\Integrations\Enums\ProviderOperationClaimDecision;
use App\Integrations\Enums\ProviderWebhookSubscriptionStatus;
use App\Integrations\Enums\ResourceType;
use App\Integrations\Enums\SyncDirection;
use App\Integrations\Exceptions\ProviderOperationRequiresReconciliationException;
use App\Integrations\Exceptions\SanitizedProviderHttpException;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationProviderWebhookSubscription;
use App\Integrations\Models\ProviderOperationAttempt;
use App\Integrations\Services\HealthStateService;
use App\Integrations\Support\OutboundProviderHttpClient;
use App\Integrations\Support\ProviderEnvironmentResolver;
use App\Models\Firm;
use App\Support\TenantAwareJobContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

/**
 * RenewGraphSubscriptionJob — FirmsVault Live Integrations, Checkpoint 2
 * (checkpoint2-design-sync-webhooks.md §3.3; checkpoint2-combined-design.md
 * §2 P-19). Structurally mirrors
 * App\Integrations\Jobs\RefreshIntegrationToken exactly (scalar-FK-only
 * constructor, TenantAwareJobContext, backoff() array, category-aware
 * failed() hook) — but is PROACTIVE/schedule-driven
 * (dispatched by App\Console\Commands\RenewProviderWebhookSubscriptionsCommand),
 * never reactive. Graph subscriptions have no automatic renewal; a
 * Microsoft connection that is healthy in every other respect will
 * still silently stop receiving webhooks the moment its subscription
 * expires, with no other code path that would notice.
 *
 * Constructor carries three bare, non-secret integer FKs ONLY — never a
 * token, never a credential ID, never a hydrated model. $firmId is
 * included deliberately, not a violation of "connection/subscription ID
 * only": both firm_integrations and integration_provider_webhook_subscriptions
 * are FORCE-RLS'd, so a fresh worker process with zero context cannot
 * safely read either to discover which firm owns them.
 *
 * Re-verify-fresh-state-first discipline (design §3.3, mirrors
 * RefreshIntegrationToken's own "Gate 1" doc comment): re-resolves BOTH
 * the connection AND the subscription row fresh from the database as
 * its first action, silently no-oping (never throwing, never counted
 * against $tries) if the connection is no longer Active or the
 * subscription row is no longer `active` — a connection disconnected,
 * or a subscription already superseded/failed, between schedule-time
 * and job-execution-time must never be renewed.
 *
 * Provider-agnostic despite the class name (kept per the design
 * document's own naming, which frames this as "Microsoft specifically"
 * for now): resolves the provider polymorphically via ProviderRegistry
 * + instanceof SupportsWebhooksContract, never branching on provider
 * identity — the identical shape every other job in this codebase
 * (PullSyncJob, PushSyncJob) already uses. Reused unmodified by a
 * future Google `watch()`-channel adapter, which has the identical
 * "remote subscription with an expiry that must be renewed" shape.
 */
final class RenewGraphSubscriptionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TenantAwareJobContext;

    /**
     * Matches RefreshIntegrationToken's own $tries — same
     * WebhookRetryPolicyService::DEFAULT_RETRY_POLICY['max_attempts']
     * shape reused by convention, not by direct dependency.
     */
    public int $tries = 5;

    public function __construct(
        public readonly int $firmIntegrationId,
        public readonly int $firmId,
        public readonly int $subscriptionId,
    ) {}

    /**
     * Fixed schedule, byte-for-byte identical to RefreshIntegrationToken's
     * own backoff() — Laravel's native mechanism, a fixed array, not a
     * jitter/category-aware computation.
     */
    public function backoff(): array
    {
        return [30, 60, 120, 240];
    }

    /**
     * CHECKPOINT 8.2 (§A7) — PHASED, WITH DURABLE AT-MOST-ONCE FOR EVERY
     * PROVIDER.
     *
     * WHAT WAS WRONG. This method used to run its whole body — the
     * outbound renewal included — inside ONE `runInFirmContext()`
     * transaction, holding `->lockForUpdate()` on the subscription row
     * across the provider call. Two defects:
     *
     *   1. The lock spanned the network window, so a durable
     *      cross-session write referencing the subscription (or its
     *      connection) had to wait for a transaction that could not commit
     *      until the provider answered — the Checkpoint 8.1 deadlock,
     *      again.
     *   2. SUCCESS-THEN-ROLLBACK. The provider genuinely renews the
     *      subscription, then `$subscription->save()` (or anything after
     *      it) throws, and the transaction rolls back. Nothing local
     *      records that the renewal happened, so the retry renews AGAIN.
     *      For Plaid that is a double charge; for Microsoft/Google — which
     *      never went through the billing pipeline at all — it is a
     *      duplicated provider-side subscription, i.e. duplicated inbound
     *      webhook traffic.
     *
     * WHAT IT DOES NOW. Ownership and the record of the send both live in
     * `provider_operation_attempts`, on a database session the caller's
     * transaction cannot roll back (see `ProviderOperationAttemptService`):
     *
     *   CLAIM    `claimRenewalCycle()` — one short transaction, no lock:
     *            re-reads connection and subscription fresh, applies every
     *            Gate-1 check, and computes the renewal-cycle identity.
     *   PROVIDER no transaction, no row lock. The durable gate is claimed,
     *            `attempt_started` is recorded BEFORE the request leaves,
     *            and the outcome is recorded immediately after.
     *   APPLY    one short transaction for the local subscription write.
     *
     * The subscription row lock is not replaced by another lock: the
     * durable claim is a strictly stronger single-winner primitive, since
     * it survives across processes and across a rollback, and a second
     * worker for the same renewal cycle is told `InFlightElsewhere` rather
     * than made to wait.
     *
     * WHY THE GATE IS APPLIED HERE AND NOT ONLY IN THE PIPELINE. Only
     * Plaid implements `RequiresBillableCallPipelineContract`, so only
     * Plaid's call is gated by `ProviderBillableCallPipeline`. The actual
     * Graph/Google renewals this job exists for bypass the pipeline
     * entirely, which is precisely why they need the gate applied
     * directly. Each path is gated exactly once — never twice.
     */
    public function handle(ProviderRegistry $registry, HealthStateService $healthStateService): void
    {
        // PHASE 1 — CLAIM. One short transaction; committed before any
        // request can leave this process.
        $claim = $this->runInFirmContext($this->firmId, fn () => $this->claimRenewalCycle($registry));

        if ($claim === null) {
            return;
        }

        // PHASE 2/3 — provider call with NO transaction and NO row lock,
        // and a short local-apply transaction nested inside.
        $this->runInFirmContextWithoutTransaction($this->firmId, function () use ($claim, $registry, $healthStateService): void {
            $connection = FirmIntegration::query()->where('id', $this->firmIntegrationId)->firstOrFail();

            $subscription = IntegrationProviderWebhookSubscription::query()
                ->where('id', $this->subscriptionId)
                ->where('firm_integration_id', $this->firmIntegrationId)
                ->firstOrFail();

            $provider = $registry->get(ProviderKey::from($claim['provider_code']));

            if (! $provider instanceof SupportsWebhooksContract) {
                return;
            }

            $this->renewOrResubscribe(
                $provider,
                $connection,
                $subscription,
                $claim['renewal_cycle_token'],
                $healthStateService,
            );
        });
    }

    /**
     * PHASE 1. Every "should this renewal happen at all" gate, in one
     * short transaction, with no lock held afterwards. Returns null for
     * every silent no-op the original method had.
     *
     * @return array{provider_code: string, renewal_cycle_token: string}|null
     */
    private function claimRenewalCycle(ProviderRegistry $registry): ?array
    {
        $connection = FirmIntegration::query()
            ->where('id', $this->firmIntegrationId)
            ->first();

        if ($connection === null) {
            // Connection deleted since dispatch (e.g. cascade-deleted
            // with its firm) — nothing to do, no error, never
            // counted against $tries.
            return null;
        }

        if ((int) $connection->firm_id !== $this->firmId) {
            // Should be structurally impossible once real tenant
            // context is active (RLS would already exclude the row)
            // — kept as an explicit, cheap defense-in-depth
            // assertion, never trusting a single layer alone.
            throw new RuntimeException(
                "Connection {$this->firmIntegrationId} does not belong to firm {$this->firmId}."
            );
        }

        // Gate 1 — re-resolved fresh. A connection disconnected between
        // schedule-time and execution-time must silently no-op, never
        // renew a subscription for a connection that no longer has usable
        // credentials.
        if ($connection->status !== ConnectionStatus::Active) {
            return null;
        }

        // CHECKPOINT 8.2 (§A7): read fresh, but WITHOUT ->lockForUpdate().
        // The lock used to be held across the provider call; single-winner
        // ownership is now the durable claim in renewOrResubscribe().
        $subscription = IntegrationProviderWebhookSubscription::query()
            ->where('id', $this->subscriptionId)
            ->where('firm_integration_id', $this->firmIntegrationId)
            ->first();

        // Gate 1's second half — a subscription already superseded
        // (renewed by a concurrent tick), already failed, or already
        // removed must never be re-renewed here.
        if ($subscription === null || $subscription->status !== ProviderWebhookSubscriptionStatus::Active) {
            return null;
        }

        $providerCode = $connection->integrationProvider?->code;

        if ($providerCode === null) {
            return null;
        }

        $provider = $registry->get(ProviderKey::from($providerCode));

        if (! $provider instanceof SupportsWebhooksContract) {
            // Defensive: the connection's registered provider no
            // longer (or never did) support webhooks. Nothing this
            // job can meaningfully do — surfacing as a hard failure
            // would just burn retries against a structural mismatch,
            // not a transient condition.
            return null;
        }

        // DETERMINISTIC RENEWAL-CYCLE IDENTITY (double-billing
        // remediation). Both idempotency keys below used to end in
        // `now()->format('YmdHi')`. With $tries = 5 and
        // backoff() = [30, 60, 120, 240], every retry after the first
        // is overwhelmingly likely to land in a DIFFERENT wall-clock
        // minute than the attempt it is retrying — so the key
        // changed, ProviderUsageReservationService::reserve() saw no
        // conflict at all, INSERTed a brand-new reservation, and the
        // pipeline made a second REAL, separately-billed outbound
        // call for one logical renewal (up to five per renewal).
        //
        // The replacement identifies the RENEWAL CYCLE, not the
        // moment of the attempt: the subscription row's CURRENT
        // provider-side identity and expiry, both re-read fresh from
        // the database at the top of every attempt and both rewritten
        // (below) only once a renewal actually SUCCEEDS. So all five
        // attempts at one renewal share a key, while the next genuine
        // renewal of the same subscription — and any other
        // subscription — gets a different one. No new column is
        // needed: `expires_at` already IS the durable "this specific
        // renewal still needs doing" marker. Hashed in the same
        // shape PushSyncJob's own deterministic key already uses.
        $renewalCycleToken = hash('sha256', implode('|', [
            (string) $connection->id,
            (string) $subscription->id,
            (string) ($subscription->provider_subscription_id ?? ''),
            $subscription->expires_at?->toIso8601String() ?? 'no_expiry',
        ]));

        return ['provider_code' => $providerCode, 'renewal_cycle_token' => $renewalCycleToken];
    }

    /**
     * PHASE 2/3. Renews (or, on a genuine 404, re-subscribes) and applies
     * the result locally. No transaction is open and no row lock is held
     * while a request is in flight.
     */
    private function renewOrResubscribe(
        SupportsWebhooksContract $provider,
        FirmIntegration $connection,
        IntegrationProviderWebhookSubscription $subscription,
        string $renewalCycleToken,
        HealthStateService $healthStateService,
    ): void {
        // FirmsVault Live Integrations, Checkpoint 4 cost-control
        // wiring pass (checkpoint4-design-cost-control.md §2.1 call
        // site #4, resolving Finding 1 of checkpoint4-security-review.md).
        // Additive `instanceof` branch only — Microsoft365Provider/
        // GoogleWorkspaceProvider (the only two real
        // SupportsWebhooksContract implementers besides Plaid) do not
        // implement RequiresBillableCallPipelineContract and fall
        // straight through to the direct-call branch.
        $requiresPipeline = $provider instanceof RequiresBillableCallPipelineContract;
        $pipelineFirm = $requiresPipeline ? Firm::query()->findOrFail($this->firmId) : null;
        $pipelineEnvironment = $requiresPipeline ? (new ProviderEnvironmentResolver)->modeFor($provider->key()) : null;
        $pipelineResourceType = ResourceType::tryFrom($subscription->resource_type);

        try {
            if ($requiresPipeline) {
                $renewResult = app(ProviderBillableCallPipeline::class)->execute(
                    providerKey: $provider->key(),
                    connection: $connection,
                    // Anti-tautology (ProviderBillableCallPipeline's
                    // own class docblock, addition #0): $this->firmId
                    // is this job's own independently-dispatched
                    // scalar constructor property, never derived from
                    // $connection->firm.
                    firm: $pipelineFirm,
                    actor: null,
                    product: 'webhook_subscribe',
                    billingOperation: 'renew',
                    environment: $pipelineEnvironment,
                    direction: SyncDirection::Outbound,
                    resourceType: $pipelineResourceType,
                    providerCall: fn () => app(OutboundProviderHttpClient::class)->execute(
                        fn () => $provider->renewSubscription([
                            'connection' => $connection,
                            'subscription' => $subscription,
                        ]),
                        'renewSubscription',
                    ),
                    usageIdempotencyKey: 'provider_webhook_renew:'.$connection->id.':'.$subscription->id.':'.$renewalCycleToken,
                    provider: $provider,
                    requiredContractFqcn: SupportsWebhooksContract::class,
                    redactResultForRecovery: fn (mixed $response) => $this->recoveryEvidenceFor($response),
                    localProcessingState: 'subscription_'.$subscription->id.':cycle_'.$renewalCycleToken,
                );

                // CHECKPOINT 8.2 (§A7): the pipeline's durable gate may
                // have refused to call again because an earlier attempt
                // already renewed successfully. Resume from its recorded
                // evidence instead of re-sending.
                if ($renewResult->outcome->servedWithoutProviderCall() && $renewResult->response === null) {
                    $this->resumeFromDurableEvidence($renewResult, $connection, $subscription, $healthStateService);

                    return;
                }

                $result = $renewResult->response;
            } else {
                $result = $this->callGatedProviderOperation(
                    $provider,
                    $connection,
                    $subscription,
                    'webhook_subscription.renew',
                    'provider_webhook_renew',
                    $renewalCycleToken,
                    fn () => $provider->renewSubscription([
                        'connection' => $connection,
                        'subscription' => $subscription,
                    ]),
                    $healthStateService,
                );

                if ($result === null) {
                    // Already renewed, resumed, or owned by another
                    // worker — every one of those is handled inside.
                    return;
                }
            }
        } catch (SanitizedProviderHttpException $e) {
            // Design §3.3: a genuine 404 (subscription already gone
            // at the provider — deleted after expiry, or never
            // created due to a prior partial failure) should trigger
            // a fresh subscribe() call instead of retrying a
            // renewal against a dead subscription id. Deliberately
            // narrowed to statusCode() === 404, not the whole
            // CATEGORY_PROVIDER_REJECTED bucket (which also covers
            // 5xx) — a transient 5xx does not mean the subscription
            // is actually gone, and treating it as such would create
            // an unnecessary duplicate subscription while Graph is
            // merely erroring transiently. Any other category
            // (including a non-404 CATEGORY_PROVIDER_REJECTED)
            // rethrows unchanged, letting this job's own $tries/
            // backoff() retry it as an ordinary renewal.
            if (! ($e->category() === SanitizedProviderHttpException::CATEGORY_PROVIDER_REJECTED && $e->statusCode() === 404)) {
                throw $e;
            }

            // The 404-fallback subscribe is a SECOND provider call, and
            // therefore its OWN logical operation with its own key prefix
            // — exactly as the two idempotency-key prefixes already
            // expressed. Gating them separately is what lets each be
            // at-most-once without one masking the other.
            if ($requiresPipeline) {
                $subscribeResult = app(ProviderBillableCallPipeline::class)->execute(
                    providerKey: $provider->key(),
                    connection: $connection,
                    firm: $pipelineFirm,
                    actor: null,
                    product: 'webhook_subscribe',
                    billingOperation: 'subscribe',
                    environment: $pipelineEnvironment,
                    direction: SyncDirection::Outbound,
                    resourceType: $pipelineResourceType,
                    providerCall: fn () => app(OutboundProviderHttpClient::class)->execute(
                        fn () => $provider->subscribe([
                            'connection' => $connection,
                            'resource_type' => $subscription->resource_type,
                            'provider_resource' => $subscription->provider_resource,
                            'provider_change_type' => $subscription->provider_change_type,
                        ]),
                        'subscribe',
                    ),
                    // Same deterministic cycle token as the renew
                    // branch above (a distinct key PREFIX keeps
                    // the two billable operations separate): the
                    // 404-fallback subscribe is part of the SAME
                    // logical renewal, so every retry of it must
                    // collapse onto one reservation too.
                    usageIdempotencyKey: 'provider_webhook_subscribe:'.$connection->id.':'.$subscription->resource_type.':'.$renewalCycleToken,
                    provider: $provider,
                    requiredContractFqcn: SupportsWebhooksContract::class,
                    redactResultForRecovery: fn (mixed $response) => $this->recoveryEvidenceFor($response),
                    localProcessingState: 'subscription_'.$subscription->id.':cycle_'.$renewalCycleToken,
                );

                if ($subscribeResult->outcome->servedWithoutProviderCall() && $subscribeResult->response === null) {
                    $this->resumeFromDurableEvidence($subscribeResult, $connection, $subscription, $healthStateService);

                    return;
                }

                $result = $subscribeResult->response;
            } else {
                $result = $this->callGatedProviderOperation(
                    $provider,
                    $connection,
                    $subscription,
                    'webhook_subscription.subscribe',
                    'provider_webhook_subscribe',
                    $renewalCycleToken,
                    fn () => $provider->subscribe([
                        'connection' => $connection,
                        'resource_type' => $subscription->resource_type,
                        'provider_resource' => $subscription->provider_resource,
                        'provider_change_type' => $subscription->provider_change_type,
                    ]),
                    $healthStateService,
                );

                if ($result === null) {
                    return;
                }
            }
        }

        [$providerSubscriptionId, $expiresAt] = $this->extractSubscriptionState($result);

        $this->applySubscriptionState($connection, $subscription, $providerSubscriptionId, $expiresAt, $healthStateService);
    }

    /**
     * The durable at-most-once gate for a provider whose call does NOT go
     * through `ProviderBillableCallPipeline` — i.e. every real Graph or
     * Google webhook renewal. Same four phases the pipeline applies, in
     * the same order, on the same independent connection.
     *
     * Returns the provider's response array, or null when the caller must
     * simply stop: another worker owns this cycle, the work is already
     * finished, or the local state has just been resumed from durable
     * evidence.
     *
     * @param  \Closure(): array<string, mixed>  $providerCall
     * @return array<string, mixed>|null
     */
    private function callGatedProviderOperation(
        SupportsWebhooksContract $provider,
        FirmIntegration $connection,
        IntegrationProviderWebhookSubscription $subscription,
        string $operationType,
        string $keyPrefix,
        string $renewalCycleToken,
        \Closure $providerCall,
        HealthStateService $healthStateService,
    ): ?array {
        $attempts = app(ProviderOperationAttemptService::class);

        $claim = $attempts->claim(
            logicalOperationKey: implode(':', [
                'firm_'.$this->firmId,
                $provider->key()->value,
                $keyPrefix,
                (string) $subscription->id,
                $renewalCycleToken,
            ]),
            providerKey: $provider->key()->value,
            firmId: $this->firmId,
            firmIntegrationId: (int) $connection->id,
            operationType: $operationType,
        );

        if (! $claim->maySendProviderRequest()) {
            if ($claim->decision === ProviderOperationClaimDecision::ReconciliationRequired) {
                throw new ProviderOperationRequiresReconciliationException(
                    $claim->attempt->logical_operation_key,
                    $claim->attempt->attempt_state->value,
                    $claim->attempt->reconciliation_reason,
                );
            }

            if ($claim->decision === ProviderOperationClaimDecision::ResumeLocalProcessing) {
                $this->resumeFromRecordedEvidence(
                    $claim->attempt,
                    $claim->ownerTokenOrFail(),
                    $connection,
                    $subscription,
                    $healthStateService,
                );
            }

            // AlreadyComplete / InFlightElsewhere / resumed — in every
            // case this worker must not call the provider.
            return null;
        }

        $ownerToken = $claim->ownerTokenOrFail();

        // Durably recorded BEFORE the request can leave this process, so a
        // crash in the network window is never mistaken for "never sent".
        $attempt = $attempts->markAttemptStarted($claim->attempt, $ownerToken, $keyPrefix.':'.$renewalCycleToken);

        try {
            $response = app(OutboundProviderHttpClient::class)->execute($providerCall, $operationType);
        } catch (SanitizedProviderHttpException $e) {
            // A definite provider refusal (auth, validation, 404, 5xx
            // rejection) is positive knowledge that no subscription was
            // created, so the cycle stays retryable. An ambiguous outcome
            // (timeout, reset) is NOT: it is escalated, because a renewal
            // that may have succeeded must never be silently repeated.
            if (in_array($e->category(), [
                SanitizedProviderHttpException::CATEGORY_TIMEOUT,
                SanitizedProviderHttpException::CATEGORY_NETWORK_ERROR,
                SanitizedProviderHttpException::CATEGORY_CONNECTION_UNAVAILABLE,
                SanitizedProviderHttpException::CATEGORY_UNKNOWN,
                SanitizedProviderHttpException::CATEGORY_MALFORMED_RESPONSE,
            ], true)) {
                $uncertain = $attempts->recordProviderOutcomeUncertain($attempt, $ownerToken, 'provider_outcome_uncertain:'.$e->category());
                $attempts->markReconciliationRequired($uncertain, 'uncertain_provider_outcome:'.$e->category());
            } else {
                $attempts->recordProviderRejected($attempt, $ownerToken, 'provider_rejected:'.$e->category(), $e->category());
            }

            throw $e;
        } catch (Throwable $e) {
            // Anything unsanitized is, by the same rule the outcome
            // normalizer applies, genuinely ambiguous — never assumed to
            // have failed cleanly.
            $uncertain = $attempts->recordProviderOutcomeUncertain($attempt, $ownerToken, 'provider_outcome_uncertain:unsanitized_throwable');
            $attempts->markReconciliationRequired($uncertain, 'uncertain_provider_outcome:unsanitized_throwable');

            throw $e;
        }

        $attempt = $attempts->recordProviderSucceeded(
            $attempt,
            $ownerToken,
            providerOutcome: 'success',
            billableClassification: null,
            redactedResultMetadata: $this->recoveryEvidenceFor($response),
            resultChecksum: null,
        );

        // The local write is its own short transaction; a failure in it is
        // recorded as a LOCAL failure with the provider-success evidence
        // preserved, so the retry resumes instead of renewing again.
        try {
            [$providerSubscriptionId, $expiresAt] = $this->extractSubscriptionState($response);
            $this->applySubscriptionState($connection, $subscription, $providerSubscriptionId, $expiresAt, $healthStateService);
        } catch (Throwable $localFailure) {
            $attempts->markLocalProcessingFailed(
                $attempt,
                $ownerToken,
                'renewal_local_apply_threw',
                $attempt->local_processing_state,
            );

            throw $localFailure;
        }

        $attempts->markLocalProcessingComplete($attempt, $ownerToken, $attempt->local_processing_state);

        // Already applied locally — the caller must not apply it twice.
        return null;
    }

    /**
     * Resume a renewal the provider already performed, using the durable
     * evidence recorded before this side failed. The pipeline-path
     * equivalent of resumeFromRecordedEvidence().
     */
    private function resumeFromDurableEvidence(
        ProviderBillableCallResult $result,
        FirmIntegration $connection,
        IntegrationProviderWebhookSubscription $subscription,
        HealthStateService $healthStateService,
    ): void {
        if ($result->operationAttempt === null || $result->operationOwnerToken === null) {
            // Nothing this worker owns and nothing outstanding — an
            // already-settled duplicate delivery.
            return;
        }

        if (! $result->mustResumeLocalProcessing()) {
            return;
        }

        $this->resumeFromRecordedEvidence(
            $result->operationAttempt,
            $result->operationOwnerToken,
            $connection,
            $subscription,
            $healthStateService,
        );
    }

    /**
     * Applies the subscription state the provider returned to an EARLIER
     * attempt, read back from that attempt's own recovery evidence.
     *
     * WHAT IS STORED, AND WHY IT IS SAFE (§A8). Only the provider-side
     * subscription id and its expiry — the two values this system already
     * stores unencrypted in `integration_provider_webhook_subscriptions`
     * for exactly this purpose. No token, no credential, no request body,
     * no other response field. Storing them is what makes a genuine resume
     * possible instead of a re-send.
     *
     * When the evidence is missing or unparseable, this refuses to invent
     * state: the attempt is escalated for reconciliation and the failure
     * surfaces to the caller.
     */
    private function resumeFromRecordedEvidence(
        ProviderOperationAttempt $attempt,
        string $ownerToken,
        FirmIntegration $connection,
        IntegrationProviderWebhookSubscription $subscription,
        HealthStateService $healthStateService,
    ): void {
        $attempts = app(ProviderOperationAttemptService::class);
        $evidence = json_decode((string) $attempt->redacted_result_metadata, true);

        $providerSubscriptionId = is_array($evidence) ? ($evidence['provider_subscription_id'] ?? null) : null;
        $expiresAtRaw = is_array($evidence) ? ($evidence['expires_at'] ?? null) : null;

        $expiresAt = null;

        if (is_string($expiresAtRaw) && $expiresAtRaw !== '') {
            try {
                $expiresAt = Carbon::parse($expiresAtRaw);
            } catch (Throwable) {
                // Unparseable evidence is treated exactly like missing
                // evidence — never coerced into a guess.
                $expiresAt = null;
            }
        }

        if (! is_string($providerSubscriptionId) || $providerSubscriptionId === '' || $expiresAt === null) {
            $failed = $attempt->attempt_state === ProviderOperationAttemptState::LocalProcessingFailed
                ? $attempt
                : $attempts->markLocalProcessingFailed($attempt, $ownerToken, 'renewal_evidence_unusable', $attempt->local_processing_state);

            $attempts->markReconciliationRequired($failed, 'renewal_succeeded_but_evidence_unusable');

            throw new ProviderOperationRequiresReconciliationException(
                $attempt->logical_operation_key,
                ProviderOperationAttemptState::ReconciliationRequired->value,
                'renewal_succeeded_but_evidence_unusable',
            );
        }

        $this->applySubscriptionState(
            $connection,
            $subscription,
            $providerSubscriptionId,
            $expiresAt,
            $healthStateService,
        );

        $attempts->markLocalProcessingComplete($attempt, $ownerToken, $attempt->local_processing_state);
    }

    /**
     * The renewal's LOCAL half, in its own short transaction — the writes
     * that used to share one transaction with the provider call.
     */
    private function applySubscriptionState(
        FirmIntegration $connection,
        IntegrationProviderWebhookSubscription $subscription,
        string $providerSubscriptionId,
        Carbon $expiresAt,
        HealthStateService $healthStateService,
    ): void {
        $this->runInFirmContext($this->firmId, function () use ($connection, $subscription, $providerSubscriptionId, $expiresAt, $healthStateService): void {
            $subscription->forceFill([
                'provider_subscription_id' => $providerSubscriptionId,
                'expires_at' => $expiresAt,
                'status' => ProviderWebhookSubscriptionStatus::Active,
                'last_renewed_at' => now(),
                'last_renewal_error' => null,
            ])->save();

            $healthStateService->recordSuccess($connection->id, $this->firmId);
        });
    }

    /**
     * The only provider-response fields kept for recovery: the
     * subscription id and expiry, both already stored unencrypted in this
     * system's own subscription table. Returns null when the response
     * carries neither, so nothing is ever half-recorded.
     */
    private function recoveryEvidenceFor(mixed $response): ?string
    {
        if (! is_array($response)) {
            return null;
        }

        $subscriptionId = $response['subscription_id'] ?? null;
        $expiresAt = $response['expires_at'] ?? null;

        if (! is_string($subscriptionId) || $subscriptionId === '') {
            return null;
        }

        if ($expiresAt instanceof \DateTimeInterface) {
            $expiresAt = $expiresAt->format(\DateTimeInterface::ATOM);
        }

        if (! is_string($expiresAt) || $expiresAt === '') {
            return null;
        }

        return json_encode([
            'provider_subscription_id' => $subscriptionId,
            'expires_at' => $expiresAt,
        ]) ?: null;
    }

    /**
     * subscribe()/renewSubscription() (SupportsWebhooksContract) return
     * only an open array<string, mixed> — "subscription state (e.g.
     * subscription id, expiry)", no fixed key names guaranteed by the
     * interface. A missing/unparseable required field is treated as a
     * malformed-response failure (an uncaught RuntimeException here
     * propagates out of handle() exactly like any other transient
     * failure, retried via this job's own $tries/backoff() — never
     * silently persisted as a NULL against this table's NOT NULL
     * expires_at column).
     *
     * @param  array<string, mixed>  $result
     * @return array{0: string, 1: Carbon}
     */
    private function extractSubscriptionState(array $result): array
    {
        $subscriptionId = $result['subscription_id'] ?? null;
        $expiresAtRaw = $result['expires_at'] ?? null;

        if (! is_string($subscriptionId) || trim($subscriptionId) === '') {
            throw new RuntimeException('Provider returned a subscription result with no usable subscription_id.');
        }

        if (! is_string($expiresAtRaw) && ! $expiresAtRaw instanceof \DateTimeInterface) {
            throw new RuntimeException('Provider returned a subscription result with no usable expires_at.');
        }

        try {
            $expiresAt = Carbon::parse($expiresAtRaw);
        } catch (Throwable) {
            throw new RuntimeException('Provider returned an unparseable expires_at value.');
        }

        return [$subscriptionId, $expiresAt];
    }

    /**
     * Reached only once $tries is exhausted for a category that was
     * rethrown above (or once extractSubscriptionState()'s malformed-
     * response guard is exhausted). Runs outside handle()'s own
     * transaction, so tenant context is re-established fresh — mirrors
     * RefreshIntegrationToken::failed() exactly.
     */
    public function failed(?Throwable $exception): void
    {
        $this->runInFirmContext($this->firmId, function () use ($exception): void {
            $subscription = IntegrationProviderWebhookSubscription::query()
                ->where('id', $this->subscriptionId)
                ->where('firm_integration_id', $this->firmIntegrationId)
                ->first();

            if ($subscription === null || $subscription->status !== ProviderWebhookSubscriptionStatus::Active) {
                // Already handled/moved on by the time all retries
                // exhausted (renewed by a concurrent tick, already
                // failed, or already removed).
                return;
            }

            $category = $exception instanceof SanitizedProviderHttpException
                ? $exception->category()
                : SanitizedProviderHttpException::CATEGORY_UNKNOWN;

            $subscription->update([
                'status' => ProviderWebhookSubscriptionStatus::RenewalFailed,
                'last_renewal_error' => $category,
            ]);

            app(HealthStateService::class)->recordProviderError(
                $this->firmIntegrationId,
                $this->firmId,
                new SanitizedHealthDiagnostic(
                    SanitizedHealthDiagnostic::CATEGORY_PROVIDER_ERROR,
                    SanitizedHealthDiagnostic::OPERATION_WEBHOOK_SUBSCRIBE,
                ),
            );
        });
    }
}
