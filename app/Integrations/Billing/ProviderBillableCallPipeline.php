<?php

declare(strict_types=1);

namespace App\Integrations\Billing;

use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Enums\ProviderOperationClaimDecision;
use App\Integrations\Enums\ResourceType;
use App\Integrations\Enums\SyncDirection;
use App\Integrations\Events\ProviderBillableCallCompleted;
use App\Integrations\Exceptions\ProviderCooldownActiveException;
use App\Integrations\Exceptions\ProviderDuplicateRequestInFlightException;
use App\Integrations\Exceptions\ProviderHardLimitExceededException;
use App\Integrations\Exceptions\ProviderKillSwitchActiveException;
use App\Integrations\Exceptions\ProviderOperationRequiresReconciliationException;
use App\Integrations\Exceptions\ProviderOptionalOperationSuspendedException;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\ProviderBillableCallReservation;
use App\Integrations\Models\ProviderOperationAttempt;
use App\Integrations\Services\FinancialIntegrationAccessPolicyService;
use App\Integrations\Services\ProviderTenantSafePolicyService;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Services\PlaidEntitlementPolicyService;
use App\Services\TimelineEventRecorder;
use Closure;
use RuntimeException;
use Throwable;

/**
 * ProviderBillableCallPipeline — the single, provider-agnostic entry
 * point every billable provider capability call is wrapped by
 * (checkpoint4-design-cost-control.md §2; checkpoint4-combined-design.md
 * §8.3, corrected per Finding 1 of `checkpoint4-security-review.md`).
 *
 * `execute()` accepts a plain `Closure $providerCall` — never a
 * compile-time-typed provider class — so this pipeline is buildable and
 * fully usable without `PlaidProvider` (or any other concrete provider)
 * existing yet, and can wrap ANY provider's billable call, Plaid or
 * otherwise. Per §2.1's corrected three-layer wiring, `$providerCall`
 * must always itself be a call to
 * `App\Integrations\Support\OutboundProviderHttpClient::execute()`
 * wrapping the real provider method (which itself calls
 * `App\Integrations\Support\ProviderRequestExecutor::send()`) — this
 * class never calls either of those two layers directly, and never
 * duplicates anything either already does.
 *
 * THREE DELIBERATE, DISCLOSED SIGNATURE ADDITIONS beyond the source
 * design's own illustrative `execute()` signature (design §2), all
 * required to make the 17 steps the design itself specifies actually
 * resolvable, and each additive/optional or newly-required rather than
 * silently reinterpreting an existing parameter:
 *
 *   0. `Firm $firm` (REQUIRED, new) — design step 2's own description
 *      ("`ProviderTenantSafePolicyService::assertConnectionBelongsToFirm(FirmIntegration
 *      $connection, Firm $firm)`") takes a `Firm` the design's own
 *      `execute()` signature never declares receiving. Deriving it from
 *      `$connection->firm` instead would make that check tautological
 *      (comparing `$connection->firm_id` against `$connection->firm->id`,
 *      which is definitionally always true) and defeat the entire
 *      purpose of the defense-in-depth comparison — exactly like
 *      `TenantSafeTrustPolicyService`'s own real precedent, the caller
 *      must supply `$firm` independently (e.g. from the ambient
 *      authenticated/job context), never derived from the very object
 *      being checked against it.
 *   1. `string $environment` (REQUIRED, new) — `provider_rate_card_entries`
 *      and the two split operation-policy tables are all keyed partly by
 *      `environment` ('sandbox'|'production'); the design's own
 *      illustrative signature omits it entirely even though steps 6/7
 *      cannot resolve a rate or a policy without it. Left for the
 *      caller to supply (mirroring how
 *      `App\Integrations\Support\ProviderEnvironmentResolver` already
 *      determines a provider's live mode from config) rather than this
 *      pipeline re-deriving it.
 *   2. `?object $provider = null` / `?string $requiredContractFqcn = null`
 *      (both optional, new) — design step 4 ("verify capability") reads
 *      "`$provider instanceof Supports<Product>Contract`... this
 *      pipeline receives the already-resolved provider instance", but
 *      the design's own `execute()` signature carries no `$provider`
 *      parameter at all. Rather than hardcode a `product -> contract
 *      FQCN` map inside this generic pipeline (which would bake in
 *      Plaid-specific product knowledge this class must not depend on),
 *      both are optional: when a caller supplies BOTH, the check runs;
 *      when either is omitted, step 4 is a no-op (the caller already
 *      had to resolve a working provider to build `$providerCall` in
 *      the first place, so an omitted check is a reduced-strictness
 *      default, never a silent capability bypass a caller could not
 *      otherwise have avoided).
 *
 * Deliberately does NOT implement pipeline step 8's "check cache" as a
 * silent early-return hidden inside a private helper indistinguishable
 * from a real call — a cache hit returns a `ProviderBillableCallResult`
 * with `reservation: null` and a `servedFromCache()` outcome, so a
 * caller can always tell the two apart.
 *
 * RESERVATION-STATE GATE (steps 12b/12c, double-billing remediation —
 * additive, the 17-step structure is unchanged). Step 12's `reserve()`
 * has always been idempotent for the ledger ROW, but step 13 used to
 * fire the REAL outbound call unconditionally, including when `reserve()`
 * had merely SELECT-fallen-back onto a reservation an earlier attempt
 * created. Combined with wall-clock-minute idempotency keys at the job
 * call sites (now deterministic), a retried job could bill the same
 * logical operation more than once. Step 12b now branches on whether
 * `reserve()` INSERTed the row (`wasRecentlyCreated`) and, when it did
 * not, on the existing row's status and `provider_call_started_at` —
 * see `gateExistingReservation()` for the full decision table. A gated
 * call returns a `ProviderBillableCallResult` carrying the EXISTING
 * reservation and a `servedFromExistingReservation()` outcome, mirroring
 * the cache-hit path's own distinguishability contract exactly (with
 * `response: null`, because a reservation records what a call cost, never
 * what it returned).
 *
 * Untouched by that remediation, deliberately: PerConnectionRateLimiter,
 * the step 7 kill-switch/suspension checks, the step 10 cooldown, and the
 * step 11 soft/hard usage limits are separate, already-correct layers.
 *
 * ---------------------------------------------------------------------
 * DURABLE PHASING (Checkpoint 8.2 §A5) — CLAIM / CALL / APPLY / RECOVER
 * ---------------------------------------------------------------------
 *
 * The reservation-state gate above is still the right idea, but all of
 * its evidence lives on the ordinary application connection, inside
 * whatever transaction the CALLER holds. When that transaction rolls back
 * — a post-call exception, a crash, a worker killed mid-deploy — both the
 * reservation and its `provider_call_started_at` intent marker vanish,
 * and the retry re-fires a call the provider already performed. That
 * residual gap is the Checkpoint 8 C3 defect.
 *
 * `execute()` is therefore phased around a SECOND, independent record
 * that the caller's transaction cannot erase — a
 * `provider_operation_attempts` row, written on its own database session
 * via `ProviderOperationAttemptService` (see that class and its table's
 * migration for why the table carries no foreign keys, no RLS policy and
 * no transactions):
 *
 *   CLAIM   (steps 12/12b/12d) Reserve as before, then durably claim the
 *           logical operation. Exactly one of five decisions comes back,
 *           and only `Proceed` authorizes a send. Committed BEFORE any
 *           request can leave the process.
 *   CALL    (steps 12e/13) Record `attempt_started` durably — a
 *           compare-and-set that can succeed at most once per attempt
 *           generation — and only then run `$providerCall()`. No database
 *           transaction is opened and no row lock is held by this
 *           pipeline across that call.
 *   APPLY   (steps 14-17) Classify the provider's outcome durably first,
 *           then do the local work (finalize, cache, audit, events). A
 *           throw during local work is recorded as
 *           `local_processing_failed` WITH the provider-success evidence
 *           intact, so the retry resumes instead of re-sending.
 *   RECOVER A retry of the same logical operation is routed by the gate,
 *           not by guesswork: resume local processing, no-op if already
 *           settled, back off if a peer holds a live lease, or refuse and
 *           demand reconciliation if the provider's outcome is unknown.
 *
 * WHY UNIVERSAL RECONCILIATION IS SAFE HERE. An uncertain outcome parks
 * the logical operation until a human resolves it, for non-billable
 * operations too (a duplicated webhook subscription is a real side
 * effect, not merely a cost). That is not a permanent stall, because
 * every logical operation key in this system is scoped to ONE attempt
 * cycle — a sync run plus cursor version plus page, or a subscription
 * renewal cycle — never to a resource for all time. A later cycle
 * computes a different key and proceeds normally; only the ambiguous
 * cycle waits for a human.
 *
 * WHAT IS DELIBERATELY *NOT* MOVED. `provider_billable_call_reservations`
 * and `integration_usage_records` remain the authoritative billing rows
 * on the ordinary connection, with their real foreign keys and their real
 * RLS. Checkpoint 8.1 tried to make those rows themselves durable by
 * relocating the FK-bearing table onto the independent connection and
 * deadlocked against `PullSyncJob`'s `lockForUpdate()`. The gate row is
 * evidence used to REBUILD billing state during recovery, never a second
 * ledger of money owed.
 */
