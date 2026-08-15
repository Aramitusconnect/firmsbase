<?php

declare(strict_types=1);

namespace App\Filament\Support\Integrations;

use App\Integrations\Billing\ProviderBillingClassifier;
use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\ProviderKillSwitch;
use App\Models\Firm;
use App\Services\TenantContextService;
use Illuminate\Support\Str;
use Throwable;

/**
 * ProviderKillSwitchScope — the single place that answers, for the
 * SuperAdmin kill-switch console, three questions the previous UI simply
 * did not ask:
 *
 *   1. WHICH TARGETS ACTUALLY EXIST? The create form used a free-text
 *      TextInput for `target`. A kill switch is enforced by an EXACT
 *      string equality match — `->where('target', $target)` — in both
 *      enforcement points (ProviderRequestExecutor::send() and
 *      ProviderOperationPolicyResolver::assertNoPlatformKillSwitchActive()).
 *      So a single typo ("transaction" for "transactions",
 *      "core-banking-data" for "core_banking_data") produced a row that
 *      LOOKS active in the console, reports itself as suspended, and
 *      silently suspends nothing at all. During an incident that is the
 *      worst possible failure: the operator believes the provider is
 *      stopped while calls keep flowing. Mission §66: "A UI scope that
 *      backend enforcement ignores is a SECURITY BUG."
 *
 *      Every option below is derived from the code that actually
 *      enforces it, never from a hand-typed parallel list:
 *        - LEVEL_PROVIDER's target is the ProviderKey value itself
 *          (ProviderRequestExecutor matches `target === $providerKey->value`).
 *        - LEVEL_PRODUCT / LEVEL_ENDPOINT_CATEGORY / LEVEL_OPERATION
 *          targets come from ProviderBillingClassifier's own governed
 *          vocabulary plus the products real call sites genuinely pass
 *          into ProviderBillableCallPipeline::execute().
 *
 *   2. WHICH SCOPES ARE ENFORCED? `ProviderKillSwitch` defines both
 *      SCOPE_PLATFORM and SCOPE_FIRM, but BOTH enforcement points filter
 *      `->where('scope_type', ProviderKillSwitch::SCOPE_PLATFORM)
 *       ->whereNull('scope_id')`. A firm-scoped row is therefore read by
 *      nothing, anywhere. This class deliberately exposes ONLY the
 *      platform scope, and says so, rather than offering an operator a
 *      per-firm suspension the backend would ignore. (The genuine
 *      per-firm mechanism is a different table entirely —
 *      `provider_firm_operation_policies.optional_operation_suspended`,
 *      see ProviderOptionalOperationSuspendedException's docblock.)
 *
 *   3. WHAT WILL THIS ACTUALLY STOP? impactPreview() counts real firms
 *      and real active connections for the chosen provider so the
 *      confirmation dialog states measured blast radius rather than an
 *      abstract warning (§67).
 */
final class ProviderKillSwitchScope
{
    /**
     * The ONLY scope this console will ever write. See reason (2) in the
     * class docblock — SCOPE_FIRM exists on the model but is read by no
     * enforcement path, so offering it would be a lie.
     */
    public const ENFORCED_SCOPE = ProviderKillSwitch::SCOPE_PLATFORM;

    /**
     * `billingOperation` strings real call sites pass into
     * ProviderBillableCallPipeline::execute(), used to compose a
     * LEVEL_OPERATION target (`"{product}:{billingOperation}"`, exactly
     * as ProviderOperationPolicyResolver composes it when matching):
     *   - 'sync'      — PullSyncJob's incremental/initial pull
     *   - 'subscribe' — ProviderConnectionService + RenewGraphSubscriptionJob
     *   - 'get'       — ProviderLiveBalanceConfirmationService
     *
     * @var array<string, string>
     */
    private const BILLING_OPERATIONS = [
        'sync' => 'sync — scheduled/triggered pull sync',
        'subscribe' => 'subscribe — webhook subscription create/renew',
        'get' => 'get — single on-demand fetch',
    ];

