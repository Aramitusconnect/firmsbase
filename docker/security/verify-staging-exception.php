<?php

declare(strict_types=1);

/**
 * Staging-release-only Trivy policy gate. See
 * docs/security/ecs-image-vulnerability-exceptions.md "Review: 2026-08-14"
 * for the human-readable rationale this script enforces mechanically.
 *
 * Reads the raw, unsuppressed Trivy JSON output (produced by the normal
 * `trivy image --severity CRITICAL,HIGH --format json` scan — no
 * .trivyignore, no scanner-level suppression exists anywhere in this
 * repository) and fails unless every CRITICAL/HIGH finding is one of the
 * exact, dated, staging-only exceptions below. This never runs for a
 * production release — production is checked against the raw Trivy exit
 * code directly, with no exception list consulted at all, by construction
 * (this script is only ever invoked from the staging QA publication step).
 *
 * This is deliberately NOT config parsed from the markdown doc — the doc
 * is the audit-trail rationale; this constant list is the actual
 * enforcement source of truth. Keep both in sync by hand when either
 * changes; a mismatch is a documentation bug, not a policy bug.
 *
 * Usage: php verify-staging-exception.php <path-to-trivy-vuln-results.json>
 */
// Each approved ID carries its own expiry AND the exact scan target
// substring it was assessed against — originally a single shared
// 'frankenphp' constant (every approved ID lived in that one binary), but
// the 2026-08-22 review added the first exception against a DIFFERENT
// target (the Debian OS layer, for an OpenSSL package finding), so the
// per-ID target check below is now a map rather than one global constant.
// If a finding under one of the approved IDs is ever found against a
// DIFFERENT target than what was actually reviewed (e.g. because the base
// image pin moved without updating this list), that's exactly the kind of
// silent drift this check exists to catch.
const APPROVED_EXCEPTIONS = [
    'GHSA-r277-6w6q-xmqw' => ['expires' => '2026-09-14', 'target' => 'frankenphp'],
    'GHSA-hrxh-6v49-42gf' => ['expires' => '2026-09-14', 'target' => 'frankenphp'],
    'CVE-2026-39821' => ['expires' => '2026-09-14', 'target' => 'frankenphp'],
    'CVE-2026-46600' => ['expires' => '2026-09-14', 'target' => 'frankenphp'],
    // Added 2026-08-22 — see docs/security/ecs-image-vulnerability-exceptions.md
    // "Review: 2026-08-22". No official upstream fix exists yet for either
    // kin-openapi CVE (frankenphp has not repinned; vulcain/caddy haven't
    // cut releases with the fixed versions) — approved because
    // openapi3filter (the vulnerable subpackage for both) is never imported
    // by vulcain or anywhere else in this dependency graph, confirmed via
    // source-level import analysis and a raw-byte scan of the shipped
    // frankenphp binary, reinforced by docker/web/Caddyfile never
    // configuring a `vulcain` directive (the only caller that could invoke
    // kin-openapi at all).
    'CVE-2026-76905' => ['expires' => '2026-09-14', 'target' => 'frankenphp'],
    'CVE-2026-77354' => ['expires' => '2026-09-14', 'target' => 'frankenphp'],
    // Added 2026-08-22 — same review. No Debian trixie fix exists yet
    // (tracker.debian.org marks it "postponed"). Approved because the
    // vulnerable code path is an OpenSSL-native QUIC *server listener*,
    // and nothing in this image ever constructs one: Caddy/FrankenPHP's
    // inbound TLS is Go's crypto/tls (not OpenSSL), docker/web/Caddyfile
    // has no tls/http3/quic directive and no TLS listener at all
    // (auto_https off, plain :8080 HTTP), no PHP extension here exposes
    // OpenSSL's QUIC APIs, and the ECS security groups / ALB path is
    // TCP-only end to end with the ALB terminating all external TLS
    // before the container ever sees a byte of it. Target is the Debian
    // OS layer (libssl3t64), not the frankenphp binary.
    'CVE-2026-14456' => ['expires' => '2026-09-14', 'target' => 'debian'],
];

function fail(string $message): never
{
    fwrite(STDERR, "[staging-exception-policy] FAIL: {$message}\n");
    exit(1);
}

function pass(string $message): void
{
    fwrite(STDOUT, "[staging-exception-policy] OK: {$message}\n");
}

$path = $argv[1] ?? null;

if ($path === null || ! is_string($path)) {
    fail('usage: php verify-staging-exception.php <path-to-trivy-vuln-results.json>');
}

if (! is_file($path)) {
    fail("Trivy results file not found: {$path}");
}

$raw = file_get_contents($path);

if ($raw === false) {
    fail("could not read Trivy results file: {$path}");
}

$decoded = json_decode($raw, true);

if (! is_array($decoded)) {
    fail('Trivy results file is not valid JSON');
}

$today = date('Y-m-d');
$unapproved = [];
$approvedFound = [];

foreach (($decoded['Results'] ?? []) as $result) {
    $target = (string) ($result['Target'] ?? '');

    foreach (($result['Vulnerabilities'] ?? []) as $vuln) {
        $severity = (string) ($vuln['Severity'] ?? '');

        if ($severity !== 'CRITICAL' && $severity !== 'HIGH') {
            continue;
        }

        $id = (string) ($vuln['VulnerabilityID'] ?? '');

        if (! array_key_exists($id, APPROVED_EXCEPTIONS)) {
            $unapproved[] = sprintf('%s (%s) in %s', $id, $severity, $target);

            continue;
        }

        $expectedTarget = APPROVED_EXCEPTIONS[$id]['target'];

        if (! str_contains($target, $expectedTarget)) {
            fail(sprintf(
                "approved exception ID %s matched, but its finding is against target '%s', not the expected '%s' — this looks like the same ID appearing on an unrelated/unexpected component, refusing to honor it",
                $id,
                $target,
                $expectedTarget
            ));
        }

        $expiresAt = APPROVED_EXCEPTIONS[$id]['expires'];

        if ($today > $expiresAt) {
            fail("approved exception {$id} expired on {$expiresAt} (today is {$today}) — re-review required before this can be honored again");
        }

        $approvedFound[$id] = true;
    }
}

if ($unapproved !== []) {
    fail(
        'unapproved CRITICAL/HIGH finding(s) present — this build is not covered by the staging-only exception and must not be published: '
        .implode('; ', $unapproved)
    );
}

pass(sprintf(
    'no unapproved CRITICAL/HIGH findings. %d of %d approved exception(s) present and unexpired: %s',
    count($approvedFound),
    count(APPROVED_EXCEPTIONS),
    implode(', ', array_keys($approvedFound)) ?: '(none — image is fully clean)'
));

exit(0);