final class ProviderBillableCallPipeline
{
    public function __construct(
        private readonly ProviderTenantSafePolicyService $tenantSafePolicy,
        private readonly PlaidEntitlementPolicyService $entitlementPolicy,
        private readonly ProviderBillingClassifier $classifier,
        private readonly ProviderRateCardResolver $rateCardResolver,
        private readonly ProviderOperationPolicyResolver $operationPolicyResolver,
        private readonly ProviderResponseCacheService $responseCache,
        private readonly ProviderRequestDeduplicationService $deduplication,
        private readonly ProviderCooldownService $cooldown,
        private readonly ProviderUsageLimitEnforcementService $limitEnforcement,
        private readonly ProviderUsageReservationService $reservationService,
        private readonly ProviderCallOutcomeNormalizer $outcomeNormalizer,
        private readonly TimelineEventRecorder $events,
        private readonly ProviderOperationAttemptService $operationAttempts,
    ) {}

    public function execute(
        ProviderKey $providerKey,
        FirmIntegration $connection,
        Firm $firm,
        ?FirmUser $actor,
        string $product,
        string $billingOperation,
        string $environment,
        SyncDirection $direction,
        ?ResourceType $resourceType,
        Closure $providerCall,
        string $usageIdempotencyKey,
        int $quantity = 1,
        ?string $confirmationToken = null,
        ?string $confirmationReason = null,
        ?object $provider = null,
        ?string $requiredContractFqcn = null,
        ?string $accountId = null,
        array $cacheKeyContext = [],
        ?Closure $redactResultForRecovery = null,
        ?string $localProcessingState = null,
    ): ProviderBillableCallResult {
        // STEP 1 — authorize actor.
        if ($actor !== null) {
            app(FinancialIntegrationAccessPolicyService::class)->assertCanView($actor);
        } elseif ($connection->status !== ConnectionStatus::Active) {
            throw new RuntimeException(
                "Cannot execute a system/job-triggered billable call: connection [id={$connection->id}] is not Active."
            );
        }

        // STEP 2 — verify firm ownership.
        $this->tenantSafePolicy->assertConnectionBelongsToFirm($connection, $firm);

        // STEP 3 — verify entitlement.
        $this->entitlementPolicy->assertEnabled($firm);

        // STEP 4 — verify capability (see class docblock, addition #2).
        if ($provider !== null && $requiredContractFqcn !== null && interface_exists($requiredContractFqcn)) {
            if (! $provider instanceof $requiredContractFqcn) {
                throw new RuntimeException(
                    'Provider ['.$provider::class."] does not implement the required capability contract [{$requiredContractFqcn}]."
                );
            }
        }

        // STEP 5 — classify operation.
        $classification = $this->classifier->classify($providerKey, $product, $billingOperation);

        if ($classification->requiresExplicitConfirmation && $confirmationToken === null) {
            throw new RuntimeException(
                "Operation ({$classification->capability()}) requires an explicit confirmation token, none was supplied."
            );
        }

        // STEP 6 — resolve effective rate.
        $rate = $this->rateCardResolver->resolve($providerKey, $classification, $environment, $firm);

        // STEP 7 — resolve firm policy (kill switches + split
        // operation-policy tables). Throws immediately on any match.
        try {
            $policy = $this->operationPolicyResolver->resolve($providerKey, $classification, $firm, $environment);
        } catch (ProviderKillSwitchActiveException $e) {
            $this->auditDenied($firm, $actor, 'provider_billing.kill_switch_denied', $classification, [
                'level' => $e->level,
                'target' => $e->target,
            ]);

            throw $e;
        } catch (ProviderOptionalOperationSuspendedException $e) {
            $this->auditDenied($firm, $actor, 'provider_billing.kill_switch_denied', $classification, [
                'reason' => 'firm_optional_operation_suspended',
            ]);

            throw $e;
        }

        // STEP 8 — check cache.
        $cached = $this->responseCache->get($connection, $classification, $cacheKeyContext);

        if ($cached !== null) {
            $this->events->record($firm, 'provider_billing.call_served_from_cache', $connection, $actor?->user, [
                'provider_key' => $providerKey->value,
                'capability' => $classification->capability(),
            ], independentOfAmbientTransaction: true);

            return new ProviderBillableCallResult(
                response: $cached,
                reservation: null,
                outcome: ProviderNormalizedOutcome::servedFromCache(),
                softLimitExceeded: false,
            );
        }

        // STEP 9 — check duplicate request.
        try {
            $lock = $this->deduplication->acquire($connection, $classification, array_filter([
                'account_id' => $accountId,
                ...$cacheKeyContext,
            ], static fn ($v) => $v !== null));
        } catch (ProviderDuplicateRequestInFlightException $e) {
            $this->auditDenied($firm, $actor, 'provider_billing.duplicate_request_denied', $classification, []);

            throw $e;
        }

        try {
            // STEP 10 — enforce cooldown.
            try {
                $this->cooldown->assertNotCoolingDown($connection, $classification, $policy, $accountId);
            } catch (ProviderCooldownActiveException $e) {
                $this->auditDenied($firm, $actor, 'provider_billing.cooldown_denied', $classification, [
                    'remaining_seconds' => $e->remainingSeconds,
                ]);

                throw $e;
            }

            // STEP 11 — enforce limits.
            try {
                $softLimitExceeded = $this->limitEnforcement->assertWithinLimits(
                    $firm, $connection, $providerKey, $classification, $policy, $quantity,
                );
            } catch (ProviderHardLimitExceededException $e) {
                $this->auditDenied($firm, $actor, 'provider_billing.hard_limit_denied', $classification, [
                    'limit' => $e->limit,
                    'attempted_total' => $e->attemptedTotal,
                ]);

                throw $e;
            }

            if ($softLimitExceeded) {
                $this->events->record($firm, 'provider_billing.soft_limit_exceeded', $connection, $actor?->user, [
                    'provider_key' => $providerKey->value,
                    'capability' => $classification->capability(),
                ], independentOfAmbientTransaction: true);
            }

            $reservationTtlSeconds = (int) config('integrations.provider_billing.reservation_ttl_seconds', 120);

            // STEP 12 — reserve usage.
            $reservation = $this->reservationService->reserve(
                firm: $firm,
                connection: $connection,
                providerKey: $providerKey,
                classification: $classification,
                environment: $environment,
                rate: $rate,
                idempotencyKey: $usageIdempotencyKey,
                quantity: $quantity,
                reservationTtlSeconds: $reservationTtlSeconds,
                reservedBy: $actor,
                correlationId: null,
                reservationReason: $confirmationReason,
            );

            // STEP 12d — DURABLE CLAIM, and the AUTHORITATIVE answer to
            // "may this logical operation send a request?" It runs BEFORE
            // the ambient reservation gate below, deliberately: the
            // reservation lives inside the caller's transaction and can
            // therefore be missing (rolled back), stale, or — as
            // ProviderBillableCallPipelineDurableGateTest proves —
            // outright contradicted by what actually happened. Only
            // ProviderOperationClaimDecision::Proceed authorizes a send;
            // every other decision either short-circuits with durable
            // evidence (including the owner token a caller needs to finish
            // interrupted local work) or refuses outright.
            $logicalOperationKey = $this->logicalOperationKeyFor($providerKey, $firm, $classification, $environment, $usageIdempotencyKey);

            $claim = $this->operationAttempts->claim(
                logicalOperationKey: $logicalOperationKey,
                providerKey: $providerKey->value,
                firmId: (int) $firm->id,
                firmIntegrationId: (int) $connection->id,
                operationType: $classification->capability(),
            );

            if (! $claim->maySendProviderRequest()) {
                return $this->resolveDeniedClaim(
                    $firm, $connection, $actor, $providerKey, $classification,
                    $claim, $reservation, $softLimitExceeded,
                );
            }

            $ownerToken = $claim->ownerTokenOrFail();

            // STEP 12b — RESERVATION-STATE GATE (double-billing
            // remediation; see this class's docblock, "Reservation-state
            // gate"). `reserve()` is idempotent bookkeeping for the
            // ledger ROW, but nothing here used to inspect whether it had
            // INSERTed a fresh row or SELECT-fallen-back onto an existing
            // one — so step 13 re-fired the REAL outbound call for a
            // logical operation an earlier attempt may already have made
            // and been billed for.
            //
            // Retained BELOW the durable claim rather than replaced by it:
            // it still covers reservations created before this checkpoint
            // shipped (which have no durable counterpart at all), and it
            // remains a genuine second opinion within one transaction. A
            // short-circuit here releases the claim taken just above —
            // nothing was sent, so the release can only ever make the
            // operation retryable, never sendable twice.
            if (! $reservation->wasRecentlyCreated) {
                $reservation = $this->gateExistingReservation(
                    $firm, $connection, $actor, $providerKey, $classification,
                    $reservation, $reservationTtlSeconds, $direction, $resourceType, $quantity,
                );

                if ($reservation->status !== ProviderBillableCallReservation::STATUS_RESERVED) {
                    // (c)/(b-with-call-in-flight): a terminal or
                    // genuinely-ambiguous prior outcome. Serve the
                    // EXISTING outcome — never a fabricated fresh one —
                    // and never call the provider again. Distinguishable
                    // by the caller exactly the way step 8's cache hit
                    // already is.
                    $this->operationAttempts->releaseUnusedClaim(
                        $claim->attempt, $ownerToken, 'superseded_by_reservation_gate:'.$reservation->status,
                    );

                    $this->events->record($firm, 'provider_billing.call_served_from_existing_reservation', $reservation, $actor?->user, [
                        'provider_key' => $providerKey->value,
                        'capability' => $classification->capability(),
                        'existing_status' => $reservation->status,
                    ], independentOfAmbientTransaction: true);

                    return new ProviderBillableCallResult(
                        response: null,
                        reservation: $reservation,
                        outcome: ProviderNormalizedOutcome::servedFromExistingReservation(),
                        softLimitExceeded: $softLimitExceeded,
                    );
                }
            }

            $this->events->record($firm, 'provider_billing.call_reserved', $reservation, $actor?->user, [
                'provider_key' => $providerKey->value,
                'capability' => $classification->capability(),
            ], independentOfAmbientTransaction: true);

            // STEP 12c — record outbound-call INTENT on the reservation
            // itself, so a crash from here onward is distinguishable (by
            // a later attempt reaching step 12b) from a crash between
            // step 12 and here. See the `provider_call_started_at`
            // migration's docblock. This marker is still useful within a
            // single transaction, but it is NOT durable across the
            // caller's rollback — step 12e is what makes the same fact
            // survive (Checkpoint 8.2 §A5/§A9).
            $reservation = $this->reservationService->markProviderCallStarted($firm, $reservation);

            // STEP 12e — DURABLE SEND INTENT, before the request can
            // leave this process. The compare-and-set inside
            // markAttemptStarted() requires this worker's owner token, the
            // `claimed` state and `send_count = 0`, so this transition
            // succeeds at most once per attempt generation — and once it
            // has succeeded, no automated path can ever return `Proceed`
            // for this key again.
            $attempt = $this->operationAttempts->markAttemptStarted($claim->attempt, $ownerToken, $usageIdempotencyKey);

            // STEP 13 — execute through OutboundProviderHttpClient ->
            // ProviderRequestExecutor. $providerCall() is always,
            // structurally, a call into that existing sanitizing
            // boundary — this pipeline never calls Http:: or duplicates
            // anything that layer or the executor beneath it already
            // does.
            //
            // WIDENED (double-billing remediation): this catch used to be
            // `catch (SanitizedProviderHttpException)` only, so ANY other
            // throwable escaping $providerCall() propagated straight out
            // of execute(), skipping steps 14-17 entirely and stranding
            // the reservation in `reserved` until its TTL with no usage
            // record ever written. Every Throwable is now captured so
            // finalize() always runs; the ORIGINAL throwable is rethrown
            // unchanged below (step 17), so a caller's own
            // category-branching and its queue worker's retry eligibility
            // are both preserved exactly as before — nothing is swallowed
            // and nothing transient is converted into a permanent
            // failure. Classification lives in
            // ProviderCallOutcomeNormalizer (extended, not replaced),
            // which decides purely from the exception's class and never
            // reads its message, so redaction is preserved.
            $response = null;
            $caughtException = null;

            try {
                $response = $providerCall();
            } catch (Throwable $e) {
                $caughtException = $e;
            }

            // STEP 14 — normalize outcome.
            $outcome = $this->outcomeNormalizer->normalize($response, $caughtException);

            // STEP 14b — DURABLE OUTCOME, recorded before ANY local work
            // runs. This is the ordering that makes the C3 defect
            // impossible: if everything below throws or the caller's
            // transaction rolls back, the provider's outcome is still on
            // record, so the retry resumes or reconciles instead of
            // re-sending.
            $attempt = $this->recordDurableOutcome(
                $attempt, $ownerToken, $outcome, $response, $classification, $redactResultForRecovery,
            );

            // STEPS 15-17 — LOCAL APPLY. Everything here touches only this
            // system's own state. A throw is recorded durably as a LOCAL
            // failure — never as a provider failure — with the
            // provider-success evidence preserved.
            try {
                // STEP 15 — finalize usage.
                $reservation = $this->reservationService->finalize($firm, $reservation, $outcome, $direction, $resourceType, $quantity);

                if ($outcome->certain && $outcome->billable) {
                    $this->cooldown->start($connection, $classification, $accountId, $policy->cooldownSeconds);

                    if ($classification->isCacheable && $policy->cacheTtlSeconds !== null && is_array($response)) {
                        $this->responseCache->put($connection, $classification, $cacheKeyContext, $response, $policy->cacheTtlSeconds);
                    }
                }

                // STEP 16 — audit.
                $finalizedEventType = match (true) {
                    ! $outcome->certain => 'provider_billing.call_finalized_uncertain',
                    $outcome->billable => 'provider_billing.call_finalized_billable',
                    default => 'provider_billing.call_finalized_non_billable',
                };

                $this->events->record($firm, $finalizedEventType, $reservation, $actor?->user, [
                    'provider_key' => $providerKey->value,
                    'capability' => $classification->capability(),
                    'category' => $outcome->category,
                    'logical_operation_key' => $logicalOperationKey,
                ], independentOfAmbientTransaction: true);

                // STEP 17 — update observability.
                ProviderBillableCallCompleted::dispatch(
                    $providerKey->value,
                    $classification->product,
                    $classification->billingOperation,
                    $outcome->billable,
                    $outcome->certain,
                    $reservation->estimated_customer_price_cents,
                    $connection->id,
                    $reservation->correlation_id,
                );
            } catch (Throwable $localFailure) {
                if ($attempt->providerWorkIsDone()) {
                    $attempt = $this->operationAttempts->markLocalProcessingFailed(
                        $attempt,
                        $ownerToken,
                        'pipeline_local_apply_threw:'.$this->shortClassName($localFailure),
                        $localProcessingState,
                    );

                    $this->auditDenied($firm, $actor, 'provider_billing.operation_local_processing_failed', $classification, [
                        'provider_key' => $providerKey->value,
                        'firm_integration_id' => $connection->id,
                        'logical_operation_key' => $logicalOperationKey,
                        'attempt_state' => $attempt->attempt_state->value,
                    ]);
                }

                throw $localFailure;
            }

            if ($caughtException !== null) {
                // The provider itself failed. The durable row already
                // records whether that failure was definite
                // (`provider_rejected`, retryable) or ambiguous
                // (escalated to reconciliation), so the original
                // throwable is rethrown unchanged for the caller and its
                // queue worker, exactly as before.
                throw $caughtException;
            }

            // The provider succeeded and the local apply above completed,
            // so this logical operation is settled end to end.
            if ($attempt->providerWorkIsDone()) {
                $attempt = $this->operationAttempts->markLocalProcessingComplete($attempt, $ownerToken, $localProcessingState);
            }

            return new ProviderBillableCallResult(
                response: $response,
                reservation: $reservation,
                outcome: $outcome,
                softLimitExceeded: $softLimitExceeded,
                operationAttempt: $attempt,
                operationOwnerToken: $ownerToken,
            );
        } finally {
            $lock->release();
        }
    }

