<?php

declare(strict_types=1);

namespace App\Integrations\Support;

use App\Integrations\Enums\ProviderKey;
use App\Integrations\Exceptions\ProviderEnvironmentMisconfiguredException;

/**
 * ProviderEnvironmentResolver — the ONLY source of truth for a real
 * provider's sandbox-vs-live base URL and mode (checkpoint1-design-health-sandbox.md
 * §B.2; checkpoint1-combined-design.md §1 step 1, Finding 1 correction).
 * Mirrors `App\Integrations\Core\ProviderRegistry`'s own discipline
 * ("that map is the ONLY source of class names this class will ever
 * instantiate"), applied one layer down, to base URLs instead of class
 * names: a provider adapter must never hardcode or accept a base URL
 * that bypasses this class, and `ProviderRequestExecutor::send()` calls
 * `assertUrlAllowedFor()` as the FIRST step of every outbound request —
 * before the rate limiter, before any HTTP call is built — so a
 * misconfigured or hostile URL is rejected before it can consume any
 * budget or reach the network.
 *
 * Config-driven only, reading `config('integrations.provider_environments')`
 * — currently an empty array (no real ProviderKey case exists yet
 * beyond `Test`, which deliberately has no entry here at all; see
 * hasConfiguredEnvironment()). Checkpoints 2-5 each add their own
 * `provider_environments` config block; nothing on this class needs to
 * change for a new provider to plug in.
 *
 * `assertUrlAllowedFor()`'s comparison algorithm is the SECURITY-REVIEW-
 * CORRECTED version (checkpoint1-security-review.md Finding 1) — modeled
 * directly on `App\Integrations\Support\ProviderRedirectUrlValidator`'s
 * already-audited `parse_url()`-based structured comparison, NEVER a raw
 * `str_starts_with()` on the full URL string (which the original design
 * draft proposed and which is bypassable by a subdomain-suffix host,
 * e.g. `https://sandbox-api.example.com.attacker.com/` passing a naive
 * prefix match against `https://sandbox-api.example.com`). Scheme, host,
 * and port must match EXACTLY (case-insensitive); the path is then
 * checked with a boundary-anchored prefix match so `/basev2` can never
 * match a configured prefix of `/base`.
 *
 * FirmsVault Live Integrations, Checkpoint 2 widening
 * (checkpoint2-combined-design.md §2 P-8; checkpoint2-security-review.md
 * Finding 4, P1 required correction): `provider_environments`'s per-mode
 * URL config shape widens from a singular `sandbox_base_url`/
 * `live_base_url` string to a purpose-keyed `sandbox_base_urls`/
 * `live_base_urls` array — needed because a provider like Microsoft 365
 * genuinely has TWO distinct allowlisted hosts per mode (an identity
 * host, `login.microsoftonline.com`, and a resource-API host,
 * `graph.microsoft.com`), which a single base URL cannot represent.
 * `baseUrlFor()`/`modeFor()`/`assertUrlAllowedFor()` all gain an
 * optional `string $purpose = 'default'` parameter; a provider with a
 * genuinely single-host shape (e.g. Plaid) configures only a `'default'`
 * key and every call site may omit `$purpose` entirely, preserving
 * today's exact single-host behavior byte-for-byte.
 *
 * REQUIRED FAIL-CLOSED RULE (security review Finding 4, P1): requesting
 * a specific, non-`'default'` `$purpose` whose key is absent from the
 * provider's configured `sandbox_base_urls`/`live_base_urls` array MUST
 * throw `ProviderEnvironmentMisconfiguredException` — it must NEVER
 * silently fall back to a `'default'` key. `baseUrlFor()` below performs
 * a direct `$urls[$purpose] ?? throw`, with no fallback branch to any
 * other key, by construction — there is no code path in this class that
 * could resolve a requested purpose to a DIFFERENT key's URL. As an
 * operational convention (not itself mechanically enforced by this
 * class), a provider's `provider_environments` config block should
 * define EITHER a bare `'default'` key (a genuinely single-host
 * provider) OR a closed set of named purposes (e.g. Microsoft's
 * `'identity'`/`'graph'`) — never both in the same block, removing the
 * "forgot to pass the right purpose, silently validated against the
 * wrong host" ambiguity Finding 4 describes, structurally, at the
 * config-authoring level, rather than relying on every future call site
 * remembering to pass the right purpose.
 */
