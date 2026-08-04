<?php

declare(strict_types=1);

namespace Tests\Feature\Ecs;

use Tests\TestCase;

/**
 * Proves the import-graph correction against the real, committed Terraform
 * files — never against a live `terraform plan`/`apply`/`import` (no AWS
 * contact, no credentials needed, fully deterministic), mirroring this
 * repo's existing philosophy of reading real committed files directly.
 *
 * `terraform test` (run separately, not by PHPUnit; see
 * infrastructure/ecs/modules/{cloudwatch_alarms,iam,ecs_service}/tests/)
 * proves the resulting for_each/count instance sets are correct under
 * mock_provider. It cannot, however, reproduce the ORIGINAL failure mode
 * itself — mock_provider synthesizes known values differently than a real
 * provider's create-plan (see those test files' own docblocks for the full
 * explanation). This file instead proves the STRUCTURAL, source-level
 * property the fix actually depends on: none of the corrected
 * count/for_each expressions compare a module-output-derived variable to
 * null, and none derives its key set from another resource's own for_each
 * map. That property holds regardless of what any given Terraform version
 * or provider does at plan time — it is a fact about the source text.
 */
class StagingImportGraphSafetyTest extends TestCase
{
    private function ecsServiceMain(): string
    {
        return $this->readFile('infrastructure/ecs/modules/ecs_service/main.tf');
    }

    private function ecsServiceVariables(): string
    {
        return $this->readFile('infrastructure/ecs/modules/ecs_service/variables.tf');
    }

    private function iamMain(): string
    {
        return $this->readFile('infrastructure/ecs/modules/iam/main.tf');
    }

    private function iamVariables(): string
    {
        return $this->readFile('infrastructure/ecs/modules/iam/variables.tf');
    }

    private function cloudwatchAlarmsMain(): string
    {
        return $this->readFile('infrastructure/ecs/modules/cloudwatch_alarms/main.tf');
    }

    private function cloudwatchAlarmsVariables(): string
    {
        return $this->readFile('infrastructure/ecs/modules/cloudwatch_alarms/variables.tf');
    }

    private function stagingMain(): string
    {
        return $this->readFile('infrastructure/ecs/environments/staging/main.tf');
    }

    private function readFile(string $relativePath): string
    {
        $path = base_path($relativePath);
        $contents = file_get_contents($path);
        $this->assertNotFalse($contents, "Failed to read {$relativePath}");

        return $contents;
    }

    private function extractVariableBlock(string $content, string $name): string
    {
        preg_match('/variable "'.preg_quote($name, '/').'" \{.*?\n}/s', $content, $matches);
        $this->assertNotEmpty($matches, "Could not locate variable \"{$name}\".");

        return $matches[0];
    }

    // ------------------------------------------------------------
    // No `== null` check on a module-output-derived variable
    // determines a for_each/count key set anymore, anywhere.
    // ------------------------------------------------------------

    public function test_no_module_output_derived_null_check_gates_a_for_each_or_count_in_ecs_service(): void
    {
        $this->assertDoesNotMatchRegularExpression(
            '/for_each\s*=\s*var\.target_group_arn\s*==\s*null/',
            $this->ecsServiceMain(),
            'The load_balancer dynamic block must no longer gate on target_group_arn == null (unknown-until-apply for a not-yet-imported target group).'
        );
    }

    public function test_no_module_output_derived_null_check_gates_a_for_each_or_count_in_iam(): void
    {
        $iam = $this->iamMain();

        $this->assertDoesNotMatchRegularExpression('/==\s*var\.kms_key_arn\s*==\s*null/', $iam);
        $this->assertDoesNotMatchRegularExpression('/var\.kms_key_arn\s*==\s*null\s*\?/', $iam, 'No dynamic block may gate on kms_key_arn == null (unknown-until-apply for the not-yet-created KMS key).');
        $this->assertDoesNotMatchRegularExpression('/var\.s3_documents_bucket_arn\s*==\s*null\s*\?/', $iam, 'No count/for_each may gate on s3_documents_bucket_arn == null (unknown-until-apply for the not-yet-created S3 bucket).');
        // Anchored to the start of a (trimmed) line so this only matches
        // real code, never this file's own explanatory comment that
        // mentions the banned pattern as an illustrative example.
        $this->assertDoesNotMatchRegularExpression(
            '/^\s*for_each\s*=\s*aws_iam_role\.task\b(?!\[)/m',
            $iam,
            'aws_iam_role_policy.task_metrics must not derive its for_each from aws_iam_role.task\'s own for_each map.'
        );
    }

    public function test_no_module_output_derived_null_check_gates_a_for_each_or_count_in_cloudwatch_alarms(): void
    {
        $alarms = $this->cloudwatchAlarmsMain();

        $this->assertDoesNotMatchRegularExpression(
            '/var\.ses_consumer_service_name\s*==\s*null\s*\?/',
            $alarms,
            'The per-service alarm for_each must not gate on ses_consumer_service_name == null (unknown-until-apply for the not-yet-created ses-consumer service).'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/count\s*=\s*var\.ses_consumer_log_group_name\s*==\s*null/',
            $alarms,
            'The ses-consumer log-metric-filter/alarm count must not gate on ses_consumer_log_group_name == null.'
        );
    }

