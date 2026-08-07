<?php

declare(strict_types=1);

namespace Tests\Feature\Ecs;

use Tests\TestCase;

/**
 * Proves the KMS key-policy correction (see docs/ecs/state-adoption-plan.md):
 * a real, evidence-proven incident showed AWS's own default key policy
 * (a single account-root "Enable IAM User Permissions" statement) does not
 * trust the CloudWatch Logs service principal, so every one of this
 * environment's aws_cloudwatch_log_group resources (all wired to this key
 * via kms_key_id) fails to create with AccessDeniedException. This asserts
 * the real, committed HCL — never a live plan/apply — since terraform
 * test's mock_provider cannot exercise this specific data source (it
 * mocks all "aws" provider data-source outputs uniformly, including pure,
 * no-API-call computations like aws_iam_policy_document, replacing them
 * with random placeholder strings regardless of config). The resulting
 * policy JSON was independently verified via a real, unmocked, offline
 * `terraform plan` in an isolated local-backend copy (see this mission's
 * final report) — this test proves the HCL that produced that verified
 * output has not since drifted.
 */
class StagingKmsKeyPolicyTest extends TestCase
{
    private function kmsModuleMain(): string
    {
        return $this->readFile('infrastructure/ecs/modules/kms/main.tf');
    }

    private function kmsModuleVariables(): string
    {
        return $this->readFile('infrastructure/ecs/modules/kms/variables.tf');
    }

    private function stagingMain(): string
    {
        return $this->readFile('infrastructure/ecs/environments/staging/main.tf');
    }

    private function stateAdoptionPlan(): string
    {
        return $this->readFile('docs/ecs/state-adoption-plan.md');
    }

    private function readFile(string $relativePath): string
    {
        $path = base_path($relativePath);
        $contents = file_get_contents($path);
        $this->assertNotFalse($contents, "Failed to read {$relativePath}");

        return $contents;
    }

    private function extractResourceBlock(string $content, string $type, string $name): string
    {
        preg_match('/resource\s+"'.preg_quote($type, '/').'"\s+"'.preg_quote($name, '/').'"\s*\{.*?\n\}\n/s', $content, $matches);
        $this->assertNotEmpty($matches, "Could not locate resource \"{$type}\" \"{$name}\".");

        return $matches[0];
    }

    private function extractDataBlock(string $content, string $type, string $name): string
    {
        preg_match('/data\s+"'.preg_quote($type, '/').'"\s+"'.preg_quote($name, '/').'"\s*\{.*?\n\}\n/s', $content, $matches);
        $this->assertNotEmpty($matches, "Could not locate data \"{$type}\" \"{$name}\".");

        return $matches[0];
    }

    // ------------------------------------------------------------
    // The key now explicitly manages a policy, wired from the data source
    // ------------------------------------------------------------

    public function test_key_wires_the_explicit_policy_document_instead_of_the_aws_default(): void
    {
        $block = $this->extractResourceBlock($this->kmsModuleMain(), 'aws_kms_key', 'this');

        $this->assertMatchesRegularExpression(
            '/policy\s*=\s*data\.aws_iam_policy_document\.this\.json/',
            $block
        );
    }

    public function test_key_identity_and_rotation_settings_unaffected_by_the_policy_change(): void
    {
        $block = $this->extractResourceBlock($this->kmsModuleMain(), 'aws_kms_key', 'this');

        $this->assertMatchesRegularExpression('/enable_key_rotation\s*=\s*true/', $block);
        $this->assertMatchesRegularExpression('/deletion_window_in_days\s*=\s*30/', $block);
        $this->assertMatchesRegularExpression('/tags\s*=\s*var\.tags/', $block);
    }

    // ------------------------------------------------------------
    // Statement 1: the account-root statement, preserved byte-for-byte
    // ------------------------------------------------------------

