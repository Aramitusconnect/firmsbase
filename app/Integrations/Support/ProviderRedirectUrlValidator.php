<?php

declare(strict_types=1);

namespace App\Integrations\Support;

use InvalidArgumentException;

/**
 * ProviderRedirectUrlValidator — SSRF-safe validation for
 * `integration_oauth_states.redirect_uri` (checkpoint-00-final-specification.md
 * §6; agent-h-security-architecture-review.md item 19). Modeled on
 * App\Services\WebhookDestinationValidationService's shape (same
 * literal-IP-range checks, same "no DNS resolution performed" honesty),
 * but deliberately a SEPARATE, self-contained class under
 * App\Integrations\Support rather than a reuse/import of that service —
 * this is the one required behavioral difference, not a stylistic one.
 *
 * The TOCTOU gap that service's own docblock discloses (it validates a
 * destination URL ONCE, at subscribe/update time, and nothing re-checks
 * it at the later moment it is actually used — a hostname that resolves
 * safely today can repoint to a private/metadata IP before it is ever
 * dereferenced) does not apply to redirect_uri the same way, because
 * redirect_uri in this checkpoint's design is NEVER a caller/request-
 * suppliable value in the first place — it is always computed fresh
 * from `route(...)`, this application's OWN fixed OAuth callback URL
 * (see OAuthConnectionController). But this class still exists, and is
 * still called at BOTH points of use rather than once, as a deliberate
 * discipline against ever letting that invariant erode silently in a
 * future change:
 *
 *   1. At initiate time (IntegrationOAuthStateService::initiate()),
 *      immediately before the value is persisted to
 *      integration_oauth_states.redirect_uri and handed to the
 *      provider's authorizationUrl() builder.
 *   2. At claim time (ProviderConnectionService::completeOAuthCallback()),
 *      immediately before the CLAIMED row's stored redirect_uri is
 *      trusted for anything, re-validated fresh — never by trusting a
 *      cached "it was safe when I checked it earlier" boolean.
 *
 * Callers MUST follow this same discipline: call assertSafe() (and, for
 * the claim-time re-check, matchesExpected()) immediately before using
 * the URL, never once up front and then cached/reused across a later,
 * separate request or transaction boundary.
 */
final class ProviderRedirectUrlValidator
{
    private const PRIVATE_IPV4_RANGES = [
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
    ];

    private const LINK_LOCAL_METADATA_IPV4_RANGES = [
        '169.254.0.0/16',
    ];

    private const PRIVATE_LOOPBACK_IPV4_RANGES = [
        '127.0.0.0/8',
    ];

    private const PRIVATE_IPV6_RANGES = [
        'fc00::/7',
    ];

    private const LINK_LOCAL_IPV6_RANGES = [
        'fe80::/10',
    ];

    public function isSafe(string $url, bool $allowInsecureHttpForTesting = false): bool
    {
        try {
            $this->assertSafe($url, $allowInsecureHttpForTesting);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    public function assertSafe(string $url, bool $allowInsecureHttpForTesting = false): void
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException("Redirect URL '{$url}' could not be parsed.");
        }

        $scheme = strtolower($parts['scheme']);

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException("Redirect URL scheme '{$scheme}' is not allowed; only http/https are permitted.");
        }

        if ($scheme === 'http' && ! $allowInsecureHttpForTesting) {
            throw new InvalidArgumentException(
                'Plain http redirect URLs are not allowed unless allowInsecureHttpForTesting is explicitly true.'
            );
        }

        $host = $this->stripIpv6Brackets(strtolower($parts['host']));

        if ($host === 'localhost') {
            throw new InvalidArgumentException("Redirect host 'localhost' is not allowed.");
        }

        if ($host === '::1') {
            throw new InvalidArgumentException("Redirect host '::1' is not allowed.");
        }

        if ($host === '169.254.169.254') {
            throw new InvalidArgumentException('Redirect host is the cloud metadata address and is not allowed.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $this->assertIpv4NotInRanges($host, array_merge(
                self::PRIVATE_LOOPBACK_IPV4_RANGES,
                self::PRIVATE_IPV4_RANGES,
                self::LINK_LOCAL_METADATA_IPV4_RANGES,
            ));

            return;
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $this->assertIpv6NotInRanges($host, array_merge(
                self::PRIVATE_IPV6_RANGES,
                self::LINK_LOCAL_IPV6_RANGES,
            ));

            return;
        }

        // Host is a hostname, not a literal IP. No DNS resolution is
        // performed here (matches WebhookDestinationValidationService's
        // own documented, honest limitation) — literal-IP checks only.
    }

    /**
     * Byte-for-byte, timing-safe comparison of a claimed redirect_uri
     * against the freshly-recomputed expected value (never a cached
     * one) — this, not assertSafe() alone, is what closes the open-
     * redirect surface: assertSafe() only rules out an SSRF-dangerous
     * destination, it does not by itself prove the URL is the ONE this
     * application actually expects for this callback.
     */
    public function matchesExpected(string $expectedRedirectUri, string $candidateRedirectUri): bool
    {
        return hash_equals($expectedRedirectUri, $candidateRedirectUri);
    }

    private function stripIpv6Brackets(string $host): string
    {
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            return substr($host, 1, -1);
        }

        return $host;
    }

    private function assertIpv4NotInRanges(string $ip, array $cidrRanges): void
    {
        foreach ($cidrRanges as $cidr) {
            if ($this->ipv4InCidr($ip, $cidr)) {
                throw new InvalidArgumentException("Redirect host '{$ip}' falls within the disallowed range {$cidr}.");
            }
        }
    }

    private function ipv4InCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);
        $bits = (int) $bits;

        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);

        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        $mask = $bits === 0 ? 0 : (~0 << (32 - $bits));

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }

    private function assertIpv6NotInRanges(string $ip, array $cidrRanges): void
    {
        foreach ($cidrRanges as $cidr) {
            if ($this->ipv6InCidr($ip, $cidr)) {
                throw new InvalidArgumentException("Redirect host '{$ip}' falls within the disallowed range {$cidr}.");
            }
        }
    }

    private function ipv6InCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);
        $bits = (int) $bits;

        $ipBin = inet_pton($ip);
        $subnetBin = inet_pton($subnet);

        if ($ipBin === false || $subnetBin === false) {
            return false;
        }

        $ipBits = '';
        $subnetBits = '';
        foreach (str_split($ipBin) as $byte) {
            $ipBits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        foreach (str_split($subnetBin) as $byte) {
            $subnetBits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        return substr($ipBits, 0, $bits) === substr($subnetBits, 0, $bits);
    }
}