    // ------------------------------------------------------------
    // ecs_service: attach_target_group
    // ------------------------------------------------------------

    public function test_attach_target_group_variable_is_a_bool_defaulting_false(): void
    {
        $block = $this->extractVariableBlock($this->ecsServiceVariables(), 'attach_target_group');
        $this->assertStringContainsString('type        = bool', $block);
        $this->assertMatchesRegularExpression('/default\s*=\s*false/', $block);
    }

    public function test_load_balancer_dynamic_block_gated_by_attach_target_group(): void
    {
        preg_match('/dynamic "load_balancer" \{.*?\n  }/s', $this->ecsServiceMain(), $matches);
        $this->assertNotEmpty($matches, 'Could not locate the load_balancer dynamic block.');
        $this->assertMatchesRegularExpression('/for_each\s*=\s*var\.attach_target_group\s*\?\s*\[1\]\s*:\s*\[\]/', $matches[0]);
    }

    public function test_health_check_grace_period_gated_by_attach_target_group(): void
    {
        $this->assertMatchesRegularExpression(
            '/health_check_grace_period_seconds\s*=\s*var\.attach_target_group\s*\?\s*60\s*:\s*null/',
            $this->ecsServiceMain()
        );
    }

    // ------------------------------------------------------------
    // iam: kms_encryption_enabled, s3_documents_enabled, task_role_names
    // ------------------------------------------------------------

    public function test_kms_and_s3_enabled_flags_are_bools_defaulting_false(): void
    {
        foreach (['kms_encryption_enabled', 's3_documents_enabled'] as $name) {
            $block = $this->extractVariableBlock($this->iamVariables(), $name);
            $this->assertStringContainsString('type        = bool', $block);
            $this->assertMatchesRegularExpression('/default\s*=\s*false/', $block);
        }
    }

    public function test_task_role_names_local_lists_all_seven_roles(): void
    {
        preg_match('/task_role_names\s*=\s*\[(.*?)\]/s', $this->iamMain(), $matches);
        $this->assertNotEmpty($matches, 'Could not locate local.task_role_names.');
        foreach (['web', 'worker', 'critical_worker', 'scheduler', 'migrate', 'maintenance', 'ses_consumer'] as $role) {
            $this->assertStringContainsString("\"{$role}\"", $matches[1]);
        }
    }

    public function test_aws_iam_role_task_and_task_metrics_both_key_off_task_role_names(): void
    {
        $iam = $this->iamMain();

        preg_match('/resource "aws_iam_role" "task" \{.*?\n}/s', $iam, $roleBlock);
        $this->assertNotEmpty($roleBlock);
        $this->assertMatchesRegularExpression('/for_each\s*=\s*toset\(local\.task_role_names\)/', $roleBlock[0]);

        preg_match('/resource "aws_iam_role_policy" "task_metrics" \{.*?\n}/s', $iam, $policyBlock);
        $this->assertNotEmpty($policyBlock);
        $this->assertMatchesRegularExpression('/for_each\s*=\s*toset\(local\.task_role_names\)/', $policyBlock[0]);
        $this->assertMatchesRegularExpression('/role\s*=\s*aws_iam_role\.task\[each\.key\]\.id/', $policyBlock[0]);
    }

    // ------------------------------------------------------------
    // cloudwatch_alarms: ses_consumer_enabled, service_alarm_names
    // ------------------------------------------------------------

    public function test_ses_consumer_enabled_is_a_bool_defaulting_false(): void
    {
        $block = $this->extractVariableBlock($this->cloudwatchAlarmsVariables(), 'ses_consumer_enabled');
        $this->assertStringContainsString('type        = bool', $block);
        $this->assertMatchesRegularExpression('/default\s*=\s*false/', $block);
    }

    public function test_service_alarm_names_local_gated_by_the_boolean_not_a_null_check(): void
    {
        preg_match('/service_alarm_names\s*=\s*merge\(.*?\n  \)/s', $this->cloudwatchAlarmsMain(), $matches);
        $this->assertNotEmpty($matches, 'Could not locate local.service_alarm_names.');
        $this->assertMatchesRegularExpression(
            '/var\.ses_consumer_enabled\s*\?\s*\{\s*ses_consumer\s*=\s*var\.ses_consumer_service_name\s*\}\s*:\s*\{\}/',
            $matches[0]
        );
    }

    public function test_both_per_service_alarms_use_the_shared_local(): void
    {
        $alarms = $this->cloudwatchAlarmsMain();

        preg_match('/resource "aws_cloudwatch_metric_alarm" "ecs_service_running_count" \{.*?\n}/s', $alarms, $runningCount);
        $this->assertNotEmpty($runningCount);
        $this->assertMatchesRegularExpression('/for_each\s*=\s*local\.service_alarm_names/', $runningCount[0]);

        preg_match('/resource "aws_cloudwatch_metric_alarm" "ecs_service_cpu_high" \{.*?\n}/s', $alarms, $cpuHigh);
        $this->assertNotEmpty($cpuHigh);
        $this->assertMatchesRegularExpression('/for_each\s*=\s*local\.service_alarm_names/', $cpuHigh[0]);
    }

