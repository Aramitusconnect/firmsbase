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
    'GHSA-r277-6w6q-xmqw' => '2026-09-14',
    'GHSA-hrxh-6v49-42gf' => '2026-09-14',
    'CVE-2026-39821' => '2026-09-14',
    'CVE-2026-46600' => '2026-09-14',
];

// The FrankenPHP candidate this exception list was assessed against. If a
// finding under one of the approved IDs above is ever found against a
// DIFFERENT package/version than what was actually reviewed (e.g. because
// the base image pin moved without updating this list), that's exactly
// the kind of silent drift this check exists to catch — so each finding
// is matched by ID AND cross-checked that it still belongs to the
// frankenphp binary target, not merely present somewhere in the scan.
const EXPECTED_SCAN_TARGET_SUBSTRING = 'frankenphp';

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

        if (! str_contains($target, EXPECTED_SCAN_TARGET_SUBSTRING)) {
            fail(sprintf(
                "approved exception ID %s matched, but its finding is against target '%s', not the expected frankenphp binary — this looks like the same ID appearing on an unrelated/unexpected component, refusing to honor it",
                $id,
                $target
            ));
        }

        $expiresAt = APPROVED_EXCEPTIONS[$id];

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