    /**
     * STEP 12b's decision table for a reservation `reserve()` did NOT
     * create — i.e. some earlier attempt already reserved this exact
     * logical operation under the same (now deterministic) idempotency
     * key. Returns a reservation in `reserved` status when, and only
     * when, it is safe for the caller to proceed to step 13; returns it
     * in its existing terminal status when the real call must NOT be
     * re-fired; throws when another attempt may be live right now.
     *
     *   (a) freshly INSERTed              -> never reaches this method.
     *   (b) `reserved`, TTL NOT elapsed   -> another worker is very
     *       likely mid-call for this exact operation right now. No
     *       ownership/leasing column exists that could prove otherwise,
     *       and inventing one would not help (a lease token cannot
     *       distinguish "my own crashed attempt" from "a peer's live
     *       attempt" any better than the TTL already does). SAFEST
     *       POLICY: refuse, by throwing the existing
     *       ProviderDuplicateRequestInFlightException — the same signal
     *       step 9 already raises for a concurrent duplicate. This does
     *       NOT strand the operation: the caller's own retry/backoff
     *       re-enters later, by which time the row is either finalized
     *       (case c) or stale (case b-stale/d).
     *   (b-stale) `reserved`, TTL elapsed, `provider_call_started_at`
     *       NULL -> crashed between reserve and the call; the provider
     *       provably was never contacted. Re-claim and proceed.
     *   (b-stale) `reserved`, TTL elapsed, call HAD started -> genuinely
     *       ambiguous. Park it terminally as `finalized_uncertain` (so it
     *       stops sitting in `reserved` forever, and so the anomaly/
     *       reconciliation surface sees it) and refuse to re-fire.
     *   (c) `finalized_billable` / `finalized_uncertain` -> the provider
     *       either definitely did, or may have done, the billable work.
     *       NEVER re-fire; serve the existing outcome.
     *   (c') `finalized_non_billable` -> DELIBERATE, DISCLOSED
     *       REFINEMENT of "no terminal status may re-fire". This status
     *       is positive knowledge that the provider REJECTED the request
     *       before doing any billable work (authentication_failed,
     *       validation_failed, rate_limited, a 5xx provider_rejected,
     *       ...) and that no `integration_usage_records` row was written.
     *       Re-firing therefore cannot double-charge, while refusing
     *       would permanently swallow exactly the transient failures a
     *       queue retry exists to recover from — a regression the
     *       deterministic-key change would otherwise introduce, since the
     *       unique index forbids simply reserving again. Re-claim and
     *       proceed.
     *   (d) `expired` -> same two sub-cases as (b-stale), decided by
     *       `provider_call_started_at`. Note this pipeline decides
     *       staleness from `expires_at` itself and never waits for
     *       ExpireStaleProviderReservationsJob: that job is a coarse,
     *       WithoutOverlapping-guarded sweep with no "runs before the
     *       next retry" guarantee, so a `reserved`-but-past-TTL row must
     *       be treated as effectively expired here.
     *
     * Every re-claim goes through the single-winner compare-and-set in
     * ProviderUsageReservationService::reclaim(); losing that race is
     * treated exactly like case (b).
     */
    private function gateExistingReservation(
        Firm $firm,
        FirmIntegration $connection,
        ?FirmUser $actor,
        ProviderKey $providerKey,
        ProviderBillingClassification $classification,
        ProviderBillableCallReservation $reservation,
        int $reservationTtlSeconds,
        SyncDirection $direction,
        ?ResourceType $resourceType,
        int $quantity,
    ): ProviderBillableCallReservation {
        $status = (string) $reservation->status;

        // (b) — a live reservation held by someone else.
        if ($status === ProviderBillableCallReservation::STATUS_RESERVED && ! $reservation->isPastTtl()) {
            $this->auditDenied($firm, $actor, 'provider_billing.reservation_in_flight_denied', $classification, [
                'provider_key' => $providerKey->value,
                'firm_integration_id' => $connection->id,
            ]);

            throw new ProviderDuplicateRequestInFlightException;
        }

        $reclaimable = match ($status) {
            ProviderBillableCallReservation::STATUS_RESERVED,
            ProviderBillableCallReservation::STATUS_EXPIRED => ! $reservation->providerCallStarted(),
            ProviderBillableCallReservation::STATUS_FINALIZED_NON_BILLABLE => true,
            default => false,
        };

        if ($reclaimable) {
            $reclaimed = $this->reservationService->reclaim($firm, $reservation, $reservationTtlSeconds);

            if ($reclaimed === null) {
                $this->auditDenied($firm, $actor, 'provider_billing.reservation_in_flight_denied', $classification, [
                    'provider_key' => $providerKey->value,
                    'firm_integration_id' => $connection->id,
                    'lost_reclaim_race_from_status' => $status,
                ]);

                throw new ProviderDuplicateRequestInFlightException;
            }

            $this->events->record($firm, 'provider_billing.reservation_reclaimed', $reclaimed, $actor?->user, [
                'provider_key' => $providerKey->value,
                'capability' => $classification->capability(),
                'previous_status' => $status,
            ], independentOfAmbientTransaction: true);

            return $reclaimed;
        }

        if ($status === ProviderBillableCallReservation::STATUS_RESERVED) {
            // (b-stale) with a call that had already started — park it
            // terminally rather than leaving it stuck in `reserved`.
            return $this->reservationService->finalize(
                $firm,
                $reservation,
                ProviderNormalizedOutcome::uncertain('abandoned_attempt'),
                $direction,
                $resourceType,
                $quantity,
            );
        }

        return $reservation;
    }

