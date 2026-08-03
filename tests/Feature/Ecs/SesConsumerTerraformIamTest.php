<?php

declare(strict_types=1);

namespace Tests\Feature\Ecs;

use Tests\TestCase;

/**
 * Proves the ses-consumer ECS/IAM Terraform wiring against the real,
 * committed .tf files — never against a live `terraform plan`/`apply`
 * (no AWS contact, no credentials needed, fully deterministic), mirroring
 * this repo's existing EntrypointRedisTlsValidationTest philosophy of
 * reading real committed files directly rather than reimplementing their
 * logic. `terraform validate`/`fmt` (run separately, not by PHPUnit) prove
 * the HCL itself is syntactically/semantically valid; these tests prove
 * the specific least-privilege properties the mission requires.
 */
class SesConsumerTerraformIamTest extends TestCase
{
    private function iamMain(): string
    {
        return $this->readFile('infrastructure/ecs/modules/iam/main.tf');
    }

    private function stagingMain(): string
    {
        return $this->readFile('infrastructure/ecs/environments/staging/main.tf');
    }

    private function stagingOutputs(): string
    {
        return $this->readFile('infrastructure/ecs/environments/staging/outputs.tf');
    }

    private function readFile(string $relativePath): string
    {
        $path = base_path($relativePath);
        $contents = file_get_contents($path);
        $this->assertNotFalse($contents, "Failed to read {$relativePath}");

        return $contents;
    }

    // ------------------------------------------------------------
    // Task role inventory + SQS policy scoping
    // ------------------------------------------------------------

    public function test_ses_consumer_exists_in_the_task_role_inventory(): void
    {
        $this->assertMatchesRegularExpression(
            '/for_each\s*=\s*toset\(\["web",\s*"worker",\s*"critical_worker",\s*"scheduler",\s*"migrate",\s*"maintenance",\s*"ses_consumer"\]\)/',
            $this->iamMain(),
            'ses_consumer must be added to the aws_iam_role.task for_each set, giving it its own dedicated task role.'
        );
    }

    public function test_ses_consumer_sqs_policy_grants_only_receive_and_delete_message(): void
    {
        $iam = $this->iamMain();

        $this->assertStringContainsString('data "aws_iam_policy_document" "task_ses_consumer_sqs"', $iam);

        preg_match(
            '/data "aws_iam_policy_document" "task_ses_consumer_sqs".*?\n}/s',
            $iam,
            $matches
        );
        $this->assertNotEmpty($matches, 'Could not locate the task_ses_consumer_sqs policy document block.');
        $block = $matches[0];

        $this->assertStringContainsString('"sqs:ReceiveMessage"', $block);
        $this->assertStringContainsString('"sqs:DeleteMessage"', $block);
        $this->assertStringNotContainsString('sqs:GetQueueAttributes', $block);
        $this->assertStringNotContainsString('sqs:ChangeMessageVisibility', $block);
        $this->assertStringNotContainsString('sqs:SendMessage', $block);
        $this->assertStringNotContainsString('sqs:PurgeQueue', $block);
        $this->assertStringNotContainsString('sqs:SetQueueAttributes', $block);
        $this->assertStringNotContainsString('sqs:GetQueueUrl', $block);
        $this->assertStringNotContainsString('sqs:*', $block);
        $this->assertStringContainsString('var.ses_events_queue_arn', $block);
    }

    public function test_ses_consumer_sqs_policy_resource_is_exactly_the_primary_queue_arn_variable_never_hardcoded(): void
    {
        preg_match(
            '/data "aws_iam_policy_document" "task_ses_consumer_sqs".*?\n}/s',
            $this->iamMain(),
            $matches
        );
        $this->assertNotEmpty($matches);
        $block = $matches[0];

        $this->assertMatchesRegularExpression('/resources\s*=\s*\[var\.ses_events_queue_arn\]/', $block);
        // Never a hardcoded ARN literal in the reusable module.
        $this->assertDoesNotMatchRegularExpression('/arn:aws:sqs:[a-z0-9-]+:\d{12}:/', $block);
    }

    public function test_no_sqs_wildcard_anywhere_in_the_iam_module(): void
    {
        $this->assertStringNotContainsString('"sqs:*"', $this->iamMain());
        $this->assertStringNotContainsString("'sqs:*'", $this->iamMain());
    }

    public function test_ses_consumer_sqs_policy_is_attached_only_to_the_ses_consumer_task_role(): void
    {
        $iam = $this->iamMain();

        $this->assertStringContainsString('resource "aws_iam_role_policy" "task_ses_consumer_sqs"', $iam);

        preg_match(
            '/resource "aws_iam_role_policy" "task_ses_consumer_sqs".*?\n}/s',
            $iam,
            $matches
        );
        $this->assertNotEmpty($matches);
        $this->assertStringContainsString('aws_iam_role.task["ses_consumer"]', $matches[0]);
    }

