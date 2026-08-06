<?php

declare(strict_types=1);

namespace Tests\Feature\Ecs;

use Tests\TestCase;

/**
 * Proves the migrate task secret-wiring correction (see
 * docs/ecs/state-adoption-plan.md §9.23): module.migrate now sources its
 * database credentials from the dedicated database-migrator secret instead
 * of the regular database-app secret every other role uses, using the exact
 * JSON selectors proven by cross-validated repository + live historical
 * task-definition evidence — never a guess, never a retrieved secret value.
 * Reads the real, committed files only (fully deterministic, no credentials
 * needed), mirroring this repo's established Ecs test philosophy.
 */
class StagingMigrateSecretWiringTest extends TestCase
{
    private const NON_MIGRATE_SERVICE_ROLES = ['web', 'worker', 'critical_worker', 'scheduler', 'maintenance', 'ses_consumer'];

    private function stagingMain(): string
    {
        return $this->readFile('infrastructure/ecs/environments/staging/main.tf');
    }

    private function stateAdoptionPlan(): string
    {
        return $this->readFile('docs/ecs/state-adoption-plan.md');
    }

    private function variableInventory(): string
    {
        return $this->readFile('docs/ecs/staging-variable-inventory.md');
    }

    private function importManifest(): array
    {
        $path = base_path('infrastructure/ecs/environments/staging/import-manifest.json');
        $contents = file_get_contents($path);
        $this->assertNotFalse($contents, 'Failed to read import-manifest.json');

        $decoded = json_decode($contents, true);
        $this->assertIsArray($decoded, 'import-manifest.json did not decode to an array');

        return $decoded;
    }

    private function manifestEntry(string $address): array
    {
        $manifest = $this->importManifest();
        $entry = collect($manifest['resources'])->firstWhere('address', $address);
        $this->assertNotNull($entry, "Could not find {$address} in import-manifest.json.");

        return $entry;
    }

    private function readFile(string $relativePath): string
    {
        $path = base_path($relativePath);
        $contents = file_get_contents($path);
        $this->assertNotFalse($contents, "Failed to read {$relativePath}");

        return $contents;
    }

    private function extractLocalsBlockContaining(string $content, string $needle): string
    {
        // main.tf has multiple `locals { ... }` blocks; find the one that
        // defines the given local name.
        preg_match_all('/locals\s*\{.*?\n\}\n/s', $content, $matches);
        foreach ($matches[0] as $block) {
            if (str_contains($block, $needle)) {
                return $block;
            }
        }
        $this->fail("Could not locate a locals block defining \"{$needle}\".");
    }

    private function extractModuleBlock(string $content, string $name): string
    {
        preg_match('/module "'.preg_quote($name, '/').'" \{.*?\n\}\n?/s', $content, $matches);
        $this->assertNotEmpty($matches, "Could not locate module \"{$name}\".");

        return $matches[0];
    }

    private function extractSection(string $doc, string $startPattern, string $endPattern): string
    {
        preg_match('/'.$startPattern.'.*?(?='.$endPattern.')/s', $doc, $matches);
        $this->assertNotEmpty($matches, "Could not locate section matching /{$startPattern}/.");

        return $matches[0];
    }

    private function extractMigrateSecretsMapBody(string $content): string
    {
        $block = $this->extractLocalsBlockContaining($content, 'migrate_secrets');
        preg_match('/migrate_secrets\s*=\s*\{(.*?)\n  \}/s', $block, $matches);
        $this->assertNotEmpty($matches, 'Could not isolate the migrate_secrets map body.');

        return $matches[1];
    }

    // ------------------------------------------------------------
    // module.migrate no longer inherits local.shared_secrets unchanged
    // ------------------------------------------------------------

    public function test_migrate_module_does_not_use_shared_secrets(): void
    {
        $migrateBlock = $this->extractModuleBlock($this->stagingMain(), 'migrate');

        $this->assertDoesNotMatchRegularExpression('/secrets\s*=\s*local\.shared_secrets/', $migrateBlock);
        $this->assertMatchesRegularExpression('/secrets\s*=\s*local\.migrate_secrets/', $migrateBlock);
    }