    /**
     * The durable gate's identity for ONE logical operation (Checkpoint
     * 8.2 §A5). Built from the caller's already-deterministic
     * `$usageIdempotencyKey` (§A10 removed every wall-clock component
     * from those) plus the firm and the full capability coordinates.
     *
     * `firm_` is included FIRST and unconditionally: it makes a
     * cross-tenant key collision impossible by construction rather than
     * merely unlikely, which matters more than usual here because the
     * gate table deliberately carries no foreign keys.
     * ProviderOperationAttemptService still refuses a mismatch at read
     * time — that check now covers only genuine key-construction bugs.
     */
    private function logicalOperationKeyFor(
        ProviderKey $providerKey,
        Firm $firm,
        ProviderBillingClassification $classification,
        string $environment,
        string $usageIdempotencyKey,
    ): string {
        return implode(':', [
            'firm_'.$firm->id,
            $providerKey->value,
            $classification->product,
            $classification->billingOperation,
            $environment,
            $usageIdempotencyKey,
        ]);
    }

    /**
     * STEP 12d's decision table for a claim that did NOT authorize a
     * send. Each branch is either an honest short-circuit backed by
     * durable evidence, or a refusal — none of them calls the provider.
     *
     *   InFlightElsewhere      -> a peer holds a live lease on this exact
     *                             logical operation. Refuse with the same
     *                             signal step 9 already raises for a
     *                             concurrent duplicate, so the caller's
     *                             existing backoff handles it.
     *   AlreadyComplete        -> settled end to end by an earlier
     *                             attempt. Serve the recorded evidence,
     *                             exactly as step 8's cache hit and step
     *                             12b's reservation gate do.
     *   ResumeLocalProcessing  -> the provider DID the work; only local
     *                             post-processing is outstanding. Hand
     *                             the caller the durable evidence AND the
     *                             owner token it needs to finish, without
     *                             ever re-sending.
     *   ReconciliationRequired -> the outcome is unknown, or a local
     *                             failure cannot be safely resumed.
     *                             Refuse loudly; only a human (or a
     *                             genuinely new logical operation) moves
     *                             this forward.
     */
    private function resolveDeniedClaim(
        Firm $firm,
        FirmIntegration $connection,
        ?FirmUser $actor,
        ProviderKey $providerKey,
        ProviderBillingClassification $classification,
        ProviderOperationClaim $claim,
        ProviderBillableCallReservation $reservation,
        bool $softLimitExceeded,
    ): ProviderBillableCallResult {
        $attempt = $claim->attempt;

        $metadata = [
            'provider_key' => $providerKey->value,
            'firm_integration_id' => $connection->id,
            'logical_operation_key' => $attempt->logical_operation_key,
            'claim_decision' => $claim->decision->value,
            'attempt_state' => $attempt->attempt_state->value,
        ];

        if ($claim->decision === ProviderOperationClaimDecision::InFlightElsewhere) {
            $this->auditDenied($firm, $actor, 'provider_billing.operation_in_flight_denied', $classification, $metadata);

            throw new ProviderDuplicateRequestInFlightException;
        }

        if ($claim->decision === ProviderOperationClaimDecision::ReconciliationRequired) {
            $this->auditDenied($firm, $actor, 'provider_billing.operation_requires_reconciliation', $classification, $metadata);

            throw new ProviderOperationRequiresReconciliationException(
                $attempt->logical_operation_key,
                $attempt->attempt_state->value,
                $attempt->reconciliation_reason,
            );
        }

        // AlreadyComplete / ResumeLocalProcessing — both are served from
        // durable evidence, and neither fabricates a fresh outcome.
        $this->events->record($firm, 'provider_billing.call_served_from_durable_operation_evidence', $reservation, $actor?->user, [
            'capability' => $classification->capability(),
            ...$metadata,
        ], independentOfAmbientTransaction: true);

        return new ProviderBillableCallResult(
            response: null,
            reservation: $reservation,
            outcome: ProviderNormalizedOutcome::servedFromExistingReservation(),
            softLimitExceeded: $softLimitExceeded,
            operationAttempt: $attempt,
            operationOwnerToken: $claim->ownerToken,
        );
    }