    public function test_account_root_statement_is_preserved_exactly(): void
    {
        $block = $this->extractDataBlock($this->kmsModuleMain(), 'aws_iam_policy_document', 'this');

        $this->assertMatchesRegularExpression('/sid\s*=\s*"Enable IAM User Permissions"/', $block);
        $this->assertMatchesRegularExpression(
            '/identifiers\s*=\s*\[\s*"arn:aws:iam::\$\{var\.aws_account_id\}:root"\s*\]/',
            $block
        );
        $this->assertMatchesRegularExpression('/actions\s*=\s*\[\s*"kms:\*"\s*\]/', $block);

        // This exact statement is not conditional on the dynamic block —
        // it must always be present regardless of
        // cloudwatch_logs_log_group_arn_pattern.
        $beforeDynamic = strstr($block, 'dynamic "statement"', true);
        $this->assertNotFalse($beforeDynamic, 'The account-root statement must be declared before the dynamic (conditional) statement.');
        $this->assertStringContainsString('Enable IAM User Permissions', $beforeDynamic);
    }

    // ------------------------------------------------------------
    // Statement 2: CloudWatch Logs — conditional, narrowly scoped
    // ------------------------------------------------------------

    public function test_cloudwatch_logs_statement_is_conditional_on_the_arn_pattern_variable(): void
    {
        $block = $this->extractDataBlock($this->kmsModuleMain(), 'aws_iam_policy_document', 'this');

        $this->assertMatchesRegularExpression(
            '/dynamic\s+"statement"\s*\{\s*for_each\s*=\s*var\.cloudwatch_logs_log_group_arn_pattern\s*==\s*null\s*\?\s*\[\]\s*:\s*\[var\.cloudwatch_logs_log_group_arn_pattern\]/',
            $block,
            'The CloudWatch Logs statement must be entirely omitted when cloudwatch_logs_log_group_arn_pattern is null — a brand-new environment must be unaffected.'
        );
    }

    public function test_cloudwatch_logs_statement_grants_exactly_the_five_documented_actions(): void
    {
        $block = $this->extractDataBlock($this->kmsModuleMain(), 'aws_iam_policy_document', 'this');

        $this->assertMatchesRegularExpression('/sid\s*=\s*"AllowCloudWatchLogsEncryption"/', $block);

        preg_match('/actions\s*=\s*\[(.*?)\]/s', substr($block, (int) strpos($block, 'AllowCloudWatchLogsEncryption')), $actionsMatch);
        $this->assertNotEmpty($actionsMatch, 'Could not locate the CloudWatch Logs statement\'s actions list.');
        $actions = array_map(
            fn (string $a) => trim($a, " \t\n\r\0\x0B\","),
            array_filter(explode(',', $actionsMatch[1]), fn (string $a) => trim($a) !== '')
        );

        $this->assertSame(
            ['kms:Encrypt', 'kms:Decrypt', 'kms:ReEncrypt*', 'kms:GenerateDataKey*', 'kms:Describe*'],
            $actions,
            'The CloudWatch Logs statement must grant exactly these five actions — never a broader kms:* grant.'
        );
    }

    public function test_cloudwatch_logs_statement_principal_is_the_regional_service_principal_only(): void
    {
        $block = $this->extractDataBlock($this->kmsModuleMain(), 'aws_iam_policy_document', 'this');

        $this->assertMatchesRegularExpression(
            '/type\s*=\s*"Service"\s*\n\s*identifiers\s*=\s*\[\s*"logs\.\$\{var\.aws_region\}\.amazonaws\.com"\s*\]/',
            $block
        );
    }

    public function test_cloudwatch_logs_statement_restricts_use_via_encryption_context_condition(): void
    {
        $block = $this->extractDataBlock($this->kmsModuleMain(), 'aws_iam_policy_document', 'this');

        $this->assertMatchesRegularExpression('/test\s*=\s*"ArnLike"/', $block);
        $this->assertMatchesRegularExpression('/variable\s*=\s*"kms:EncryptionContext:aws:logs:arn"/', $block);
        $this->assertMatchesRegularExpression(
            '/values\s*=\s*\[\s*statement\.value\s*\]/',
            $block,
            'The condition must restrict to exactly the caller-supplied ARN pattern, never a hardcoded or broader value.'
        );
    }