    public function test_no_other_task_role_references_the_ses_events_queue_arn_or_any_sqs_action(): void
    {
        $iam = $this->iamMain();

        // Every sqs: action mention in the whole file must be inside the
        // one ses_consumer-scoped policy document — never referenced by
        // task_s3_documents, task_metrics, or any other policy document.
        $this->assertSame(
            1,
            substr_count($iam, 'data "aws_iam_policy_document" "task_ses_consumer_sqs"'),
            'Exactly one SQS policy document should exist, scoped to ses_consumer only.'
        );

        // No unrelated policy document (S3, metrics, execution role) may
        // itself mention any sqs: action.
        foreach (['task_s3_documents', 'task_metrics', 'task_execution'] as $name) {
            preg_match('/data "aws_iam_policy_document" "'.$name.'".*?\n}\n/s', $iam, $blockMatch);
            $this->assertNotEmpty($blockMatch, "Could not locate data \"aws_iam_policy_document\" \"{$name}\" block.");
            $this->assertStringNotContainsString('sqs:', $blockMatch[0], "{$name} must not reference any sqs: action.");
        }
    }

    public function test_s3_document_role_names_does_not_include_ses_consumer(): void
    {
        preg_match('/s3_document_role_names\s*=\s*\[(.*?)\]/s', $this->iamMain(), $matches);
        $this->assertNotEmpty($matches, 'Could not locate local.s3_document_role_names.');
        $this->assertStringNotContainsString('ses_consumer', $matches[1], 'ses_consumer has no documented S3 need and must not receive the S3 grant.');
    }

    // ------------------------------------------------------------
    // Secret scoping (web + ses-consumer only)
    // ------------------------------------------------------------

    public function test_web_and_ses_consumer_receive_the_hmac_secret_via_merge(): void
    {
        $main = $this->stagingMain();

        preg_match('/module "web" \{.*?\n}/s', $main, $webBlock);
        preg_match('/module "ses_consumer" \{.*?\n}/s', $main, $sesBlock);
        $this->assertNotEmpty($webBlock);
        $this->assertNotEmpty($sesBlock);

        $this->assertMatchesRegularExpression('/secrets\s*=\s*merge\(local\.shared_secrets,\s*local\.hmac_secret\)/', $webBlock[0]);
        $this->assertMatchesRegularExpression('/secrets\s*=\s*merge\(local\.shared_secrets,\s*local\.hmac_secret\)/', $sesBlock[0]);
    }

    public function test_unrelated_roles_do_not_receive_the_hmac_secret(): void
    {
        $main = $this->stagingMain();

        foreach (['worker', 'critical_worker', 'scheduler', 'migrate', 'maintenance'] as $role) {
            preg_match('/module "'.$role.'" \{.*?\n}/s', $main, $block);
            $this->assertNotEmpty($block, "Could not locate module \"{$role}\" block.");
            $this->assertStringNotContainsString('hmac_secret', $block[0], "module \"{$role}\" must not reference local.hmac_secret.");
            $this->assertMatchesRegularExpression('/secrets\s*=\s*local\.shared_secrets/', $block[0], "module \"{$role}\" should use the plain shared_secrets map, unmodified.");
        }
    }

    public function test_hmac_secret_local_defines_exactly_the_platform_notifications_env_var(): void
    {
        preg_match('/hmac_secret\s*=\s*\{(.*?)\}/s', $this->stagingMain(), $matches);
        $this->assertNotEmpty($matches, 'Could not locate local.hmac_secret.');
        $this->assertStringContainsString('PLATFORM_NOTIFICATIONS_RECIPIENT_FINGERPRINT_HMAC_KEY', $matches[1]);
        $this->assertStringContainsString('var.platform_notifications_recipient_fingerprint_hmac_key_secret_arn', $matches[1]);
    }

    public function test_hmac_key_is_never_placed_in_plain_environment_maps(): void
    {
        $main = $this->stagingMain();

        preg_match('/shared_environment\s*=\s*\{.*?\n  }/s', $main, $sharedEnv);
        preg_match('/ses_events_environment\s*=\s*\{.*?\n  }/s', $main, $sesEnv);
        $this->assertNotEmpty($sharedEnv);
        $this->assertNotEmpty($sesEnv);

        $this->assertStringNotContainsString('PLATFORM_NOTIFICATIONS_RECIPIENT_FINGERPRINT_HMAC_KEY', $sharedEnv[0]);
        $this->assertStringNotContainsString('PLATFORM_NOTIFICATIONS_RECIPIENT_FINGERPRINT_HMAC_KEY', $sesEnv[0]);
    }

    public function test_queue_url_is_plain_environment_not_a_secret(): void
    {
        preg_match('/ses_events_environment\s*=\s*\{(.*?)\n  }/s', $this->stagingMain(), $matches);
        $this->assertNotEmpty($matches);
        $this->assertStringContainsString('SES_EVENTS_QUEUE_URL', $matches[1]);
        $this->assertStringContainsString('var.ses_events_queue_url', $matches[1]);

        // And never inside local.hmac_secret or local.shared_secrets.
        preg_match('/hmac_secret\s*=\s*\{(.*?)\}/s', $this->stagingMain(), $hmacMatches);
        $this->assertStringNotContainsString('SES_EVENTS_QUEUE_URL', $hmacMatches[1] ?? '');
    }