    /**
     * STEP 14b — translate the normalized outcome into the durable gate's
     * own vocabulary, on the independent connection, before any local work
     * runs.
     *
     *   certain + billable  -> provider_succeeded. The provider did the
     *                          work; a retry may resume local processing
     *                          but may never re-send.
     *   certain + !billable -> provider_rejected. Positive knowledge that
     *                          the provider refused BEFORE doing billable
     *                          work, so a fresh attempt is safe. This is
     *                          the only post-send state that stays
     *                          retryable, and it mirrors step 12b's own
     *                          `finalized_non_billable` refinement.
     *   !certain            -> provider_outcome_uncertain, immediately
     *                          escalated to reconciliation_required. We
     *                          genuinely cannot tell whether the provider
     *                          did the work, so no automated path touches
     *                          it again (see the class docblock for why
     *                          that cannot stall a resource permanently).
     *
     * Only ALREADY-REDACTED evidence is stored (§A8): an optional
     * caller-supplied redactor for the metadata summary, plus a one-way
     * SHA-256 digest of the response for later comparison. No response
     * body, token, or provider payload is ever written.
     */
    private function recordDurableOutcome(
        ProviderOperationAttempt $attempt,
        string $ownerToken,
        ProviderNormalizedOutcome $outcome,
        mixed $response,
        ProviderBillingClassification $classification,
        ?Closure $redactResultForRecovery,
    ): ProviderOperationAttempt {
        if (! $outcome->certain) {
            $uncertain = $this->operationAttempts->recordProviderOutcomeUncertain(
                $attempt, $ownerToken, 'provider_outcome_uncertain:'.$outcome->category,
            );

            return $this->operationAttempts->markReconciliationRequired(
                $uncertain, 'uncertain_provider_outcome:'.$outcome->category,
            );
        }

        if (! $outcome->billable) {
            return $this->operationAttempts->recordProviderRejected(
                $attempt, $ownerToken, 'provider_rejected:'.$outcome->category, $outcome->category,
            );
        }

        return $this->operationAttempts->recordProviderSucceeded(
            $attempt,
            $ownerToken,
            providerOutcome: $outcome->category,
            billableClassification: $classification->capability(),
            redactedResultMetadata: $this->redactedResultMetadata($response, $redactResultForRecovery),
            resultChecksum: $this->resultChecksum($response),
        );
    }