final class ProviderEnvironmentResolver
{
    /**
     * True only if `config('integrations.provider_environments')` has an
     * entry for this key at all. Callers that must tolerate "this
     * provider has no environment concept configured" gracefully (e.g.
     * `IntegrationCredentialService::decryptForOperation()`'s mode-
     * consistency check, which must never throw for every existing
     * TestProvider-backed credential) check this BEFORE calling
     * modeFor()/baseUrlFor(), which both throw when unconfigured.
     */
    public function hasConfiguredEnvironment(ProviderKey $key): bool
    {
        return is_array(config("integrations.provider_environments.{$key->value}"));
    }

    /**
     * The raw 'sandbox'|'live' mode value for $key. Throws
     * ProviderEnvironmentMisconfiguredException if $key has no
     * configuration entry at all, or if the entry's `mode` is missing or
     * not one of the two valid values — never silently defaults to
     * either mode when the configuration itself is absent or malformed.
     *
     * Checkpoint 2 note: `$purpose` is accepted for signature parity
     * with `baseUrlFor()`/`assertUrlAllowedFor()` (a caller may pass the
     * same `$purpose` value to all three uniformly), but is NOT
     * currently used in this method's own logic — a provider's `mode`
     * (sandbox vs. live) is a single, whole-provider setting in today's
     * config shape, never purpose-specific (unlike the base URL itself,
     * which genuinely does vary by purpose for a dual-host provider like
     * Microsoft 365). Reserved for forward compatibility only.
     */
    public function modeFor(ProviderKey $key, string $purpose = 'default'): string
    {
        $config = $this->configFor($key);
        $mode = $config['mode'] ?? null;

        if (! is_string($mode) || ! in_array($mode, ['sandbox', 'live'], true)) {
            throw new ProviderEnvironmentMisconfiguredException(
                "Provider \"{$key->value}\" has no valid environment mode configured; expected \"sandbox\" or \"live\"."
            );
        }

        return $mode;
    }

    /**
     * The base URL matching $key's CURRENTLY configured mode, for the
     * given $purpose. Throws ProviderEnvironmentMisconfiguredException
     * if the resolved mode's purpose-keyed URL is missing/null/empty —
     * NEVER silently falls back to the other mode's URL (defeats the
     * sandbox/live isolation guarantee) and NEVER silently falls back to
     * a different purpose's key, including `'default'` (security review
     * Finding 4, P1 — see this class's own docblock). A provider with no
     * `sandbox_base_urls`/`live_base_urls` array configured at all for
     * the resolved mode also throws here, exactly as the prior
     * singular-string shape did for a missing `sandbox_base_url`/
     * `live_base_url`.
     */
    public function baseUrlFor(ProviderKey $key, string $purpose = 'default'): string
    {
        $mode = $this->modeFor($key, $purpose);
        $config = $this->configFor($key);
        $urlConfigKey = $mode === 'live' ? 'live_base_urls' : 'sandbox_base_urls';
        $urls = $config[$urlConfigKey] ?? null;

        if (! is_array($urls)) {
            throw new ProviderEnvironmentMisconfiguredException(
                "Provider \"{$key->value}\" is configured for the \"{$mode}\" environment but has no \"{$urlConfigKey}\" array set."
            );
        }

        // Deliberately a direct, unconditional lookup — no `?? $urls['default']`
        // fallback branch exists anywhere in this method. Requesting a
        // specific, non-default $purpose whose key is absent MUST throw,
        // never silently resolve against a different purpose's URL
        // (security review Finding 4, P1 required correction).
        $url = $urls[$purpose] ?? null;

        if (! is_string($url) || trim($url) === '') {
            throw new ProviderEnvironmentMisconfiguredException(
                "Provider \"{$key->value}\" is configured for the \"{$mode}\" environment but has no \"{$urlConfigKey}.{$purpose}\" set."
            );
        }

        return $url;
    }

