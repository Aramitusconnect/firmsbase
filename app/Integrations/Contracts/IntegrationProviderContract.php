<?php

declare(strict_types=1);

namespace App\Integrations\Contracts;

use App\Integrations\Enums\AuthMethod;
use App\Integrations\Enums\ProviderKey;

/**
 * IntegrationProviderContract — the root contract every registered
 * integration provider must implement. Deliberately minimal: identity,
 * display metadata, platform-level configuration readiness, and the
 * auth methods it supports. Every other capability (OAuth, API keys,
 * webhooks, health checks, sync, disconnect) is an orthogonal, optional
 * `Supports*` interface a provider implements only if it applies — see
 * provider-contracts.md and checkpoint-00-final-specification.md §9.
 *
 * Core framework code (ProviderRegistry, orchestrator services) must
 * never branch on a provider's key/identity to decide behavior; it may
 * only call this contract's methods and check `instanceof` against the
 * `Supports*` interfaces.
 */
interface IntegrationProviderContract
{
    /**
     * The provider's stable, immutable registry identity.
     */
    public function key(): ProviderKey;

    /**
     * Human-readable name suitable for display in firm-facing and
     * SuperAdmin UI (a later checkpoint's concern) — must never be
     * ambiguous about whether the provider is real or internal-only.
     */
    public function displayName(): string;

    /**
     * Human-readable description of what this provider does.
     */
    public function description(): string;

    /**
     * Whether this provider is currently permitted to run at all at
     * the platform level (e.g. required app-level credentials/
     * environment flags are present) — independent of any given firm's
     * per-connection state, which is out of scope until Checkpoint 3.
     */
    public function isConfigured(): bool;

    /**
     * @return AuthMethod[] the authentication method(s) this provider
     *                      supports, in no particular priority order.
     */
    public function supportedAuthMethods(): array;
}
