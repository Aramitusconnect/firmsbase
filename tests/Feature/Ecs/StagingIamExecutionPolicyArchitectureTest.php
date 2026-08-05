<?php

declare(strict_types=1);

namespace Tests\Feature\Ecs;

use Tests\TestCase;

/**
 * Proves the IAM execution-policy architecture correction (see
 * docs/ecs/state-adoption-plan.md §9.18): the live two-layer permission
 * shape (AmazonECSTaskExecutionRolePolicy managed-policy attachment +
 * FirmsBaseStagingSecretsAccess narrow inline policy) is now preserved in
 * Terraform, against the real, committed files — never against a live
 * `terraform plan`/`apply`/`import` (no AWS contact, no credentials
 * needed, fully deterministic).
 */
class StagingIamExecutionPolicyArchitectureTest extends TestCase
{
    private const LIVE_SECRET_ARNS = [
        'arn:aws:secretsmanager:us-east-1:603013471426:secret:firmsbase/staging/app-key-QigVGy',
        'arn:aws:secretsmanager:us-east-1:603013471426:secret:firmsbase/staging/redis-auth-token-p6rVKN',
        'arn:aws:secretsmanager:us-east-1:603013471426:secret:firmsbase/staging/database-app-8NUj2a',
        'arn:aws:secretsmanager:us-east-1:603013471426:secret:firmsbase/staging/database-migrator-TpsE6P',
    ];

    private const MANAGED_POLICY_ARN = 'arn:aws:iam::aws:policy/service-role/AmazonECSTaskExecutionRolePolicy';

    private function iamModuleMain(): string
    {
        return $this->readFile('infrastructure/ecs/modules/iam/main.tf');
    }

    private function iamModuleVariables(): string
    {
        return $this->readFile('infrastructure/ecs/modules/iam/variables.tf');
    }

    private function stagingMain(): string
    {
        return $this->readFile('infrastructure/ecs/environments/staging/main.tf');
    }

    private function stagingVariables(): string
    {
        return $this->readFile('infrastructure/ecs/environments/staging/variables.tf');
    }