    // ------------------------------------------------------------
    // No static AWS credentials configured anywhere in Terraform
    // ------------------------------------------------------------

    public function test_static_aws_credential_variables_are_not_configured_in_terraform(): void
    {
        foreach ([$this->stagingMain(), $this->readFile('infrastructure/ecs/environments/staging/variables.tf'), $this->readFile('infrastructure/ecs/environments/staging/terraform.tfvars.example')] as $content) {
            $this->assertStringNotContainsString('SES_EVENTS_AWS_ACCESS_KEY_ID', $content);
            $this->assertStringNotContainsString('SES_EVENTS_AWS_SECRET_ACCESS_KEY', $content);
        }
    }

    // ------------------------------------------------------------
    // No secret VALUES in outputs (ARNs are identifiers, not values,
    // and are fine — but the module must never resolve/output the
    // underlying secret content).
    // ------------------------------------------------------------

    public function test_outputs_never_expose_a_secret_value(): void
    {
        $outputs = $this->stagingOutputs();

        $this->assertStringNotContainsString('hmac_key_secret_arn', $outputs, 'Outputs must not surface the HMAC secret ARN — it is not needed by any consumer of Terraform outputs and keeps the output list minimal.');
        $this->assertStringNotContainsString('app_key_secret_arn', $outputs);
        $this->assertStringNotContainsString('db_password_secret_arn', $outputs);
        $this->assertStringNotContainsString('redis_auth_token', $outputs);
    }

    public function test_outputs_include_the_expected_non_secret_ses_consumer_values(): void
    {
        $outputs = $this->stagingOutputs();

        $this->assertStringContainsString('ses_consumer_service_name', $outputs);
        $this->assertStringContainsString('ses_consumer_task_role_arn', $outputs);
        $this->assertStringContainsString('ses_consumer_log_group_name', $outputs);
        $this->assertStringContainsString('ses_consumer', $outputs); // task_definition_arns map entry
    }

    // ------------------------------------------------------------
    // ECS service shape: no ALB, no public IP, desired count +
    // circuit breaker, dedicated logging, autoscaling disabled.
    // ------------------------------------------------------------

    public function test_ses_consumer_service_has_no_alb_target_group(): void
    {
        preg_match('/module "ses_consumer" \{.*?\n}/s', $this->stagingMain(), $matches);
        $this->assertNotEmpty($matches);
        // A plain substring check would false-positive on this module
        // block's own explanatory comment ("# No target_group_arn —
        // never behind the ALB...") — assert there is no actual
        // `target_group_arn = ...` assignment instead.
        $this->assertDoesNotMatchRegularExpression('/target_group_arn\s*=/', $matches[0]);
    }

    public function test_ecs_service_module_never_assigns_a_public_ip_to_any_role(): void
    {
        $this->assertStringContainsString(
            'assign_public_ip = false',
            $this->readFile('infrastructure/ecs/modules/ecs_service/main.tf')
        );
    }

    public function test_ses_consumer_has_desired_count_and_the_circuit_breaker_is_not_disabled(): void
    {
        preg_match('/module "ses_consumer" \{.*?\n}/s', $this->stagingMain(), $matches);
        $this->assertNotEmpty($matches);
        $block = $matches[0];

        $this->assertStringContainsString('desired_count      = var.ses_consumer_desired_count', $block);
        // enable_deployment_circuit_breaker defaults to true in the shared
        // module (infrastructure/ecs/modules/ecs_service/variables.tf) and
        // is not overridden here — confirm it is not explicitly disabled.
        $this->assertStringNotContainsString('enable_deployment_circuit_breaker = false', $block);
        $this->assertStringContainsString(
            'default     = true',
            $this->extractVariableBlock('enable_deployment_circuit_breaker')
        );
    }

    public function test_ses_consumer_has_dedicated_logging(): void
    {
        $main = $this->stagingMain();

        $this->assertMatchesRegularExpression('/roles\s*=\s*\[.*"ses-consumer"\]/', $main);

        preg_match('/module "ses_consumer" \{.*?\n}/s', $main, $matches);
        $this->assertNotEmpty($matches);
        $this->assertStringContainsString('aws_cloudwatch_log_group.app["ses-consumer"].name', $matches[0]);
    }

    public function test_ses_consumer_autoscaling_is_disabled(): void
    {
        preg_match('/module "ses_consumer" \{.*?\n}/s', $this->stagingMain(), $matches);
        $this->assertNotEmpty($matches);
        $this->assertStringContainsString('enable_autoscaling = false', $matches[0]);
    }

    private function extractVariableBlock(string $name): string
    {
        $content = $this->readFile('infrastructure/ecs/modules/ecs_service/variables.tf');
        preg_match('/variable "'.preg_quote($name, '/').'" \{.*?\n}/s', $content, $matches);
        $this->assertNotEmpty($matches, "Could not locate variable \"{$name}\".");

        return $matches[0];
    }
}