    /**
     * Products genuinely passed to the billing pipeline. The first
     * thirteen are ProviderBillingClassifier's own governed vocabulary
     * (its ENDPOINT_CATEGORY_MAP keys); `webhook_subscribe` is a real
     * product string that no rate card prices but that
     * ProviderConnectionService/RenewGraphSubscriptionJob genuinely pass
     * — and which therefore genuinely IS killable at product level, for
     * Microsoft 365 and Google Workspace as much as for Plaid.
     *
     * @var array<int, string>
     */
    private const PIPELINE_PRODUCTS = [
        'auth',
        'balance',
        'enrich',
        'identity',
        'identity_match',
        'identity_verification',
        'income',
        'investments',
        'item',
        'liabilities',
        'monitor',
        'statements',
        'transactions',
        'webhook_subscribe',
    ];

    /**
     * Levels an operator may create, each with the honest, specific
     * disclosure of WHERE it is enforced — so nobody has to read
     * ProviderRequestExecutor to know that the provider level is the
     * only one that stops every outbound call regardless of billing
     * wiring.
     *
     * @return array<string, string>
     */
    public static function levelOptions(): array
    {
        return [
            ProviderKillSwitch::LEVEL_PROVIDER => 'Entire provider — stops every outbound call to this provider',
            ProviderKillSwitch::LEVEL_PRODUCT => 'Product — stops metered calls for one product',
            ProviderKillSwitch::LEVEL_ENDPOINT_CATEGORY => 'Endpoint category — stops metered calls for a group of products',
            ProviderKillSwitch::LEVEL_OPERATION => 'Operation — stops one product/operation pair',
        ];
    }

    /**
     * Where each level is actually checked, in plain language, shown
     * beneath the level picker and repeated in the confirmation modal.
     */
    public static function enforcementDisclosure(?string $level): string
    {
        return match ($level) {
            ProviderKillSwitch::LEVEL_PROVIDER => 'Enforced in ProviderRequestExecutor::send(), the single outbound path every provider adapter shares. '
                .'This is the only level that stops calls for a provider that has no billing-pipeline wiring at all.',
            ProviderKillSwitch::LEVEL_PRODUCT,
            ProviderKillSwitch::LEVEL_ENDPOINT_CATEGORY,
            ProviderKillSwitch::LEVEL_OPERATION => 'Enforced in ProviderOperationPolicyResolver, reached only through ProviderBillableCallPipeline. '
                .'Calls that do not route through the billing pipeline are NOT stopped by this level — use "Entire provider" if you need a hard stop.',
            default => 'Select a level to see exactly where it is enforced.',
        };
    }

    /**
     * The validated target options for a chosen level, or null when the
     * level does not take an operator-chosen target (LEVEL_PROVIDER,
     * whose target is always the provider key itself).
     *
     * @return array<string, string>|null
     */
    public static function targetOptions(?string $level): ?array
    {
        return match ($level) {
            ProviderKillSwitch::LEVEL_PRODUCT => self::productOptions(),
            ProviderKillSwitch::LEVEL_ENDPOINT_CATEGORY => self::endpointCategoryOptions(),
            ProviderKillSwitch::LEVEL_OPERATION => self::operationOptions(),
            default => null,
        };
    }

    /**
     * @return array<string, string>
     */
    public static function productOptions(): array
    {
        $options = [];

        foreach (self::PIPELINE_PRODUCTS as $product) {
            $options[$product] = Str::headline($product)." ({$product})";
        }

        return $options;
    }

    /**
     * Endpoint categories, read off ProviderBillingClassifier's own map
     * by classifying each known product — never a second hand-maintained
     * copy of the category list, so a category added to the classifier
     * appears here automatically.
     *
     * @return array<string, string>
     */
    public static function endpointCategoryOptions(): array
    {
        $classifier = new ProviderBillingClassifier;
        $options = [];

        foreach (self::PIPELINE_PRODUCTS as $product) {
            $category = $classifier->classify(ProviderKey::Plaid, $product, 'sync')->endpointCategory;
            $options[$category] = Str::headline($category)." ({$category})";
        }

        ksort($options);

        return $options;
    }

    /**
     * `product:operation` pairs, composed exactly the way
     * ProviderOperationPolicyResolver composes the string it matches
     * against. Only pairs a real call site can actually produce are
     * offered — an operator cannot create a switch for
     * `statements:subscribe`, which nothing will ever emit.
     *
     * @return array<string, string>
     */
    public static function operationOptions(): array
    {
        $options = [];

        foreach (self::PIPELINE_PRODUCTS as $product) {
            foreach (self::realOperationsFor($product) as $operation) {
                $target = "{$product}:{$operation}";
                $options[$target] = $target.' — '.self::BILLING_OPERATIONS[$operation];
            }
        }

        ksort($options);

        return $options;
    }

