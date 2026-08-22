<?php

declare(strict_types=1);

namespace Tests\Feature\Ecs;

use Tests\TestCase;

/**
 * Proves the shared task-role policy audit conclusions (see
 * docs/ecs/state-adoption-plan.md §9.22): the live FirmsVaultStagingSesSend
 * policy's now-confirmed content, the corrected SES resource-scoping
 * conclusion, the seven-role IAM permission matrix, and the migrate
 * secret-wiring gap found during this audit and resolved in a later pass
 * (§9.23) — against the real, committed files only, never a live
 * AWS/Terraform call (fully deterministic, no credentials needed).
 */
class StagingSharedTaskRolePolicyAuditTest extends TestCase
{
    private const TASK_ROLE_KEYS = ['web', 'worker', 'critical_worker', 'scheduler', 'migrate', 'maintenance', 'ses_consumer'];

    private function iamModuleMain(): string
    {
        return $this->readFile('infrastructure/ecs/modules/iam/main.tf');
    }

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

    private function extractResourceBlock(string $content, string $type, string $name): string
    {
        preg_match('/resource "'.preg_quote($type, '/').'" "'.preg_quote($name, '/').'" \{.*?\n\}\n/s', $content, $matches);
        $this->assertNotEmpty($matches, "Could not locate resource \"{$type}\" \"{$name}\".");

        return $matches[0];
    }

