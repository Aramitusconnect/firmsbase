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
        $iam = $this->iamMain();

        // aws_iam_role.task's for_each now keys off local.task_role_names
        // (a static list, shared with aws_iam_role_policy.task_metrics —
        // see docs/ecs/state-adoption-plan.md §9.9 for why deriving a
        // for_each from another resource's own for_each map is unsafe
        // during `terraform import`) rather than an inline literal list.
        // The invariant this test actually cares about — ses_consumer has
        // its own dedicated task role — still holds via that local.
        preg_match('/resource "aws_iam_role" "task" \{.*?\n}/s', $iam, $roleBlock);
        $this->assertNotEmpty($roleBlock, 'Could not locate resource "aws_iam_role" "task".');
        $this->assertMatchesRegularExpression(
            '/for_each\s*=\s*toset\(local\.task_role_names\)/',
            $roleBlock[0]
        );

        preg_match('/task_role_names\s*=\s*\[(.*?)\]/s', $iam, $localMatch);
        $this->assertNotEmpty($localMatch, 'Could not locate local.task_role_names.');
        $this->assertStringContainsString(
            '"ses_consumer"',
            $localMatch[1],
            'ses_consumer must be in local.task_role_names, giving it its own dedicated task role.'
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

    // ------------------------------------------------------------
    // Public-IP / NAT-egress safety design (see
    // docs/ecs/state-adoption-plan.md §9.1 and §7). Live staging has
    // no confirmed NAT gateway and the running services use
    // assignPublicIp=ENABLED — a hardcoded `assign_public_ip = false`
    // in the shared module would create an outage risk on any future
    // apply. These tests replace the obsolete
    // test_ecs_service_module_never_assigns_a_public_ip_to_any_role,
    // which asserted exactly that hardcoded, now-removed literal.
    // ------------------------------------------------------------

    public function test_ecs_service_module_derives_public_ip_from_a_caller_supplied_variable(): void
    {
        $this->assertMatchesRegularExpression(
            '/assign_public_ip\s*=\s*var\.assign_public_ip/',
            $this->readFile('infrastructure/ecs/modules/ecs_service/main.tf'),
            'The shared module must never hardcode assign_public_ip — it must come from var.assign_public_ip so every caller decides explicitly.'
        );
    }

    public function test_ecs_service_assign_public_ip_variable_has_no_permissive_hidden_default(): void
    {
        $block = $this->extractVariableBlock('assign_public_ip');

        $this->assertStringContainsString('type        = bool', $block);
        $this->assertDoesNotMatchRegularExpression(
            '/\bdefault\s*=/',
            $block,
            'assign_public_ip must have no default at all — every caller (including any future environment) must choose explicitly, so a forgotten call site fails terraform validate/plan instead of silently defaulting to something unsafe.'
        );
    }

    public function test_every_staging_ecs_service_module_call_passes_assign_public_ip_from_the_shared_local(): void
    {
        $main = $this->stagingMain();

        foreach (['web', 'worker', 'critical_worker', 'scheduler', 'migrate', 'maintenance', 'ses_consumer'] as $role) {
            preg_match('/module "'.$role.'" \{.*?\n}/s', $main, $block);
            $this->assertNotEmpty($block, "Could not locate module \"{$role}\" block.");
            $this->assertMatchesRegularExpression(
                '/assign_public_ip\s*=\s*local\.assign_public_ip/',
                $block[0],
                "module \"{$role}\" must pass assign_public_ip = local.assign_public_ip — no role may be omitted from this wiring."
            );
        }
    }

    public function test_staging_computes_public_ip_from_the_private_egress_readiness_local(): void
    {
        preg_match('/locals\s*\{.*?\n}/s', $this->stagingMain(), $matches);
        $this->assertNotEmpty($matches, 'Could not locate the first locals block in staging main.tf.');

        $this->assertMatchesRegularExpression(
            '/assign_public_ip\s*=\s*!\s*var\.private_egress_ready/',
            $matches[0],
            'local.assign_public_ip must be derived from private_egress_ready (negated), not a separate independent toggle.'
        );
    }

    public function test_private_egress_ready_defaults_false_so_public_ip_stays_enabled_by_default(): void
    {
        $block = $this->extractStagingVariableBlock('private_egress_ready');

        $this->assertStringContainsString('type        = bool', $block);
        $this->assertMatchesRegularExpression(
            '/default\s*=\s*false/',
            $block,
            'private_egress_ready must default to false — combined with local.assign_public_ip = !var.private_egress_ready, this is what keeps every ECS service publicly IP-addressed by default, matching the live VPC (no confirmed NAT gateway) and avoiding an outage on first apply.'
        );
    }

    public function test_private_egress_ready_cannot_be_declared_true_without_nat_gateway_ids(): void
    {
        $block = $this->extractStagingVariableBlock('nat_gateway_ids');

        $this->assertStringContainsString('type        = list(string)', $block);
        $this->assertMatchesRegularExpression('/default\s*=\s*\[\]/', $block);
        $this->assertStringContainsString('validation {', $block);
        $this->assertMatchesRegularExpression(
            '/condition\s*=\s*!\s*var\.private_egress_ready\s*\|\|\s*length\(var\.nat_gateway_ids\)\s*>\s*0/',
            $block,
            'nat_gateway_ids must cross-validate against private_egress_ready: private_egress_ready may only be true when at least one real NAT gateway ID is supplied — this is what makes the safety invariant enforceable by terraform plan/validate, not just documentation.'
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

    // ------------------------------------------------------------
    // Blocker fix: SES sending permission reconciliation. Proves the
    // grant is real Terraform-managed infrastructure (a resource
    // block, not just a doc claim), scoped to exactly the web role,
    // exactly one identity, with an exact From-address condition — and
    // that no other role (including ses_consumer) can send mail.
    // ------------------------------------------------------------

    private function sesSendPolicyDocumentBlock(): string
    {
        preg_match('/data "aws_iam_policy_document" "task_web_ses_send".*?\n}\n/s', $this->iamMain(), $matches);
        $this->assertNotEmpty($matches, 'Could not locate the task_web_ses_send policy document — the SES-sending grant must be Terraform-managed, not merely documented.');

        return $matches[0];
    }

    public function test_ses_send_permission_is_terraform_managed_not_merely_documented(): void
    {
        $iam = $this->iamMain();

        $this->assertStringContainsString('data "aws_iam_policy_document" "task_web_ses_send"', $iam);
        $this->assertStringContainsString('resource "aws_iam_role_policy" "task_web_ses_send"', $iam);

        preg_match('/resource "aws_iam_role_policy" "task_web_ses_send".*?\n}\n/s', $iam, $resourceMatch);
        $this->assertNotEmpty($resourceMatch);
        $this->assertStringContainsString('aws_iam_role.task["web"]', $resourceMatch[0], 'The SES-send policy resource must attach to the web task role.');
    }

    public function test_web_role_receives_exactly_ses_send_raw_email_on_the_exact_identity_variable(): void
    {
        $block = $this->sesSendPolicyDocumentBlock();

        $this->assertStringContainsString('"ses:SendRawEmail"', $block);
        $this->assertStringNotContainsString('ses:SendEmail', $block, 'ses:SendEmail is never called by SesTransport — must not be granted without direct code evidence.');
        $this->assertMatchesRegularExpression('/resources\s*=\s*\[var\.ses_sending_identity_arn\]/', $block);
        // Never a hardcoded ARN literal in the reusable module.
        $this->assertDoesNotMatchRegularExpression('/arn:aws:ses:[a-z0-9-]+:\d{12}:/', $block);
    }

    public function test_ses_send_permission_has_an_exact_from_address_condition(): void
    {
        $block = $this->sesSendPolicyDocumentBlock();

        $this->assertStringContainsString('condition {', $block);
        $this->assertStringContainsString('"StringEquals"', $block);
        $this->assertStringContainsString('"ses:FromAddress"', $block);
        $this->assertMatchesRegularExpression('/values\s*=\s*\[var\.ses_authorized_from_address\]/', $block);
    }

    public function test_no_ses_wildcard_or_all_identities_grant_exists(): void
    {
        $iam = $this->iamMain();

        $this->assertStringNotContainsString('"ses:*"', $iam);
        $this->assertStringNotContainsString("'ses:*'", $iam);
        // The only resources block inside the SES policy document must
        // be the single-element identity-ARN list already asserted
        // above — assert there is no "resources = [\"*\"]" anywhere
        // near an ses: action.
        $block = $this->sesSendPolicyDocumentBlock();
        $this->assertDoesNotMatchRegularExpression('/resources\s*=\s*\["\*"\]/', $block);
    }

    public function test_ses_consumer_and_unrelated_roles_receive_no_ses_permission(): void
    {
        $iam = $this->iamMain();

        // Exactly one ses: policy document exists in the entire
        // module, and it is the web-scoped one asserted above — proves
        // no *other* policy document anywhere (ses_consumer's own SQS
        // policy, task_s3_documents, task_metrics, task_execution)
        // references any ses: action.
        $this->assertSame(
            1,
            substr_count($iam, 'actions   = ["ses:SendRawEmail"]'),
            'Exactly one policy statement should grant ses:SendRawEmail, scoped to web only.'
        );

        foreach (['task_ses_consumer_sqs', 'task_s3_documents', 'task_metrics', 'task_execution'] as $name) {
            preg_match('/data "aws_iam_policy_document" "'.$name.'".*?\n}\n/s', $iam, $blockMatch);
            $this->assertNotEmpty($blockMatch, "Could not locate data \"aws_iam_policy_document\" \"{$name}\" block.");
            $this->assertStringNotContainsString('ses:', $blockMatch[0], "{$name} must not reference any ses: action.");
        }
    }

    public function test_execution_role_secret_access_is_exact_not_wildcarded(): void
    {
        // Superseded 2026-08-05 (see docs/ecs/state-adoption-plan.md
        // §9.18): a fresh aws iam get-role-policy re-verification found
        // live's execution-role inline policy does NOT grant access to
        // the platform-notifications HMAC-key secret at all — only
        // app-key, db-password, redis-auth-token, and db-migrator. The
        // module's secret_arns variable was renamed
        // task_execution_secret_arns and the HMAC-key ARN was removed
        // from the staging wiring; adding it back would be a permission
        // EXPANSION beyond today's live grant (the HMAC secret is still
        // referenced by not-yet-deployed task definitions — see §9.18
        // correction 4 — a separate decision for if/when that feature is
        // actually deployed).
        $iam = $this->iamMain();

        preg_match('/sid\s*=\s*"ReadTaskSecrets".*?\n    }\n/s', $iam, $blockMatch);
        $this->assertNotEmpty($blockMatch, 'Could not locate the execution role\'s ReadTaskSecrets statement.');
        $block = $blockMatch[0];

        $this->assertMatchesRegularExpression('/resources\s*=\s*var\.task_execution_secret_arns/', $block, 'The execution role\'s secret access must be the exact var.task_execution_secret_arns list, never a wildcard.');
        $this->assertStringNotContainsString('"*"', $block);

        // The HMAC secret ARN variable must NOT be included in the
        // execution role's secret list — live does not grant it.
        preg_match('/task_execution_secret_arns\s*=\s*\[(.*?)\]/s', $this->stagingMain(), $callSite);
        $this->assertNotEmpty($callSite, 'Could not locate the task_execution_secret_arns list passed to module.iam.');
        $this->assertStringNotContainsString('var.platform_notifications_recipient_fingerprint_hmac_key_secret_arn', $callSite[1]);
        $this->assertStringContainsString('var.db_migrator_secret_arn', $callSite[1]);
    }

    private function extractVariableBlock(string $name): string
    {
        $content = $this->readFile('infrastructure/ecs/modules/ecs_service/variables.tf');
        preg_match('/variable "'.preg_quote($name, '/').'" \{.*?\n}/s', $content, $matches);
        $this->assertNotEmpty($matches, "Could not locate variable \"{$name}\".");

        return $matches[0];
    }

    private function extractStagingVariableBlock(string $name): string
    {
        $content = $this->readFile('infrastructure/ecs/environments/staging/variables.tf');
        preg_match('/variable "'.preg_quote($name, '/').'" \{.*?\n}/s', $content, $matches);
        $this->assertNotEmpty($matches, "Could not locate variable \"{$name}\" in staging variables.tf.");

        return $matches[0];
    }
}