    public function test_migrate_module_does_not_use_shared_environment_unchanged(): void
    {
        $migrateBlock = $this->extractModuleBlock($this->stagingMain(), 'migrate');

        $this->assertDoesNotMatchRegularExpression('/environment\s*=\s*local\.shared_environment/', $migrateBlock);
        $this->assertMatchesRegularExpression('/environment\s*=\s*local\.migrate_environment/', $migrateBlock);
    }

    // ------------------------------------------------------------
    // Exact discovered JSON selectors
    // ------------------------------------------------------------

    public function test_migrate_secrets_uses_the_exact_evidence_proven_selectors(): void
    {
        $block = $this->extractLocalsBlockContaining($this->stagingMain(), 'migrate_secrets');

        $expected = [
            'APP_KEY' => '"${var.app_key_secret_arn}:APP_KEY::"',
            'DB_HOST' => '"${var.db_migrator_secret_arn}:host::"',
            'DB_PORT' => '"${var.db_migrator_secret_arn}:port::"',
            'DB_DATABASE' => '"${var.db_migrator_secret_arn}:dbname::"',
            'DB_USERNAME' => '"${var.db_migrator_secret_arn}:username::"',
            'DB_PASSWORD' => '"${var.db_migrator_secret_arn}:password::"',
            'REDIS_PASSWORD' => '"${var.redis_auth_token_secret_arn}:REDIS_PASSWORD::"',
        ];

        foreach ($expected as $key => $value) {
            $this->assertMatchesRegularExpression(
                '/'.preg_quote($key, '/').'\s*=\s*'.preg_quote($value, '/').'/',
                $block,
                "migrate_secrets must map {$key} to exactly {$value}."
            );
        }
    }

    public function test_migrate_secrets_has_exactly_seven_keys(): void
    {
        $mapBody = $this->extractMigrateSecretsMapBody($this->stagingMain());

        $keyCount = preg_match_all('/^\s*[A-Z_]+\s*=/m', $mapBody);
        $this->assertSame(7, $keyCount, 'migrate_secrets must have exactly 7 keys (APP_KEY, DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD, REDIS_PASSWORD).');
    }

    // ------------------------------------------------------------
    // No hardcoded migrator username/password anywhere
    // ------------------------------------------------------------

