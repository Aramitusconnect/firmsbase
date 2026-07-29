<?php

declare(strict_types=1);

namespace App\Integrations\Billing;

use App\Integrations\Contracts\SupportsBalanceContract;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Enums\SyncDirection;
use App\Integrations\Exceptions\ProviderInvalidOrExpiredConfirmationTokenException;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\ProviderBalanceSnapshot;
use App\Integrations\Services\FinancialIntegrationAccessPolicyService;
use App\Integrations\Services\ProviderTenantSafePolicyService;
use App\Integrations\Support\OutboundProviderHttpClient;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Services\PlaidEntitlementPolicyService;
use App\Services\TenantContextService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * ProviderLiveBalanceConfirmationService — the Live Balance-specific
 * safeguard service (checkpoint4-design-cost-control.md §5.3),
 * matching the product owner's own quoted requirement verbatim: "last
 * successful balance retrieval; cached balance age; included allowance
 * remaining; estimated customer charge or overage; cooldown remaining;
 * reason field; confirmation step."
 *
 * Two-call contract: `prepare()` runs the read-only, non-mutating
 * prefix of the pipeline (entitlement through limit-checking, but stops
 * before reservation) and mints a short-lived, single-use confirmation
 * token; `confirm()` requires that exact token and runs the FULL
 * `ProviderBillableCallPipeline::execute()`.
 *
 * `$provider` is accepted generically as `SupportsBalanceContract` (a
 * new, provider-agnostic contract this checkpoint also defines,
 * `App\Integrations\Contracts\SupportsBalanceContract`) — this class
 * has no compile-time dependency on `PlaidProvider` or any other
 * concrete provider class.
 */
final class ProviderLiveBalanceConfirmationService
{
    private const PRODUCT = 'balance';

    private const BILLING_OPERATION = 'get';

    private const TOKEN_TTL_SECONDS = 90;

    public function __construct(
        private readonly FinancialIntegrationAccessPolicyService $accessPolicy,
        private readonly ProviderTenantSafePolicyService $tenantSafePolicy,
        private readonly PlaidEntitlementPolicyService $entitlementPolicy,
        private readonly ProviderBillingClassifier $classifier,
        private readonly ProviderRateCardResolver $rateCardResolver,
        private readonly ProviderOperationPolicyResolver $operationPolicyResolver,
        private readonly ProviderCooldownService $cooldown,
        private readonly ProviderUsageLimitEnforcementService $limitEnforcement,
        private readonly ProviderBillableCallPipeline $pipeline,
    ) {}

    public function prepare(
        ProviderKey $providerKey,
        FirmIntegration $connection,
        Firm $firm,
        string $accountId,
        string $environment,
        FirmUser $actor,
    ): ProviderLiveBalanceConfirmationContext {
        $this->accessPolicy->assertCanView($actor);
        $this->tenantSafePolicy->assertConnectionBelongsToFirm($connection, $firm);
        $this->entitlementPolicy->assertEnabled($firm);

        $classification = $this->classifier->classify($providerKey, self::PRODUCT, self::BILLING_OPERATION);
        $rate = $this->rateCardResolver->resolve($providerKey, $classification, $environment, $firm);

        // Kill-switch / firm-suspension checks still throw here (a
        // genuinely blocked operation must never even reach a
        // confirmation screen), but cooldown/limit state below is
        // computed as DATA, never enforced — that enforcement happens
        // for real inside confirm()'s own full pipeline run.
        $policy = $this->operationPolicyResolver->resolve($providerKey, $classification, $firm, $environment);

        $cooldownRemaining = $this->cooldown->remainingSeconds($connection, $classification, $accountId);

        $currentTotal = $this->limitEnforcement->currentPeriodTotal($firm, $connection, $providerKey, $classification, $policy);
        $includedAllowanceRemaining = $policy->softLimitQuantity !== null
            ? max(0, $policy->softLimitQuantity - $currentTotal)
            : null;
        $isOverage = $policy->softLimitQuantity !== null && ($currentTotal + 1) > $policy->softLimitQuantity;

        $snapshot = $this->latestSnapshot($firm, $connection, $accountId);

        $token = (string) Str::uuid7();

        $context = new ProviderLiveBalanceConfirmationContext(
            lastSuccessfulRetrievalAt: $snapshot?->retrieved_at,
            cachedBalanceAgeSeconds: $snapshot !== null ? (int) $snapshot->retrieved_at->diffInSeconds(now()) : null,
            includedAllowanceRemaining: $includedAllowanceRemaining,
            estimatedCustomerChargeCents: $rate?->customer_price_cents,
            isOverage: $isOverage,
            cooldownRemainingSeconds: $cooldownRemaining,
            reasonRequired: $isOverage,
            confirmationToken: $token,
            confirmationTokenExpiresInSeconds: self::TOKEN_TTL_SECONDS,
        );

        Cache::put(
            $this->tokenKey($token),
            [
                'firm_id' => $firm->id,
                'firm_integration_id' => $connection->id,
                'account_id' => $accountId,
                'actor_id' => $actor->id,
            ],
            self::TOKEN_TTL_SECONDS,
        );

        return $context;
    }

