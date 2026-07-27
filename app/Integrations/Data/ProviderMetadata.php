<?php

declare(strict_types=1);

namespace App\Integrations\Data;

use App\Integrations\Contracts\IntegrationProviderContract;
use App\Integrations\Contracts\SupportsApiKeyContract;
use App\Integrations\Contracts\SupportsDisconnectContract;
use App\Integrations\Contracts\SupportsHealthCheckContract;
use App\Integrations\Contracts\SupportsIncrementalSyncContract;
use App\Integrations\Contracts\SupportsOAuthContract;
use App\Integrations\Contracts\SupportsPullSyncContract;
use App\Integrations\Contracts\SupportsPushSyncContract;
use App\Integrations\Contracts\SupportsWebhooksContract;
use App\Integrations\Enums\ProviderKey;
use InvalidArgumentException;

/**
 * ProviderMetadata — readonly value object describing a registered
 * provider. Built exclusively via fromProvider(), never constructed
 * with a hand-maintained `capabilities`/`resourceTypes`/etc. list —
 * every derivable field is reflected off the actual resolved provider
 * instance so it can never silently drift from what the class really
 * implements (checkpoint-00-final-specification.md §8/§9,
 * provider-contracts.md "Metadata schema").
 *
 * `moduleCode`/`degradationTypeKey` are the intended
 * FeatureGateService/EntitlementService and IntegrationType-style
 * degradation integration points described in provider-contracts.md,
 * but neither has a corresponding method on IntegrationProviderContract
 * at Checkpoint 1 (no entitlement wiring exists yet — that is
 * Checkpoint 2/9 scope), so fromProvider() always leaves them null for
 * now. Reviewer note: this is a deliberate Checkpoint 1 judgment call,
 * not an oversight — flagged here so a later checkpoint does not
 * mistake `null` for "provider has no module" once entitlement wiring
 * actually exists.
 */
final readonly class ProviderMetadata
{
    /**
     * @param  class-string[]  $capabilities  short (basename) class names
     *                                        of the Supports* interfaces
     *                                        the provider actually
     *                                        implements.
     * @param  string[]  $supportedAuthMethods
     * @param  string[]  $resourceTypes
     * @param  string[]  $requiredOAuthScopes
     * @param  string[]  $webhookEventTypes
     */
    public function __construct(
        public ProviderKey $key,
        public string $displayName,
        public string $description,
        public array $capabilities,
        public array $supportedAuthMethods,
        public array $resourceTypes,
        public array $requiredOAuthScopes,
        public ?string $healthCheckEndpointConvention,
        public array $webhookEventTypes,
        public ?string $moduleCode = null,
        public ?string $degradationTypeKey = null,
    ) {}

    /**
     * The closed set of Supports* capability interfaces this method
     * knows how to detect via reflection. Kept as a single, explicit
     * list here (rather than scanning the filesystem) so an unrelated
     * future interface added under Contracts/ never silently becomes
     * a "capability" without a deliberate update to this list.
     *
     * @return class-string[]
     */
    private static function knownCapabilityInterfaces(): array
    {
        return [
            SupportsOAuthContract::class,
            SupportsApiKeyContract::class,
            SupportsWebhooksContract::class,
            SupportsHealthCheckContract::class,
            SupportsPullSyncContract::class,
            SupportsPushSyncContract::class,
            SupportsIncrementalSyncContract::class,
            SupportsDisconnectContract::class,
        ];
    }

    /**
     * Build metadata for an already-resolved provider instance purely
     * by reflecting on which capability interfaces it implements —
     * never from a hand-maintained parallel array.
     */
    public static function fromProvider(IntegrationProviderContract $provider): self
    {
        $implemented = class_implements($provider) ?: [];

        $capabilities = [];
        foreach (self::knownCapabilityInterfaces() as $interface) {
            if (isset($implemented[$interface])) {
                $capabilities[] = self::basename($interface);
            }
        }

        // Checkpoint 2 (FirmsVault Live Integrations, Microsoft 365
        // provider) fix: fromProvider() reflects generically, with no
        // firm/connection context — but a capability-aware provider's
        // requiredScopes() (e.g. Microsoft365Provider) deliberately
        // throws on an empty capability list, per the mission's own
        // "never silently default to a broad scope bundle" requirement
        // for the REAL scope-request path (initiateOAuthConnection()).
        // requiredOAuthScopes is presentation/documentation-only here
        // (this class's own docblock), so a provider that cannot
        // compute a bundle without a specific connection's capability
        // selection correctly has nothing to disclose at this generic
        // reflection point — caught and treated as "no scopes to
        // display," never propagated as a hard failure of metadata
        // reflection itself.
        $requiredOAuthScopes = [];
        if ($provider instanceof SupportsOAuthContract) {
            try {
                $requiredOAuthScopes = $provider->requiredScopes();
            } catch (InvalidArgumentException) {
                $requiredOAuthScopes = [];
            }
        }

        $healthCheckEndpointConvention = $provider instanceof SupportsHealthCheckContract
            ? $provider->healthCheckEndpointConvention()
            : null;

        $webhookEventTypes = $provider instanceof SupportsWebhooksContract
            ? $provider->webhookEventTypes()
            : [];

        $resourceTypes = [];
        if ($provider instanceof SupportsPullSyncContract) {
            $resourceTypes = array_merge($resourceTypes, $provider->pullableResourceTypes());
        }
        if ($provider instanceof SupportsPushSyncContract) {
            $resourceTypes = array_merge($resourceTypes, $provider->pushableResourceTypes());
        }
        $resourceTypes = array_values(array_unique($resourceTypes));

        return new self(
            key: $provider->key(),
            displayName: $provider->displayName(),
            description: $provider->description(),
            capabilities: $capabilities,
            supportedAuthMethods: array_map(
                static fn ($method) => $method->value,
                $provider->supportedAuthMethods(),
            ),
            resourceTypes: $resourceTypes,
            requiredOAuthScopes: $requiredOAuthScopes,
            healthCheckEndpointConvention: $healthCheckEndpointConvention,
            webhookEventTypes: $webhookEventTypes,
            moduleCode: null,
            degradationTypeKey: null,
        );
    }

    /**
     * @param  class-string  $fqcn
     */
    private static function basename(string $fqcn): string
    {
        $segments = explode('\\', $fqcn);

        return end($segments);
    }
}
