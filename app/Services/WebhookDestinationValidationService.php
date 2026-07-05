<?php

namespace App\Services;

/**
 * WebhookDestinationValidationService — pure, no network/DNS calls
 * (correction #5). Rejects a destination URL on: non-http/https
 * schemes; http unless $allowInsecureHttpForTesting is explicitly true;
 * localhost/127.0.0.0/8/::1; literal private IPv4 ranges
 * (10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16); literal link-local/
 * metadata ranges (169.254.0.0/16, explicitly including
 * 169.254.169.254); and IPv6 private/link-local ranges (fc00::/7,
 * fe80::/10).
 *
 * This service deliberately does NOT perform any hostname lookup, DNS
 * resolution, or resolver call of any kind — it only inspects the
 * literal host string in the URL. A hostname like "attacker.example.com" that
 * RESOLVES to a private/metadata IP at send time is NOT caught here —
 * that is an explicit, documented limitation of this phase (no real
 * HTTP transport exists yet to resolve against). Any future real
 * transport MUST re-resolve the hostname and re-run an IP-literal check
 * against every resolved address IMMEDIATELY before opening the
 * connection — this validation service checks the URL at
 * subscribe/update time only, which is necessarily a point-in-time
 * check for a hostname (DNS can change later), never a substitute for
 * a pre-send check in a real transport.
 */
class WebhookDestinationValidationService
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
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    public function assertSafe(string $url, bool $allowInsecureHttpForTesting = false): void
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new \InvalidArgumentException("Destination URL '{$url}' could not be parsed.");
        }

        $scheme = strtolower($parts['scheme']);

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new \InvalidArgumentException("Destination URL scheme '{$scheme}' is not allowed; only http/https are permitted.");
        }

        if ($scheme === 'http' && ! $allowInsecureHttpForTesting) {
            throw new \InvalidArgumentException(
                'Plain http destination URLs are not allowed unless allowInsecureHttpForTesting is explicitly true.'
            );
        }

        $host = $this->stripIpv6Brackets(strtolower($parts['host']));

        if ($host === 'localhost') {
            throw new \InvalidArgumentException("Destination host 'localhost' is not allowed.");
        }

        if ($host === '::1') {
            throw new \InvalidArgumentException("Destination host '::1' is not allowed.");
        }

        if ($host === '169.254.169.254') {
            throw new \InvalidArgumentException('Destination host is the cloud metadata address and is not allowed.');
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
        // performed in this phase (correction #5) — literal-IP checks
        // only. A future real transport must re-check resolved IPs.
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
                throw new \InvalidArgumentException("Destination host '{$ip}' falls within the disallowed range {$cidr}.");
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
                throw new \InvalidArgumentException("Destination host '{$ip}' falls within the disallowed range {$cidr}.");
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