    public function test_ses_consumer_log_alarms_gated_by_the_boolean(): void
    {
        $alarms = $this->cloudwatchAlarmsMain();

        preg_match('/resource "aws_cloudwatch_log_metric_filter" "ses_consumer_errors" \{.*?\n}/s', $alarms, $filter);
        $this->assertNotEmpty($filter);
        $this->assertMatchesRegularExpression('/count\s*=\s*var\.ses_consumer_enabled\s*\?\s*1\s*:\s*0/', $filter[0]);

        preg_match('/resource "aws_cloudwatch_metric_alarm" "ses_consumer_errors_high" \{.*?\n}/s', $alarms, $alarm);
        $this->assertNotEmpty($alarm);
        $this->assertMatchesRegularExpression('/count\s*=\s*var\.ses_consumer_enabled\s*\?\s*1\s*:\s*0/', $alarm[0]);
    }

    // ------------------------------------------------------------
    // staging root: every new flag is actually wired to true where needed
    // ------------------------------------------------------------

    public function test_staging_passes_the_new_flags_at_every_call_site(): void
    {
        $main = $this->stagingMain();

        preg_match('/module "iam" \{.*?\n}/s', $main, $iamBlock);
        $this->assertNotEmpty($iamBlock);
        $this->assertMatchesRegularExpression('/kms_encryption_enabled\s*=\s*true/', $iamBlock[0]);
        $this->assertMatchesRegularExpression('/s3_documents_enabled\s*=\s*true/', $iamBlock[0]);

        preg_match('/module "web" \{.*?\n}/s', $main, $webBlock);
        $this->assertNotEmpty($webBlock);
        $this->assertMatchesRegularExpression('/attach_target_group\s*=\s*true/', $webBlock[0]);

        preg_match('/module "cloudwatch_alarms" \{.*?\n}/s', $main, $alarmsBlock);
        $this->assertNotEmpty($alarmsBlock);
        $this->assertMatchesRegularExpression('/ses_consumer_enabled\s*=\s*true/', $alarmsBlock[0]);
    }

    // ------------------------------------------------------------
    // Resource addresses were preserved — only for_each/count expressions
    // changed, never the resource type+name declarations themselves.
    // ------------------------------------------------------------

    public function test_every_touched_resource_address_still_exists_unchanged(): void
    {
        $expectations = [
            'infrastructure/ecs/modules/ecs_service/main.tf' => ['resource "aws_ecs_service" "this" {'],
            'infrastructure/ecs/modules/iam/main.tf' => [
                'resource "aws_iam_role" "task" {',
                'resource "aws_iam_role_policy" "task_metrics" {',
                'resource "aws_iam_role_policy" "task_s3_documents" {',
                'data "aws_iam_policy_document" "task_s3_documents" {',
            ],
            'infrastructure/ecs/modules/cloudwatch_alarms/main.tf' => [
                'resource "aws_cloudwatch_metric_alarm" "ecs_service_running_count" {',
                'resource "aws_cloudwatch_metric_alarm" "ecs_service_cpu_high" {',
                'resource "aws_cloudwatch_log_metric_filter" "ses_consumer_errors" {',
                'resource "aws_cloudwatch_metric_alarm" "ses_consumer_errors_high" {',
            ],
        ];

        foreach ($expectations as $path => $declarations) {
            $content = $this->readFile($path);
            foreach ($declarations as $declaration) {
                $this->assertStringContainsString($declaration, $content, "{$path} must still declare: {$declaration}");
            }
        }
    }

    // ------------------------------------------------------------
    // No credential or secret value introduced
    // ------------------------------------------------------------

    public function test_no_credential_or_secret_value_was_introduced(): void
    {
        foreach ([
            'infrastructure/ecs/modules/ecs_service/main.tf',
            'infrastructure/ecs/modules/ecs_service/variables.tf',
            'infrastructure/ecs/modules/iam/main.tf',
            'infrastructure/ecs/modules/iam/variables.tf',
            'infrastructure/ecs/modules/cloudwatch_alarms/main.tf',
            'infrastructure/ecs/modules/cloudwatch_alarms/variables.tf',
            'infrastructure/ecs/environments/staging/main.tf',
            'infrastructure/ecs/environments/staging/scripts/tf-guard.sh',
        ] as $path) {
            $content = $this->readFile($path);
            $this->assertDoesNotMatchRegularExpression('/AKIA[0-9A-Z]{16}/', $content, "{$path} must not contain an AWS access key ID.");
            $this->assertStringNotContainsString('-----BEGIN', $content, "{$path} must not contain PEM-encoded credential material.");
        }
    }
}
