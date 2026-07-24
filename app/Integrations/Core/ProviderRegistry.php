<?php

declare(strict_types=1);

namespace App\Integrations\Core;

use App\Integrations\Contracts\IntegrationProviderContract;
use App\Integrations\Data\ProviderMetadata;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Exceptions\UnknownProviderException;

/**
 * ProviderRegistry — deliberately thin, no god-class, no
 * provider-string behavior branching
 * (checkpoint-00-final-specification.md §8, provider-contracts.md
 * "Registry Design").
 *
 * Resolves a ProviderKey to a provider instance strictly via
 * `config('integrations.providers')`, an array of
 * [ProviderKey-string => FQCN]. That map is the ONLY source of class
 * names this class will ever instantiate — get() never accepts or
 * derives a class name from any other input, so it can never be used
 * to dynamically instantiate an arbitrary class from unvalidated data.
 *
 * The config map itself may use a plain array lookup to find a
 * registered class name — that is wiring, not behavior. What this
 * class must never do is branch on the key to decide *what a provider
 * does* (e.g. no `match ($key) { ProviderKey::Test => ... }` containing
 * actual capability logic) — all such logic belongs on the provider
 * class itself, discovered polymorphically via `instanceof` against
 * the `Supports*` capability contracts.
 */
final class ProviderRegistry
{
    /**
     * Resolve a provider key to its registered instance via the
     * container. Throws UnknownProviderException if the key has no
     * entry (or a null entry, e.g. an environment-gated provider that
     * is currently disabled) in the config-driven map.
     */
    public function get(ProviderKey $key): IntegrationProviderContract
    {
        $class = $this->registeredMap()[$key->value] ?? null;

        if ($class === null) {
            throw new UnknownProviderException($key->value);
        }

        return app()->make($class);
    }

    /**
     * Checkpoint 12 addition (frozen-design-post-security-review.md
     * §2 F1): a non-throwing existence check — "is this key currently
     * resolvable" — for callers (e.g. ConnectProviderAction's dropdown
     * filter) that need to test resolvability without catching
     * UnknownProviderException as control flow. Never changes get()'s
     * existing throwing contract; purely additive, reuses the exact
     * same registeredMap() filtering get() itself relies on.
     */
    public function has(ProviderKey $key): bool
    {
        return array_key_exists($key->value, $this->registeredMap());
    }

    /**
     * Build ProviderMetadata for a registered key by reflecting on the
     * resolved instance's actually-implemented capability interfaces —
     * never a hardcoded per-provider capability list.
     */
    public function metadataFor(ProviderKey $key): ProviderMetadata
    {
        return ProviderMetadata::fromProvider($this->get($key));
    }

    /**
     * @return ProviderMetadata[] metadata for every currently
     *                            registered (non-null-mapped) provider.
     */
    public function all(): array
    {
        return array_map(
            fn (string $keyValue): ProviderMetadata => $this->metadataFor(ProviderKey::from($keyValue)),
            array_keys($this->registeredMap()),
        );
    }

    /**
     * The config-driven map of registered provider classes, with any
     * environment-gated-off entries (a null class name) filtered out.
     * This filtering — not a hardcoded provider list — is what makes a
     * provider key "known" to this registry at all.
     *
     * @return array<string, class-string<IntegrationProviderContract>>
     */
    private function registeredMap(): array
    {
        /** @var array<string, class-string<IntegrationProviderContract>|null> $configured */
        $configured = config('integrations.providers', []);

        return array_filter($configured, static fn (?string $class): bool => $class !== null);
    }
}