    /**
     * The actual enforcement mechanism (checkpoint1-security-review.md
     * Finding 1's corrected algorithm). Recomputes the expected base URL
     * for $key's CURRENTLY configured mode independently — never trusts
     * a value passed in — then requires:
     *
     *   1. An EXACT, case-insensitive match on scheme + host + port
     *      (never a substring/prefix match on the host).
     *   2. A boundary-anchored prefix match on the path: the candidate
     *      URL's path must start with the expected path, and the
     *      character immediately following that matched prefix must be
     *      `/`, `?`, `#`, or end-of-string — so `/basev2` can never
     *      match a configured prefix of `/base`. A single trailing
     *      slash on the configured base path is normalized away first,
     *      so inconsistent trailing-slash configuration across
     *      different providers' config blocks can never produce a
     *      false rejection.
     *
     * Throws ProviderEnvironmentMisconfiguredException on any mismatch
     * or parse failure — deliberately the SAME exception type
     * baseUrlFor()/modeFor() throw for an actual config gap, per
     * checkpoint1-combined-design.md §1 step 1: a URL that fails this
     * guard is always treated as a configuration/environment problem,
     * never mapped into SanitizedProviderHttpException's retryable/
     * terminal vocabulary.
     */
    public function assertUrlAllowedFor(ProviderKey $key, string $url, string $purpose = 'default'): void
    {
        $expectedBaseUrl = $this->baseUrlFor($key, $purpose);

        $expectedParts = parse_url($expectedBaseUrl);
        $candidateParts = parse_url($url);

        if ($expectedParts === false || $candidateParts === false
            || ! isset($expectedParts['scheme'], $expectedParts['host'], $candidateParts['scheme'], $candidateParts['host'])) {
            throw new ProviderEnvironmentMisconfiguredException(
                "Provider \"{$key->value}\": the outbound request URL could not be validated against its configured base URL."
            );
        }

        $expectedScheme = strtolower($expectedParts['scheme']);
        $candidateScheme = strtolower($candidateParts['scheme']);

        if ($expectedScheme !== $candidateScheme) {
            throw new ProviderEnvironmentMisconfiguredException(
                "Provider \"{$key->value}\": outbound request scheme does not match the configured base URL's scheme."
            );
        }

        $expectedHost = strtolower($expectedParts['host']);
        $candidateHost = strtolower($candidateParts['host']);

        if ($expectedHost !== $candidateHost) {
            throw new ProviderEnvironmentMisconfiguredException(
                "Provider \"{$key->value}\": outbound request host does not exactly match the configured base URL's host."
            );
        }

        $expectedPort = $expectedParts['port'] ?? $this->defaultPortForScheme($expectedScheme);
        $candidatePort = $candidateParts['port'] ?? $this->defaultPortForScheme($candidateScheme);

        if ($expectedPort !== $candidatePort) {
            throw new ProviderEnvironmentMisconfiguredException(
                "Provider \"{$key->value}\": outbound request port does not match the configured base URL's port."
            );
        }

        $expectedPath = $this->normalizeBasePath($expectedParts['path'] ?? '');
        $candidatePath = $candidateParts['path'] ?? '';

        if (! str_starts_with($candidatePath, $expectedPath)) {
            throw new ProviderEnvironmentMisconfiguredException(
                "Provider \"{$key->value}\": outbound request path does not start with the configured base URL's path."
            );
        }

        $remainder = substr($candidatePath, strlen($expectedPath));

        if ($remainder !== '' && ! in_array($remainder[0], ['/', '?', '#'], true)) {
            throw new ProviderEnvironmentMisconfiguredException(
                "Provider \"{$key->value}\": outbound request path extends the configured base URL's path without a boundary separator."
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function configFor(ProviderKey $key): array
    {
        $config = config("integrations.provider_environments.{$key->value}");

        if (! is_array($config)) {
            throw new ProviderEnvironmentMisconfiguredException(
                "No provider_environments configuration exists for provider \"{$key->value}\"."
            );
        }

        return $config;
    }

    /**
     * Strips a single trailing slash from a non-root base path so a
     * configured `sandbox_base_url` of either `https://host/base` or
     * `https://host/base/` produces IDENTICAL boundary-matching
     * behavior — closes the "inconsistent trailing-slash configuration
     * across four providers... is a likely footgun" concern raised
     * directly against this class (checkpoint1-security-review.md
     * Finding 1).
     */
    private function normalizeBasePath(string $path): string
    {
        if ($path === '' || $path === '/') {
            return '';
        }

        return rtrim($path, '/');
    }

    private function defaultPortForScheme(string $scheme): ?int
    {
        return match ($scheme) {
            'http' => 80,
            'https' => 443,
            default => null,
        };
    }
}
