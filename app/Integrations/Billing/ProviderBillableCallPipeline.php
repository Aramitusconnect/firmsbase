<?php

declare(strict_types=1);

namespace App\Integrations\Billing;

use App\Integrations\Enums\ProviderKey;
use App\Integrations\Enums\ResourceType;
use App\Integrations\Enums\SyncDirection;
use App\Integrations\Events\ProviderBillableCallCompleted;
use App\Integrations\Exceptions\ProviderCooldownActiveException;
use App\Integrations\Exceptions\ProviderDuplicateRequestInFlightException;
use App\Integrations\Exceptions\ProviderHardLimitExceededException;
use App\Integrations\Exceptions\ProviderKillSwitchActiveException;
use App\Integrations\Exceptions\ProviderOptionalOperationSuspendedException;
use App\Integrations\Exceptions\SanitizedProviderHttpException;
use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Services\FinancialIntegrationAccessPolicyService;
use App\Integrations\Services\ProviderTenantSafePolicyService;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Services\PlaidEntitlementPolicyService;
use App\Services\TimelineEventRecorder;
use Closure;
use RuntimeException;

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
                    "Provider [".$provider::class."] does not implement the required capability contract [{$requiredContractFqcn}]."
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

            $this->events->record($firm, 'provider_billing.call_reserved', $reservation, $actor?->user, [
                'provider_key' => $providerKey->value,
                'capability' => $classification->capability(),
            ], independentOfAmbientTransaction: true);

            // STEP 13 — execute through OutboundProviderHttpClient ->
            // ProviderRequestExecutor. $providerCall() is always,
            // structurally, a call into that existing sanitizing
            // boundary — this pipeline never calls Http:: or duplicates
            // anything that layer or the executor beneath it already
            // does.
            $response = null;
            $caughtException = null;

            try {
                $response = $providerCall();
            } catch (SanitizedProviderHttpException $e) {
                $caughtException = $e;
            }

            // STEP 14 — normalize outcome.
            $outcome = $this->outcomeNormalizer->normalize($response, $caughtException);

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

            if ($caughtException !== null) {
                throw $caughtException;
            }

            return new ProviderBillableCallResult(
                response: $response,
                reservation: $reservation,
                outcome: $outcome,
                softLimitExceeded: $softLimitExceeded,
            );
        } finally {
            $lock->release();
        }
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