    /**
     * Validates that a level/target pair is one this console offered and
     * that enforcement can actually match. Called server-side inside the
     * action closure, never relying on the form's own option list alone
     * (a Livewire payload is client-supplied input like any other).
     */
    public static function isEnforceableTarget(string $providerKey, string $level, string $target): bool
    {
        if ($level === ProviderKillSwitch::LEVEL_PROVIDER) {
            // ProviderRequestExecutor matches target === provider key.
            return $target === $providerKey;
        }

        $options = self::targetOptions($level);

        return $options !== null && array_key_exists($target, $options);
    }

    /**
     * Real, measured blast radius for a provider-level suspension:
     * how many activated firms hold a connection to this provider, and
     * how many of those connections are currently active.
     *
     * `firm_integrations` carries FORCE ROW LEVEL SECURITY with no
     * cross-firm-read policy, so this counts one firm at a time inside
     * that firm's own tenant context — the same per-firm-loop pattern
     * PlatformConnectionDirectoryService and
     * IntegrationPlatformProviderHealthSummaryService already establish.
     * It never opens an unscoped cross-firm query and never uses a
     * BYPASSRLS role.
     *
     * A firm whose context cannot be established is COUNTED AS
     * UNREADABLE and reported as such — never silently dropped, which
     * would understate blast radius on the one screen where
     * understating it is most dangerous.
     *
     * @return array{firms:int, active_connections:int, unreadable_firms:int, scanned_firms:int, truncated:bool}
     */
    public static function impactPreview(string $providerCode, int $maxFirmsScanned = 500): array
    {
        $tenantContext = new TenantContextService;

        $firms = 0;
        $activeConnections = 0;
        $unreadable = 0;

        $firmIds = Firm::query()->orderBy('id')->limit($maxFirmsScanned + 1)->pluck('id');
        $truncated = $firmIds->count() > $maxFirmsScanned;
        $firmIds = $firmIds->take($maxFirmsScanned);

        foreach ($firmIds as $firmId) {
            try {
                $counts = $tenantContext->runWithFirmContext((int) $firmId, function () use ($providerCode): array {
                    $rows = FirmIntegration::query()
                        ->join('integration_providers', 'integration_providers.id', '=', 'firm_integrations.integration_provider_id')
                        ->where('integration_providers.code', $providerCode)
                        ->selectRaw('count(*) as total')
                        ->selectRaw('count(*) filter (where firm_integrations.status = ?) as active', [ConnectionStatus::Active->value])
                        ->first();

                    return [
                        'total' => (int) ($rows->total ?? 0),
                        'active' => (int) ($rows->active ?? 0),
                    ];
                });
            } catch (Throwable) {
                // Provider-failure isolation (§28): one unreadable firm
                // must not take down the impact preview — but it MUST be
                // disclosed, not swallowed.
                $unreadable++;

                continue;
            }

            if ($counts['total'] > 0) {
                $firms++;
                $activeConnections += $counts['active'];
            }
        }

        return [
            'firms' => $firms,
            'active_connections' => $activeConnections,
            'unreadable_firms' => $unreadable,
            'scanned_firms' => $firmIds->count(),
            'truncated' => $truncated,
        ];
    }

    /**
     * Human-readable impact sentence for a confirmation modal. States
     * measurement limits explicitly rather than presenting a partial
     * scan as a complete one.
     */
    public static function impactSentence(string $providerCode): string
    {
        $impact = self::impactPreview($providerCode);

        $sentence = sprintf(
            'Measured impact: %d firm(s) hold a connection to this provider, with %d currently active connection(s).',
            $impact['firms'],
            $impact['active_connections'],
        );

        if ($impact['unreadable_firms'] > 0) {
            $sentence .= sprintf(' %d firm(s) could not be evaluated and are NOT included in these counts.', $impact['unreadable_firms']);
        }

        if ($impact['truncated']) {
            $sentence .= sprintf(' Only the first %d firms were scanned — the real impact may be larger.', $impact['scanned_firms']);
        }

        return $sentence;
    }

    /**
     * The operations a given product is genuinely called with, per the
     * real pipeline call sites enumerated in this class's docblock.
     *
     * @return array<int, string>
     */
    private static function realOperationsFor(string $product): array
    {
        return match ($product) {
            'webhook_subscribe' => ['subscribe'],
            'balance' => ['get', 'sync'],
            default => ['sync'],
        };
    }
}