    private function stagingTfvarsExample(): string
    {
        return $this->readFile('infrastructure/ecs/environments/staging/terraform.tfvars.example');
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

    private function extractDataBlock(string $content, string $type, string $name): string
    {
        preg_match('/data "'.preg_quote($type, '/').'" "'.preg_quote($name, '/').'" \{.*?\n\}\n/s', $content, $matches);
        $this->assertNotEmpty($matches, "Could not locate data \"{$type}\" \"{$name}\".");

        return $matches[0];
    }

    private function extractResourceBlock(string $content, string $type, string $name): string
    {
        preg_match('/resource "'.preg_quote($type, '/').'" "'.preg_quote($name, '/').'" \{.*?\n\}\n/s', $content, $matches);
        $this->assertNotEmpty($matches, "Could not locate resource \"{$type}\" \"{$name}\".");

        return $matches[0];
    }

    private function extractVariableBlock(string $content, string $name): string
    {
        preg_match('/variable "'.preg_quote($name, '/').'" \{.*?\n\}/s', $content, $matches);
        $this->assertNotEmpty($matches, "Could not locate variable \"{$name}\".");

        return $matches[0];
    }

    private function extractModuleBlock(string $content, string $name): string
    {
        preg_match('/module "'.preg_quote($name, '/').'" \{.*?\n\}\n/s', $content, $matches);
        $this->assertNotEmpty($matches, "Could not locate module \"{$name}\".");

        return $matches[0];
    }

    private function extractSection(string $doc, string $startPattern, string $endPattern): string
    {
        preg_match('/'.$startPattern.'.*?(?='.$endPattern.')/s', $doc, $matches);
        $this->assertNotEmpty($matches, "Could not locate section matching /{$startPattern}/.");

        return $matches[0];
    }

    // ------------------------------------------------------------
    // Managed-policy attachment
    // ------------------------------------------------------------

    public function test_managed_policy_attachment_uses_the_non_exclusive_resource_type(): void
    {
        $main = $this->iamModuleMain();

        $this->assertStringContainsString('resource "aws_iam_role_policy_attachment" "task_execution_managed"', $main);
        // Only a resource DECLARATION is forbidden — the module's own
        // explanatory comment legitimately names the excluded resource
        // type to say why it isn't used.
        $this->assertDoesNotMatchRegularExpression('/resource\s+"aws_iam_role_policy_attachments_exclusive"/', $main);
        $this->assertDoesNotMatchRegularExpression('/resource\s+"aws_iam_role_policy_attachments_exclusive"/', $this->stagingMain());
    }

    public function test_managed_policy_attachment_attaches_to_the_execution_role_by_name(): void
    {
        $attachment = $this->extractResourceBlock($this->iamModuleMain(), 'aws_iam_role_policy_attachment', 'task_execution_managed');

        $this->assertStringContainsString('role       = aws_iam_role.task_execution.name', $attachment);
        $this->assertStringContainsString('policy_arn = var.task_execution_managed_policy_arn', $attachment);
    }

    public function test_role_resource_itself_does_not_manage_policy_attachments(): void
    {
        $role = $this->extractResourceBlock($this->iamModuleMain(), 'aws_iam_role', 'task_execution');

        $this->assertStringNotContainsString('managed_policy_arns', $role);
        $this->assertStringNotContainsString('inline_policy', $role);
    }

    public function test_managed_policy_arn_is_a_required_module_input_resolving_to_the_exact_live_value(): void
    {
        $var = $this->extractVariableBlock($this->iamModuleVariables(), 'task_execution_managed_policy_arn');
        $this->assertDoesNotMatchRegularExpression('/^\s*default\s*=/m', $var, 'task_execution_managed_policy_arn must have no default.');

        $module = $this->extractModuleBlock($this->stagingMain(), 'iam');
        $this->assertMatchesRegularExpression('/task_execution_managed_policy_arn\s*= var\.iam_task_execution_managed_policy_arn/', $module);

        $rootVar = $this->extractVariableBlock($this->stagingVariables(), 'iam_task_execution_managed_policy_arn');
        $this->assertDoesNotMatchRegularExpression('/^\s*default\s*=/m', $rootVar);

        $tfvars = $this->stagingTfvarsExample();
        $this->assertStringContainsString('iam_task_execution_managed_policy_arn = "'.self::MANAGED_POLICY_ARN.'"', $tfvars);
    }

    // ------------------------------------------------------------
    // Inline policy — secrets-only, exact live shape
    // ------------------------------------------------------------

    public function test_inline_policy_name_remains_the_live_value(): void
    {
        $policy = $this->extractResourceBlock($this->iamModuleMain(), 'aws_iam_role_policy', 'task_execution');
        $this->assertStringContainsString('name   = var.task_execution_policy_name', $policy);

        $tfvars = $this->stagingTfvarsExample();
        $this->assertStringContainsString('iam_task_execution_policy_name = "FirmsBaseStagingSecretsAccess"', $tfvars);
    }

    public function test_inline_policy_document_contains_only_secretsmanager_get_secret_value(): void
    {
        $doc = $this->extractDataBlock($this->iamModuleMain(), 'aws_iam_policy_document', 'task_execution');

        $this->assertStringContainsString('actions   = ["secretsmanager:GetSecretValue"]', $doc);

        foreach (['ecr:', 'logs:'] as $forbiddenPrefix) {
            $this->assertStringNotContainsString($forbiddenPrefix, $doc, "No {$forbiddenPrefix}* action may remain in the staging inline policy — those come from the managed-policy attachment.");
        }
    }

    public function test_no_ecr_or_logs_action_reaches_the_staging_inline_policy_via_wiring(): void
    {
        // Removed entirely — these variables previously fed the ECR/logs
        // statements that no longer exist in the inline-policy document.
        $this->assertStringNotContainsString('ecr_repository_arn', $this->iamModuleVariables());
        $this->assertStringNotContainsString('log_group_arns', $this->iamModuleVariables());
    }

    public function test_task_execution_secret_arns_is_required_nonempty_unique(): void
    {
        $var = $this->extractVariableBlock($this->iamModuleVariables(), 'task_execution_secret_arns');
        $this->assertDoesNotMatchRegularExpression('/^\s*default\s*=/m', $var, 'task_execution_secret_arns must have no default.');
        $this->assertStringContainsString('length(var.task_execution_secret_arns) > 0', $var);
        $this->assertStringContainsString('toset(var.task_execution_secret_arns)', $var);
    }

    public function test_exact_four_live_secret_arns_are_wired_in_staging(): void
    {
        $module = $this->extractModuleBlock($this->stagingMain(), 'iam');
        preg_match('/task_execution_secret_arns\s*=\s*\[(.*?)\]/s', $module, $matches);
        $this->assertNotEmpty($matches, 'Could not locate task_execution_secret_arns in module.iam call.');

        $wiredVars = array_map('trim', explode(',', trim($matches[1])));
        $wiredVars = array_filter($wiredVars, fn ($v) => $v !== '');

        $this->assertEqualsCanonicalizing(
            ['var.app_key_secret_arn', 'var.db_password_secret_arn', 'var.redis_auth_token_secret_arn', 'var.db_migrator_secret_arn'],
            array_values($wiredVars)
        );

        // The platform-notifications HMAC-key secret must NOT be wired
        // into the execution role's secrets grant — live's inline policy
        // does not include it (see §9.18).
        $this->assertStringNotContainsString('var.platform_notifications_recipient_fingerprint_hmac_key_secret_arn', $matches[1]);
    }

    public function test_db_migrator_secret_arn_variable_resolves_to_the_exact_live_arn(): void
    {
        $var = $this->extractVariableBlock($this->stagingVariables(), 'db_migrator_secret_arn');
        $this->assertStringContainsString('type        = string', $var);

        $tfvars = $this->stagingTfvarsExample();
        $this->assertStringContainsString(
            'db_migrator_secret_arn      = "arn:aws:secretsmanager:us-east-1:603013471426:secret:firmsbase/staging/database-migrator-TpsE6P"',
            $tfvars
        );
    }

    public function test_ssm_and_kms_execution_inputs_are_separately_named_and_disabled_for_staging(): void
    {
        $vars = $this->iamModuleVariables();
        $this->assertStringContainsString('variable "task_execution_ssm_parameter_arns"', $vars);
        $this->assertStringContainsString('variable "task_execution_kms_decrypt_enabled"', $vars);

        $kmsVar = $this->extractVariableBlock($vars, 'task_execution_kms_decrypt_enabled');
        $this->assertDoesNotMatchRegularExpression('/^\s*default\s*=/m', $kmsVar, 'task_execution_kms_decrypt_enabled must have no default — explicit opt-in.');

        $module = $this->extractModuleBlock($this->stagingMain(), 'iam');
        $this->assertMatchesRegularExpression('/task_execution_ssm_parameter_arns\s*=\s*\[\]/', $module);
        $this->assertMatchesRegularExpression('/task_execution_kms_decrypt_enabled\s*=\s*false/', $module);

        // Independent from the unrelated S3-document KMS flag, which
        // remains enabled — proves the two are decoupled, not the same
        // gate silently enabling both.
        $this->assertMatchesRegularExpression('/kms_encryption_enabled\s*=\s*true/', $module);
    }

    public function test_no_unverified_ssm_or_kms_statement_in_the_document_by_default(): void
    {
        $doc = $this->extractDataBlock($this->iamModuleMain(), 'aws_iam_policy_document', 'task_execution');
        $this->assertStringContainsString('for_each = length(var.task_execution_ssm_parameter_arns) > 0 ? [1] : []', $doc);
        $this->assertStringContainsString('for_each = var.task_execution_kms_decrypt_enabled ? [1] : []', $doc);
    }

    // ------------------------------------------------------------
    // Resource addresses unchanged
    // ------------------------------------------------------------

    public function test_role_and_inline_policy_resource_addresses_are_unchanged(): void
    {
        $main = $this->iamModuleMain();
        preg_match_all('/resource "aws_iam_role" "task_execution"/', $main, $roleMatches);
        $this->assertCount(1, $roleMatches[0]);

        preg_match_all('/resource "aws_iam_role_policy" "task_execution"/', $main, $policyMatches);
        $this->assertCount(1, $policyMatches[0]);
    }

    // ------------------------------------------------------------
    // Manifest totals and classifications
    // ------------------------------------------------------------

    public function test_manifest_totals_are_66_15_8_6_95(): void
    {
        $manifest = $this->importManifest();
        $this->assertSame(66, $manifest['summary']['new']);
        $this->assertSame(15, $manifest['summary']['import_then_migrate']);
        $this->assertSame(8, $manifest['summary']['import_unchanged']);
        $this->assertSame(6, $manifest['summary']['do_not_import']);
        $this->assertSame(95, $manifest['summary']['total']);
    }

    public function test_new_attachment_address_is_classified_import_unchanged(): void
    {
        $entry = $this->manifestEntry('module.iam.aws_iam_role_policy_attachment.task_execution_managed');
        $this->assertSame('import_unchanged', $entry['classification']);
        $this->assertSame('firmsbase-staging-ecs-execution-role/'.self::MANAGED_POLICY_ARN, $entry['import_id']);
    }

    public function test_inline_policy_address_is_now_classified_import_unchanged(): void
    {
        $entry = $this->manifestEntry('module.iam.aws_iam_role_policy.task_execution');
        $this->assertSame('import_unchanged', $entry['classification']);
    }

    public function test_execution_role_address_classification_is_untouched_by_this_pass(): void
    {
        // This mission does not change the role's own classification —
        // only the two policy-architecture addresses.
        $entry = $this->manifestEntry('module.iam.aws_iam_role.task_execution');
        $this->assertSame('import_then_migrate', $entry['classification']);
    }

    // ------------------------------------------------------------
    // Both imports stay separate; no permission decision authorized
    // ------------------------------------------------------------

    public function test_neither_policy_resource_is_marked_as_imported(): void
    {
        foreach (['module.iam.aws_iam_role_policy_attachment.task_execution_managed', 'module.iam.aws_iam_role_policy.task_execution'] as $address) {
            $entry = $this->manifestEntry($address);
            $this->assertMatchesRegularExpression('/remains unimported|Remains unimported/', $entry['notes']);
        }
    }

    public function test_documentation_states_both_resources_remain_independent_imports(): void
    {
        $section = $this->extractSection($this->stateAdoptionPlan(), '### 9\.18', '## 10\.');

        $this->assertStringContainsString('module.iam.aws_iam_role_policy_attachment.task_execution_managed', $section);
        $this->assertStringContainsString('module.iam.aws_iam_role_policy.task_execution', $section);
        $this->assertMatchesRegularExpression('/not[\s*]+authorize[\s*]+detaching/i', $section);
        $this->assertMatchesRegularExpression('/permission expansion|expanding\/reducing/i', $section);
    }

    public function test_variable_inventory_documents_the_architecture_correction(): void
    {
        $doc = $this->variableInventory();
        $this->assertStringContainsString('database-migrator', $doc);
        $this->assertStringContainsString('task_execution_secret_arns', $doc);
        $this->assertStringContainsString('aws_iam_role_policy_attachment', $doc);
    }

    // ------------------------------------------------------------
    // No secret value or credential introduced
    // ------------------------------------------------------------

    public function test_no_new_variable_is_marked_sensitive_or_carries_a_secret_value(): void
    {
        foreach (['task_execution_managed_policy_arn', 'task_execution_secret_arns', 'task_execution_ssm_parameter_arns', 'task_execution_kms_decrypt_enabled'] as $name) {
            $var = $this->extractVariableBlock($this->iamModuleVariables(), $name);
            $this->assertStringNotContainsString('sensitive', $var);
        }

        $rootVar = $this->extractVariableBlock($this->stagingVariables(), 'db_migrator_secret_arn');
        $this->assertStringNotContainsString('sensitive', $rootVar, 'The secret ARN identifier is not the secret value — but this must not become a sensitive-marked passthrough for the value itself either way.');
    }

    public function test_live_secret_arns_constant_matches_what_this_test_expects_the_wiring_to_produce(): void
    {
        // Sanity check on this test file's own fixture — proves
        // self::LIVE_SECRET_ARNS is exactly 4 unique ARNs, matching the
        // live get-role-policy Resource list this correction targets.
        $this->assertCount(4, self::LIVE_SECRET_ARNS);
        $this->assertCount(4, array_unique(self::LIVE_SECRET_ARNS));
    }
}