    public function confirm(
        ProviderKey $providerKey,
        FirmIntegration $connection,
        Firm $firm,
        string $accountId,
        string $environment,
        FirmUser $actor,
        SupportsBalanceContract $provider,
        string $confirmationToken,
        ?string $reason = null,
    ): ProviderBillableCallResult {
        $tokenPayload = Cache::pull($this->tokenKey($confirmationToken));

        if (
            $tokenPayload === null
            || (int) $tokenPayload['firm_id'] !== (int) $firm->id
            || (int) $tokenPayload['firm_integration_id'] !== (int) $connection->id
            || $tokenPayload['account_id'] !== $accountId
            || (int) $tokenPayload['actor_id'] !== (int) $actor->id
        ) {
            throw new ProviderInvalidOrExpiredConfirmationTokenException;
        }

        $idempotencyKey = "provider_live_balance:{$connection->id}:{$accountId}:{$confirmationToken}";

        $result = $this->pipeline->execute(
            providerKey: $providerKey,
            connection: $connection,
            firm: $firm,
            actor: $actor,
            product: self::PRODUCT,
            billingOperation: self::BILLING_OPERATION,
            environment: $environment,
            direction: SyncDirection::Inbound,
            resourceType: null,
            providerCall: fn () => app(OutboundProviderHttpClient::class)->execute(
                fn () => $provider->fetchBalance($connection, $accountId, ['connection' => $connection, 'account_id' => $accountId]),
                'fetchBalance',
            ),
            usageIdempotencyKey: $idempotencyKey,
            quantity: 1,
            confirmationToken: $confirmationToken,
            confirmationReason: $reason,
            provider: $provider,
            requiredContractFqcn: SupportsBalanceContract::class,
            accountId: $accountId,
        );

        if ($result->outcome->certain && $result->outcome->billable && is_array($result->response)) {
            $this->persistBalanceSnapshot($firm, $connection, $accountId, $result->response);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $balancePayload
     */
    private function persistBalanceSnapshot(Firm $firm, FirmIntegration $connection, string $accountId, array $balancePayload): void
    {
        (new TenantContextService)->runWithFirmContext($firm, function () use ($firm, $connection, $accountId, $balancePayload) {
            ProviderBalanceSnapshot::query()->updateOrCreate(
                [
                    'firm_integration_id' => $connection->id,
                    'account_id' => $accountId,
                ],
                [
                    'firm_id' => $firm->id,
                    'available_cents' => $balancePayload['available_cents'] ?? null,
                    'current_cents' => $balancePayload['current_cents'] ?? null,
                    'iso_currency_code' => $balancePayload['iso_currency_code'] ?? null,
                    'retrieved_at' => now(),
                ],
            );
        });
    }

    private function latestSnapshot(Firm $firm, FirmIntegration $connection, string $accountId): ?ProviderBalanceSnapshot
    {
        return (new TenantContextService)->runWithFirmContext($firm, function () use ($connection, $accountId) {
            return ProviderBalanceSnapshot::query()
                ->where('firm_integration_id', $connection->id)
                ->where('account_id', $accountId)
                ->first();
        });
    }

    private function tokenKey(string $token): string
    {
        return "provider-live-balance-confirmation:{$token}";
    }
}