    /**
     * A short, safe summary of the provider result for recovery
     * purposes, or null. NEVER the response itself: without a
     * caller-supplied redactor this returns null rather than guessing
     * what would be safe to keep.
     */
    private function redactedResultMetadata(mixed $response, ?Closure $redactResultForRecovery): ?string
    {
        if ($redactResultForRecovery === null) {
            return null;
        }

        $redacted = $redactResultForRecovery($response);

        if ($redacted === null) {
            return null;
        }

        return mb_substr(is_string($redacted) ? $redacted : json_encode($redacted), 0, 2000) ?: null;
    }

    /**
     * A one-way digest of the response, so a resumed attempt can tell
     * "the same result" from "a different result" without the result
     * itself ever being stored. Deliberately hashes a JSON encoding and
     * returns null when the response cannot be encoded — never a partial
     * or lossy rendering of a payload.
     */
    private function resultChecksum(mixed $response): ?string
    {
        if ($response === null) {
            return null;
        }

        $encoded = json_encode($response);

        return $encoded === false ? null : 'sha256:'.hash('sha256', $encoded);
    }

    private function shortClassName(object $subject): string
    {
        $class = $subject::class;

        return ($position = strrpos($class, '\\')) === false ? $class : substr($class, $position + 1);
    }

    /**
     * @param  array<string, mixed>  $extraMetadata
     */
    private function auditDenied(Firm $firm, ?FirmUser $actor, string $eventType, ProviderBillingClassification $classification, array $extraMetadata): void
    {
        $this->events->record($firm, $eventType, null, $actor?->user, [
            'capability' => $classification->capability(),
            'product' => $classification->product,
            ...$extraMetadata,
        ], independentOfAmbientTransaction: true);
    }
}
