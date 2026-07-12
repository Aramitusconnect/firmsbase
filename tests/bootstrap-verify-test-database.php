<?php

/**
 * Section 39A-3L Stage A test-harness guard.
 *
 * phpunit.xml intentionally does not hardcode DB_USERNAME/DB_PASSWORD/
 * DB_DATABASE. They must come from the process environment, normally set
 * by tools/rls-test/run-artisan-test.sh against a uniquely named disposable
 * database. This script runs before any test and aborts the entire process
 * rather than letting it silently fall back to .env, a shared role, or a
 * persistent/shared/quarantined/sibling-mission database.
 */

$required = ['DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'];
$missing = [];

foreach ($required as $key) {
    $value = getenv($key);
    if ($value === false || $value === '') {
        $missing[] = $key;
    }
}

if ($missing !== []) {
    fwrite(STDERR, "\n[39A-3L test-harness guard] Refusing to run: missing required testing environment variable(s): ".implode(', ', $missing).".\n");
    fwrite(STDERR, "Run tests only through tools/rls-test/run-artisan-test.sh (see .env.testing.example for the expected shape).\n\n");
    exit(1);
}

if (getenv('APP_ENV') !== 'testing') {
    fwrite(STDERR, "\n[39A-3L test-harness guard] Refusing to run: APP_ENV must be 'testing', got '".getenv('APP_ENV')."'.\n\n");
    exit(1);
}

$expectedUser = 'rls_test_runner_39a3l';
$actualUser = getenv('DB_USERNAME');
if ($actualUser !== $expectedUser) {
    fwrite(STDERR, "\n[39A-3L test-harness guard] Refusing to run: DB_USERNAME must be the dedicated mission test role ('{$expectedUser}'), got '{$actualUser}'.\n\n");
    exit(1);
}

$dbName = getenv('DB_DATABASE');
$disposablePattern = '/^firmsbase_test_39a3l_disposable_[a-z0-9]+_[a-z0-9]+$/';

$blocklist = [
    'firmsbase',
    'firmsbase_test',
    'firmsbase_test_39a3k',
    'firmsbase_test_39a3l_marathon',
    'firmsbase_test_39a3l_checkpoint21_recovery',
    'firmsbase_test_39a3l_marathon_incident_20260712',
    'firmsbase_test_39a4a1_inventory',
    'firmsbase_test_39a4a_classification',
];

if (in_array($dbName, $blocklist, true)) {
    fwrite(STDERR, "\n[39A-3L test-harness guard] Refusing to run: DB_DATABASE ('{$dbName}') is an explicitly blocked persistent/shared/quarantined/sibling-mission database.\n\n");
    exit(1);
}

if (! preg_match($disposablePattern, (string) $dbName)) {
    fwrite(STDERR, "\n[39A-3L test-harness guard] Refusing to run: DB_DATABASE ('{$dbName}') does not match the approved disposable naming pattern 'firmsbase_test_39a3l_disposable_<purpose>_<run_id>'. Tests must never target the immutable template directly, a persistent database, or any non-disposable database.\n\n");
    exit(1);
}