    public function test_no_hardcoded_migrator_credential_exists_in_staging_config(): void
    {
        // Scoped to the migrate_secrets map body itself — not the whole
        // combined locals block, which also legitimately contains
        // shared_environment's own hardcoded DB_USERNAME for the regular
        // (non-migrator) app credential.
        $mapBody = $this->extractMigrateSecretsMapBody($this->stagingMain());

        $this->assertDoesNotMatchRegularExpression(
            '/DB_USERNAME\s*=\s*"(?!\$\{)[^"]*"/',
            $mapBody,
            'migrate_secrets must not contain a hardcoded DB_USERNAME literal.'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/DB_PASSWORD\s*=\s*"(?!\$\{)[^"]*"/',
            $mapBody,
            'migrate_secrets must not contain a hardcoded DB_PASSWORD literal.'
        );
    }

    // ------------------------------------------------------------
    // No environment/secrets key collision (would fail ECS RegisterTaskDefinition)
    // ------------------------------------------------------------

    public function test_migrate_environment_excludes_the_four_now_secret_sourced_db_keys(): void
    {
        $block = $this->extractLocalsBlockContaining($this->stagingMain(), 'migrate_environment');

        $this->assertMatchesRegularExpression('/migrate_environment\s*=\s*\{/', $block);
        $this->assertMatchesRegularExpression(
            '/!contains\(\["DB_HOST",\s*"DB_PORT",\s*"DB_DATABASE",\s*"DB_USERNAME"\],\s*k\)/',
            $block
        );
    }

    public function test_migrate_environment_still_carries_db_connection_and_sslmode_plain(): void
    {
        // These are protocol/mode flags, not credentials — evidence
        // (the historical migrate task's own environment array) shows
        // they remain plain, unlike DB_HOST/PORT/DATABASE/USERNAME.
        $staging = $this->stagingMain();
        $this->assertMatchesRegularExpression('/DB_CONNECTION\s*=\s*"pgsql"/', $staging);
        $this->assertMatchesRegularExpression('/DB_SSLMODE\s*=\s*"require"/', $staging);

        $block = $this->extractLocalsBlockContaining($staging, 'migrate_environment');
        $this->assertDoesNotMatchRegularExpression('/"DB_CONNECTION"/', $block, 'DB_CONNECTION must not be in the excluded-keys list.');
        $this->assertDoesNotMatchRegularExpression('/"DB_SSLMODE"/', $block, 'DB_SSLMODE must not be in the excluded-keys list.');
    }

    // ------------------------------------------------------------
    // Every other role remains unchanged
    // ------------------------------------------------------------

    public function test_no_other_role_secrets_map_was_changed(): void
    {
        $staging = $this->stagingMain();

        $expectations = [
            'web' => 'secrets\s*=\s*merge\(local\.shared_secrets,\s*local\.hmac_secret\)',
            'worker' => 'secrets\s*=\s*local\.shared_secrets',
            'critical_worker' => 'secrets\s*=\s*local\.shared_secrets',
            'scheduler' => 'secrets\s*=\s*local\.shared_secrets',
            'maintenance' => 'secrets\s*=\s*local\.shared_secrets',
            'ses_consumer' => 'secrets\s*=\s*merge\(local\.shared_secrets,\s*local\.hmac_secret\)',
        ];

        foreach ($expectations as $role => $pattern) {
            $block = $this->extractModuleBlock($staging, $role);
            $this->assertMatchesRegularExpression("/{$pattern}/", $block, "module \"{$role}\" secrets wiring must remain unchanged.");
            $this->assertDoesNotMatchRegularExpression('/local\.migrate_secrets/', $block, "module \"{$role}\" must never reference local.migrate_secrets.");
        }
    }

    public function test_database_migrator_secret_arn_appears_only_in_execution_role_grant_and_migrate_secrets(): void
    {
        // Strip comment lines first — explanatory prose mentioning the bare
        // identifier (e.g. "already carried GetSecretValue on
        // db_migrator_secret_arn") is not a code reference and must not be
        // counted toward the expected occurrence total.
        $code = preg_replace('/^\s*#.*$/m', '', $this->stagingMain());

        // Occurrences: (1) module.iam's task_execution_secret_arns list,
        // (2)-(6) the five migrate_secrets selectors that reference it.
        $count = preg_match_all('/db_migrator_secret_arn/', $code);
        $this->assertSame(6, $count, 'var.db_migrator_secret_arn must appear exactly 6 times in code: once in the execution-role grant, five times in migrate_secrets (DB_HOST/DB_PORT/DB_DATABASE/DB_USERNAME/DB_PASSWORD).');
    }

    // ------------------------------------------------------------
    // Execution-role access unchanged — exactly 4 secrets, no wildcard
    // ------------------------------------------------------------

    public function test_execution_role_secret_arns_remain_exactly_four_no_wildcard(): void
    {
        $staging = $this->stagingMain();
        $iamBlock = $this->extractModuleBlock($staging, 'iam');

        preg_match('/task_execution_secret_arns\s*=\s*\[(.*?)\]/s', $iamBlock, $matches);
        $this->assertNotEmpty($matches);
        $arns = $matches[1];

        foreach (['app_key_secret_arn', 'db_password_secret_arn', 'redis_auth_token_secret_arn', 'db_migrator_secret_arn'] as $expected) {
            $this->assertStringContainsString("var.{$expected}", $arns);
        }
        $this->assertDoesNotMatchRegularExpression('/"\*"/', $arns);

        $entryCount = preg_match_all('/var\.\w+_secret_arn/', $arns);
        $this->assertSame(4, $entryCount, 'task_execution_secret_arns must contain exactly 4 secret ARN references.');
    }

    // ------------------------------------------------------------
    // HMAC-secret separation unchanged
    // ------------------------------------------------------------

    public function test_hmac_secret_separation_is_unchanged(): void
    {
        $staging = $this->stagingMain();

        $webBlock = $this->extractModuleBlock($staging, 'web');
        $sesConsumerBlock = $this->extractModuleBlock($staging, 'ses_consumer');
        $this->assertMatchesRegularExpression('/local\.hmac_secret/', $webBlock);
        $this->assertMatchesRegularExpression('/local\.hmac_secret/', $sesConsumerBlock);

        foreach (['worker', 'critical_worker', 'scheduler', 'migrate', 'maintenance'] as $role) {
            $block = $this->extractModuleBlock($staging, $role);
            $this->assertDoesNotMatchRegularExpression('/local\.hmac_secret/', $block, "module \"{$role}\" must not receive the HMAC secret.");
        }
    }

    // ------------------------------------------------------------
    // No Terraform resource address changed
    // ------------------------------------------------------------

    public function test_no_terraform_resource_or_module_address_changed(): void
    {
        $staging = $this->stagingMain();

        $this->assertSame(7, preg_match_all('/module\s+"[a-zA-Z0-9_]+"\s*\{[^}]*source\s*=\s*"[^"]*modules\/ecs_service"/s', $staging));
        $this->assertMatchesRegularExpression('/^module "migrate" \{/m', $staging);
    }

    // ------------------------------------------------------------
    // Canary selection unchanged
    // ------------------------------------------------------------

    public function test_maintenance_remains_first_canary_migrate_still_excluded(): void
    {
        $doc = $this->stateAdoptionPlan();
        $section = $this->extractSection($doc, '### 9\.23', '## 10\.');

        $this->assertMatchesRegularExpression('/`maintenance`\s*remains\s*the\s*selected\s*first\s*deployment\s*canary/i', $section);
        $this->assertMatchesRegularExpression('/`migrate`\s*remains\s*excluded/i', $section);
        $this->assertMatchesRegularExpression('/schema-migration side effects/i', $section);
    }

    // ------------------------------------------------------------
    // Evidence trail documented; no secret value exposed
    // ------------------------------------------------------------

    public function test_documentation_records_the_evidence_trail_without_secret_values(): void
    {
        $doc = $this->stateAdoptionPlan();
        $section = $this->extractSection($doc, '### 9\.23', '## 10\.');

        $this->assertStringContainsString('staging-deploy/firmsbase-staging-migrate.json', $section);
        $this->assertMatchesRegularExpression('/describe-task-definition/i', $section);
        $this->assertMatchesRegularExpression('/no secret value was (ever )?retrieved/i', $section);
        $this->assertMatchesRegularExpression('/No .{0,10}describe-secret.{0,10} call.{0,20}and no key-only/is', $section);
    }

    public function test_manifest_migrate_task_definition_note_records_the_correction(): void
    {
        $entry = $this->manifestEntry('module.migrate.aws_ecs_task_definition.this');
        $notes = $entry['notes'];

        $this->assertMatchesRegularExpression('/CORRECTED 2026-08-06/', $notes);
        $this->assertStringContainsString('database-migrator', $notes);
        $this->assertSame('do_not_import', $entry['classification'], 'Classification must not change — this is a config correction, not an import-readiness change.');
    }

    public function test_variable_inventory_mirrors_the_correction(): void
    {
        $doc = $this->variableInventory();
        $this->assertStringContainsString('Migrate task secret-wiring defect corrected', $doc);
        $this->assertMatchesRegularExpression('/database-migrator-TpsE6P/', $doc);
    }
}