    public function test_no_wildcard_principal_anywhere_in_the_policy(): void
    {
        $block = $this->extractDataBlock($this->kmsModuleMain(), 'aws_iam_policy_document', 'this');

        $this->assertDoesNotMatchRegularExpression('/identifiers\s*=\s*\[\s*"\*"\s*\]/', $block);
        $this->assertDoesNotMatchRegularExpression('/type\s*=\s*"\*"/', $block);
    }

    // ------------------------------------------------------------
    // Module stays generic; variable is caller-supplied, never derived
    // ------------------------------------------------------------

    public function test_arn_pattern_variable_defaults_to_null_and_is_not_derived_inside_the_module(): void
    {
        $vars = $this->kmsModuleVariables();
        preg_match('/variable\s+"cloudwatch_logs_log_group_arn_pattern"\s*\{.*?\n\}\n/s', $vars, $matches);
        $this->assertNotEmpty($matches, 'Could not locate variable "cloudwatch_logs_log_group_arn_pattern".');
        $this->assertMatchesRegularExpression('/default\s*=\s*null/', $matches[0]);

        // The module itself must never build this pattern from
        // var.name_prefix — it must come from the caller.
        $this->assertDoesNotMatchRegularExpression(
            '/cloudwatch_logs_log_group_arn_pattern\s*=\s*"arn:aws:logs/',
            $this->kmsModuleMain(),
            'The module must never hardcode or derive the log-group ARN pattern itself — only the staging root, which knows which log groups actually use this key, may supply it.'
        );
    }

    public function test_account_id_and_region_variables_have_no_default(): void
    {
        $vars = $this->kmsModuleVariables();

        foreach (['aws_account_id', 'aws_region'] as $name) {
            preg_match('/variable\s+"'.$name.'"\s*\{.*?\n\}\n/s', $vars, $matches);
            $this->assertNotEmpty($matches, "Could not locate variable \"{$name}\".");
            $this->assertDoesNotMatchRegularExpression('/default\s*=/', $matches[0], "{$name} must have no default — every caller must supply it explicitly.");
        }
    }

    // ------------------------------------------------------------
    // Staging root supplies the exact live account/region and the exact
    // ARN pattern covering all 7 workload log groups, only this key
    // ------------------------------------------------------------

    public function test_staging_root_supplies_the_exact_account_region_and_log_group_pattern(): void
    {
        $staging = $this->stagingMain();
        preg_match('/module\s+"kms"\s*\{.*?\n\}\n/s', $staging, $matches);
        $this->assertNotEmpty($matches, 'Could not locate module "kms".');
        $block = $matches[0];

        $this->assertStringContainsString('aws_account_id = var.aws_account_id', $block);
        $this->assertStringContainsString('aws_region     = var.aws_region', $block);
        $this->assertMatchesRegularExpression(
            '/cloudwatch_logs_log_group_arn_pattern\s*=\s*"arn:aws:logs:\$\{var\.aws_region\}:\$\{var\.aws_account_id\}:log-group:\/ecs\/\$\{var\.name_prefix\}\/\*"/',
            $block
        );
    }

    public function test_only_one_log_group_resource_exists_and_it_shares_the_same_name_prefix(): void
    {
        $staging = $this->stagingMain();

        $this->assertSame(
            1,
            preg_match_all('/resource\s+"aws_cloudwatch_log_group"/', $staging),
            'There must be exactly one aws_cloudwatch_log_group resource (a for_each over every role) — the ARN pattern above must cover exactly its namespace, nothing more.'
        );
        $this->assertMatchesRegularExpression(
            '/name\s*=\s*"\/ecs\/\$\{var\.name_prefix\}\/\$\{each\.value\}"/',
            $staging
        );
    }

    // ------------------------------------------------------------
    // Documentation records the real incident and the fix honestly
    // ------------------------------------------------------------

    public function test_documentation_records_the_real_incident_and_the_fix(): void
    {
        $doc = $this->stateAdoptionPlan();

        $this->assertStringContainsString('AccessDeniedException', $doc);
        $this->assertStringContainsString('logs.us-east-1.amazonaws.com', $doc);
        $this->assertStringContainsString('AllowCloudWatchLogsEncryption', $doc);
        $this->assertStringContainsString('kms:EncryptionContext:aws:logs:arn', $doc);
    }
}
