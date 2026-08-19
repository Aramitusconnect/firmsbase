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
const APPROVED_EXCEPTIONS = [
    'GHSA-r277-6w6q-xmqw' => [
        'expires' => '2026-09-14',
        'expected_target_contains' => 'frankenphp',
        'expected_package' => null,
        'expected_installed_version' => null,
    ],
    'GHSA-hrxh-6v49-42gf' => [
        'expires' => '2026-09-14',
        'expected_target_contains' => 'frankenphp',
        'expected_package' => null,
        'expected_installed_version' => null,
    ],
    'CVE-2026-39821' => [
        'expires' => '2026-09-14',
        'expected_target_contains' => 'frankenphp',
        'expected_package' => null,
        'expected_installed_version' => null,
    ],
    'CVE-2026-46600' => [
        'expires' => '2026-09-14',
        'expected_target_contains' => 'frankenphp',
        'expected_package' => null,
        'expected_installed_version' => null,
    ],
    'CVE-2026-14456' => [
        'expires' => '2026-09-14',
        'expected_target_contains' => 'debian 13.6',
        'expected_package' => 'libssl3t64',
        'expected_installed_version' => '3.5.6-1~deb13u2',
    ],
];

// Each finding is matched by ID and by the exact metadata that made the
// exception reviewable. Null package/version pins intentionally preserve the
// original FrankenPHP-only behavior for the first four exceptions.

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

        $exception = APPROVED_EXCEPTIONS[$id];
        $expectedTargetContains = (string) $exception['expected_target_contains'];

        if (! str_contains($target, $expectedTargetContains)) {
            fail(sprintf(
                "approved exception ID %s matched, but its finding is against target '%s', not a target containing '%s' — this looks like the same ID appearing on an unrelated/unexpected component, refusing to honor it",
                $id,
                $target,
                $expectedTargetContains
            ));
        }

        $pkgName = (string) ($vuln['PkgName'] ?? '');
        $expectedPackage = $exception['expected_package'];

        if (is_string($expectedPackage) && $pkgName !== $expectedPackage) {
            fail(sprintf(
                "approved exception ID %s matched, but its package is '%s', not the expected package '%s' — refusing to honor it",
                $id,
                $pkgName,
                $expectedPackage
            ));
        }

        $installedVersion = (string) ($vuln['InstalledVersion'] ?? '');
        $expectedInstalledVersion = $exception['expected_installed_version'];

        if (is_string($expectedInstalledVersion) && $installedVersion !== $expectedInstalledVersion) {
            fail(sprintf(
                "approved exception ID %s matched, but its installed version is '%s', not the expected installed version '%s' — refusing to honor it",
                $id,
                $installedVersion,
                $expectedInstalledVersion
            ));
        }

        $expiresAt = (string) $exception['expires'];

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