    private function extractDataBlock(string $content, string $type, string $name): string
    {
        preg_match('/data "'.preg_quote($type, '/').'" "'.preg_quote($name, '/').'" \{.*?\n\}\n/s', $content, $matches);
        $this->assertNotEmpty($matches, "Could not locate data \"{$type}\" \"{$name}\".");

        return $matches[0];
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

    // ------------------------------------------------------------
    // Corrected SES resource-scoping conclusion
    // ------------------------------------------------------------

    public function test_documentation_states_ses_actions_support_identity_arn_scoping_not_wildcard_requirement(): void
    {
        $doc = $this->stateAdoptionPlan();
        $section = $this->extractSection($doc, '### 9\.22', '## 10\.');

        $this->assertMatchesRegularExpression('/do\s*\*\*not\*\*\s*claim|does not claim|not.{0,20}require/i', $section);
        $this->assertMatchesRegularExpression('/support identity-ARN resource scoping/i', $section);
        $this->assertMatchesRegularExpression('/genuine,\s*intentional least-privilege narrowing/i', $section);
    }

    // ------------------------------------------------------------
    // Live shared policy content, now confirmed
    // ------------------------------------------------------------

    public function test_documentation_records_the_canonical_live_shared_policy_content(): void
    {
        $doc = $this->stateAdoptionPlan();
        $section = $this->extractSection($doc, '### 9\.22', '## 10\.');

        $this->assertStringContainsString('AllowOnlyFirmsVaultStagingSender', $section);
        $this->assertStringContainsString('ses:SendEmail', $section);
        $this->assertStringContainsString('ses:SendRawEmail', $section);
        $this->assertStringContainsString('no-reply@staging-mail.firmsvault.com', $section);
        $this->assertMatchesRegularExpression('/zero attached managed policies/i', $section);
    }

    public function test_manifest_web_ses_send_note_no_longer_claims_actions_are_unread(): void
    {
        $entry = $this->manifestEntry('module.iam.aws_iam_role_policy.task_web_ses_send[0]');
        $notes = $entry['notes'];

        $this->assertStringNotContainsString('remain unread and unconfirmed', $notes);
        $this->assertMatchesRegularExpression('/successfully read/i', $notes);
        $this->assertStringContainsString('ses:SendEmail', $notes);
        $this->assertStringContainsString('ses:SendRawEmail', $notes);
    }

    // ------------------------------------------------------------
    // Proposed web policy: exact SES action/resource/condition
    // ------------------------------------------------------------

    public function test_web_ses_send_policy_grants_only_send_raw_email(): void
    {
        $data = $this->extractDataBlock($this->iamModuleMain(), 'aws_iam_policy_document', 'task_web_ses_send');

        $this->assertMatchesRegularExpression('/actions\s*=\s*\["ses:SendRawEmail"\]/', $data);
        $this->assertDoesNotMatchRegularExpression('/ses:SendEmail/', $data, 'The proposed web policy must not include ses:SendEmail.');
    }

    public function test_web_ses_send_policy_scopes_resource_to_the_identity_arn_variable(): void
    {
        $data = $this->extractDataBlock($this->iamModuleMain(), 'aws_iam_policy_document', 'task_web_ses_send');

        $this->assertMatchesRegularExpression('/resources\s*=\s*\[var\.ses_sending_identity_arn\]/', $data);
        $this->assertDoesNotMatchRegularExpression('/resources\s*=\s*\["\*"\]/', $data);
    }

    public function test_web_ses_send_policy_preserves_the_from_address_condition(): void
    {
        $data = $this->extractDataBlock($this->iamModuleMain(), 'aws_iam_policy_document', 'task_web_ses_send');

        $this->assertMatchesRegularExpression('/variable\s*=\s*"ses:FromAddress"/', $data);
        $this->assertMatchesRegularExpression('/values\s*=\s*\[var\.ses_authorized_from_address\]/', $data);
    }

    // ------------------------------------------------------------
    // No SES send action on any non-web role
    // ------------------------------------------------------------

    public function test_no_ses_send_action_exists_outside_the_web_policy(): void
    {
        $main = $this->iamModuleMain();

        // Strip full-line/trailing comments before counting so prose that
        // legitimately discusses ses:SendEmail/ses:SendRawEmail as
        // historical context (e.g. "confirmed by code inspection that
        // SesTransport only calls ses:SendRawEmail (never ses:SendEmail)")
        // isn't mistaken for a second grant.
        $codeOnly = preg_replace('/#.*$/m', '', $main);

        $count = preg_match_all('/actions\s*=\s*\[[^\]]*ses:Send(Raw)?Email/', $codeOnly);
        $this->assertSame(1, $count, 'Exactly one actions=[...] block in the IAM module may reference ses:Send(Raw)?Email — no other role may grant SES send.');

        $webBlock = $this->extractDataBlock($main, 'aws_iam_policy_document', 'task_web_ses_send');
        $this->assertMatchesRegularExpression('/actions\s*=\s*\["ses:SendRawEmail"\]/', $webBlock);
    }

    public function test_ses_consumer_policy_grants_no_email_send_action(): void
    {
        $data = $this->extractDataBlock($this->iamModuleMain(), 'aws_iam_policy_document', 'task_ses_consumer_sqs');

        $this->assertDoesNotMatchRegularExpression('/ses:/', $data);
        $this->assertMatchesRegularExpression('/actions\s*=\s*\["sqs:ReceiveMessage",\s*"sqs:DeleteMessage"\]/', $data);
        $this->assertMatchesRegularExpression('/resources\s*=\s*\[var\.ses_events_queue_arn\]/', $data);
    }

    // ------------------------------------------------------------
    // Seven-role permission matrix — S3/metrics role-key sets
    // ------------------------------------------------------------

    public function test_s3_document_role_names_excludes_scheduler_migrate_ses_consumer(): void
    {
        $main = $this->iamModuleMain();
        preg_match('/s3_document_role_names\s*=\s*\[([^\]]*)\]/', $main, $matches);
        $this->assertNotEmpty($matches, 'Could not locate local.s3_document_role_names.');

        $list = $matches[1];
        foreach (['web', 'worker', 'critical_worker', 'maintenance'] as $expected) {
            $this->assertStringContainsString("\"{$expected}\"", $list);
        }
        foreach (['scheduler', 'migrate', 'ses_consumer'] as $excluded) {
            $this->assertStringNotContainsString("\"{$excluded}\"", $list);
        }
    }

    public function test_all_seven_roles_are_declared_and_get_metrics_policy(): void
    {
        $main = $this->iamModuleMain();
        preg_match('/task_role_names\s*=\s*\[([^\]]*)\]/', $main, $matches);
        $this->assertNotEmpty($matches);
        $list = $matches[1];

        foreach (self::TASK_ROLE_KEYS as $role) {
            $this->assertStringContainsString("\"{$role}\"", $list);
        }

        // task_metrics for_each is derived from the same static local, not
        // from aws_iam_role.task's own for_each map.
        $this->assertMatchesRegularExpression(
            '/resource "aws_iam_role_policy" "task_metrics" \{[^}]*for_each\s*=\s*toset\(local\.task_role_names\)/s',
            $main
        );
    }

    public function test_no_task_role_has_a_description_argument(): void
    {
        $block = $this->extractResourceBlock($this->iamModuleMain(), 'aws_iam_role', 'task');
        $this->assertDoesNotMatchRegularExpression('/\bdescription\s*=/', $block, 'Task roles (unlike task_execution) have no description argument today — this must remain an accurate, deliberate fact, not silently added.');
    }

    // ------------------------------------------------------------
    // Secret separation
    // ------------------------------------------------------------

    public function test_hmac_secret_is_merged_only_into_web_and_ses_consumer(): void
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

    public function test_execution_role_secret_arns_are_exactly_four_no_wildcard(): void
    {
        $staging = $this->stagingMain();
        $iamBlock = $this->extractModuleBlock($staging, 'iam');

        preg_match('/task_execution_secret_arns\s*=\s*\[(.*?)\]/s', $iamBlock, $matches);
        $this->assertNotEmpty($matches);
        $arns = $matches[1];

        foreach (['app_key_secret_arn', 'db_password_secret_arn', 'redis_auth_token_secret_arn', 'db_migrator_secret_arn'] as $expected) {
            $this->assertStringContainsString("var.{$expected}", $arns, "task_execution_secret_arns must include var.{$expected}.");
        }
        $this->assertDoesNotMatchRegularExpression('/"\*"/', $arns, 'No wildcard secret ARN may appear in task_execution_secret_arns.');
    }

    public function test_migrate_secret_wiring_gap_was_found_here_and_resolved_in_a_later_pass(): void
    {
        // Superseded 2026-08-06 (§9.23): the gap this test originally
        // proved was "documented and left unfixed" (module.migrate on
        // local.shared_secrets, no evidence yet for the migrator secret's
        // JSON key shape) is now resolved with cross-validated evidence —
        // see StagingMigrateSecretWiringTest.php for the full proof that
        // module.migrate now uses local.migrate_secrets correctly. This
        // test now only confirms §9.22's original finding is still
        // recorded as historical narrative (audit trail), and that §9.22
        // itself points forward to §9.23 for the resolution — it no
        // longer asserts the live .tf config is unfixed, since that
        // would now be false.
        $doc = $this->stateAdoptionPlan();
        $section = $this->extractSection($doc, '### 9\.22', '### 9\.23');

        $this->assertMatchesRegularExpression('/required configuration correction/i', $section);
        $this->assertMatchesRegularExpression('/db_migrator_secret_arn/', $section);
        $this->assertMatchesRegularExpression('/RESOLVED 2026-08-06.{0,20}§9\.23/is', $section);

        $staging = $this->stagingMain();
        $migrateBlock = $this->extractModuleBlock($staging, 'migrate');
        $this->assertMatchesRegularExpression('/secrets\s*=\s*local\.migrate_secrets/', $migrateBlock, 'module.migrate must now use its dedicated secrets map — see §9.23.');
    }

    public function test_db_username_is_a_shared_hardcoded_literal_not_migrate_specific(): void
    {
        $staging = $this->stagingMain();
        $this->assertMatchesRegularExpression('/DB_USERNAME\s*=\s*"firmsbase_app"/', $staging);

        // shared_environment must define exactly one hardcoded DB_USERNAME
        // literal ("firmsbase_app"), used by web/worker/critical_worker/
        // scheduler/maintenance. Since §9.23's correction, migrate carries
        // its own second DB_USERNAME entry inside local.migrate_secrets —
        // sourced from the dedicated database-migrator secret selector, not
        // a hardcoded literal — so the total across the file is now 2.
        $this->assertSame(2, preg_match_all('/DB_USERNAME\s*=/', $staging));

        // shared_environment is wrapped in merge({...}, local.canonical_hostname_environment)
        // (see commit c6220ee7) — isolate just the inner map literal (merge()'s
        // first argument), not its second argument, so this count stays scoped
        // to shared_environment's own body.
        preg_match('/shared_environment\s*=\s*merge\(\s*\{(.*?)\n    \},/s', $staging, $sharedMatches);
        $this->assertNotEmpty($sharedMatches, 'Could not isolate the shared_environment map body.');
        $this->assertSame(
            1,
            preg_match_all('/DB_USERNAME\s*=/', $sharedMatches[1]),
            'shared_environment must define DB_USERNAME exactly once, as the hardcoded "firmsbase_app" literal.'
        );

        preg_match('/migrate_secrets\s*=\s*\{(.*?)\n  \}/s', $staging, $migrateMatches);
        $this->assertNotEmpty($migrateMatches, 'Could not isolate the migrate_secrets map body.');
        $this->assertMatchesRegularExpression(
            '/DB_USERNAME\s*=\s*"\$\{var\.db_migrator_secret_arn\}:username::"/',
            $migrateMatches[1],
            'migrate_secrets must source DB_USERNAME from the dedicated migrator secret selector, not a hardcoded literal.'
        );
    }

    // ------------------------------------------------------------
    // Log-group design
    // ------------------------------------------------------------

    public function test_seven_workload_log_groups_are_declared_named_after_role_not_app(): void
    {
        $staging = $this->stagingMain();
        $block = $this->extractResourceBlock($staging, 'aws_cloudwatch_log_group', 'app');

        $this->assertMatchesRegularExpression('/for_each\s*=\s*toset\(local\.roles\)/', $block);
        $this->assertMatchesRegularExpression('/name\s*=\s*"\/ecs\/\$\{var\.name_prefix\}\/\$\{each\.value\}"/', $block);
        $this->assertMatchesRegularExpression('/retention_in_days\s*=\s*30/', $block);
        $this->assertMatchesRegularExpression('/kms_key_id\s*=\s*module\.kms\.key_arn/', $block);

        preg_match('/roles\s*=\s*\[([^\]]*)\]/', $staging, $matches);
        $this->assertNotEmpty($matches);
        $roles = $matches[1];
        foreach (['web', 'worker', 'critical-worker', 'scheduler', 'migrate', 'maintenance', 'ses-consumer'] as $role) {
            $this->assertStringContainsString("\"{$role}\"", $roles);
        }
    }

    public function test_documentation_states_historical_log_group_must_remain_available_for_rollback(): void
    {
        $doc = $this->stateAdoptionPlan();
        $section = $this->extractSection($doc, '### 9\.22', '## 10\.');

        $this->assertStringContainsString('/ecs/firmsbase-staging/app', $section);
        $this->assertMatchesRegularExpression('/must remain available[\s\S]{0,20}untouched[\s\S]{0,20}for rollback/i', $section);
    }

    // ------------------------------------------------------------
    // Canary selection
    // ------------------------------------------------------------

    public function test_documentation_selects_maintenance_not_migrate_or_web_as_the_first_canary(): void
    {
        $doc = $this->stateAdoptionPlan();
        $section = $this->extractSection($doc, '### 9\.22', '## 10\.');

        $this->assertMatchesRegularExpression('/\*\*`maintenance`\*\*, not\s*\n?`migrate` and not\s*\n?`web`/i', $section);
        $this->assertMatchesRegularExpression('/schema migrations/i', $section);
    }

    // ------------------------------------------------------------
    // No unauthorized configuration change
    // ------------------------------------------------------------

    public function test_no_new_ecs_service_or_task_definition_resource_address_was_added(): void
    {
        $staging = $this->stagingMain();

        // Exactly 7 ecs_service module calls, unchanged from before this audit.
        $this->assertSame(7, preg_match_all('/module\s+"[a-zA-Z0-9_]+"\s*\{[^}]*source\s*=\s*"[^"]*modules\/ecs_service"/s', $staging));
    }
}
