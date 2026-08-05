<?php

declare(strict_types=1);

namespace Tests\Feature\Ecs;

use Tests\TestCase;

/**
 * Proves the ECS service-level adoption alignment correction (see
 * docs/ecs/state-adoption-plan.md §9.20): deployment min/max healthy
 * percent and service tags are now explicit for all four existing live
 * services (web/worker/critical-worker/scheduler), the log-group
 * architecture difference remains accurately documented, service
 * import-readiness is recorded without the Group A/B/C-vs-readiness-rank
 * contradiction, and the shared task role's unreadable inline policy is
 * recorded without inferring its actions from its name. Reads the real,
 * committed files directly — never a live `terraform plan`/`apply`/
 * `import` (no AWS contact, no credentials needed, fully deterministic),
 * mirroring StagingPhaseA3AdoptionAlignmentTest's philosophy.
 */
class StagingEcsServiceAdoptionAlignmentTest extends TestCase
{
    private const SERVICE_ROLES = ['web', 'worker', 'critical_worker', 'scheduler'];

    private function ecsServiceModuleVariables(): string
    {
        return $this->readFile('infrastructure/ecs/modules/ecs_service/variables.tf');
    }

    private function ecsServiceModuleMain(): string
    {
        return $this->readFile('infrastructure/ecs/modules/ecs_service/main.tf');
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

    private function extractResourceBlock(string $content, string $type, string $name): string
    {
        preg_match('/resource "'.preg_quote($type, '/').'" "'.preg_quote($name, '/').'" \{.*?\n\}\n/s', $content, $matches);
        $this->assertNotEmpty($matches, "Could not locate resource \"{$type}\" \"{$name}\".");

        return $matches[0];
    }

    private function extractSection(string $doc, string $startPattern, string $endPattern): string
    {
        preg_match('/'.$startPattern.'.*?(?='.$endPattern.')/s', $doc, $matches);
        $this->assertNotEmpty($matches, "Could not locate section matching /{$startPattern}/.");

        return $matches[0];
    }

    // ------------------------------------------------------------
    // Deployment min/max healthy percent — module-level defaults
    // ------------------------------------------------------------

    public function test_deployment_percentage_module_variables_still_default_to_100_200(): void
    {
        $vars = $this->ecsServiceModuleVariables();

        $min = $this->extractVariableBlock($vars, 'deployment_minimum_healthy_percent');
        $this->assertMatchesRegularExpression('/default\s*=\s*100\b/', $min);

        $max = $this->extractVariableBlock($vars, 'deployment_maximum_percent');
        $this->assertMatchesRegularExpression('/default\s*=\s*200\b/', $max);
    }

    // ------------------------------------------------------------
    // Deployment min/max healthy percent — staging-root variables
    // ------------------------------------------------------------

    public function test_all_eight_deployment_percentage_root_variables_exist_and_default_to_100_200(): void
    {
        $vars = $this->stagingVariables();

        foreach (self::SERVICE_ROLES as $role) {
            $min = $this->extractVariableBlock($vars, "{$role}_deployment_minimum_healthy_percent");
            $this->assertMatchesRegularExpression('/type\s*=\s*number/', $min);
            $this->assertMatchesRegularExpression('/default\s*=\s*100\b/', $min, "{$role}_deployment_minimum_healthy_percent must preserve the 100 new-environment default.");

            $max = $this->extractVariableBlock($vars, "{$role}_deployment_maximum_percent");
            $this->assertMatchesRegularExpression('/type\s*=\s*number/', $max);
            $this->assertMatchesRegularExpression('/default\s*=\s*200\b/', $max, "{$role}_deployment_maximum_percent must preserve the 200 new-environment default.");
        }
    }

    public function test_deployment_percentage_root_variables_validate_bounds_and_ordering(): void
    {
        $vars = $this->stagingVariables();

        foreach (self::SERVICE_ROLES as $role) {
            $min = $this->extractVariableBlock($vars, "{$role}_deployment_minimum_healthy_percent");
            $this->assertMatchesRegularExpression('/>=\s*0/', $min);
            $this->assertMatchesRegularExpression('/<=\s*100\b/', $min);

            $max = $this->extractVariableBlock($vars, "{$role}_deployment_maximum_percent");
            $this->assertMatchesRegularExpression('/>=\s*100\b/', $max);
            $this->assertMatchesRegularExpression('/<=\s*200\b/', $max);
            $this->assertMatchesRegularExpression(
                '/'.preg_quote("{$role}_deployment_maximum_percent", '/').'\s*>=\s*var\.'.preg_quote("{$role}_deployment_minimum_healthy_percent", '/').'/',
                $max,
                "{$role}_deployment_maximum_percent must validate it is not lower than {$role}_deployment_minimum_healthy_percent."
            );
        }
    }

    public function test_all_four_module_calls_wire_deployment_percentages_from_root_variables(): void
    {
        $staging = $this->stagingMain();

        foreach (self::SERVICE_ROLES as $role) {
            $block = $this->extractModuleBlock($staging, $role);

            $this->assertMatchesRegularExpression(
                '/deployment_minimum_healthy_percent\s*=\s*var\.'.preg_quote("{$role}_deployment_minimum_healthy_percent", '/').'/',
                $block,
                "module \"{$role}\" must wire deployment_minimum_healthy_percent from var.{$role}_deployment_minimum_healthy_percent."
            );
            $this->assertMatchesRegularExpression(
                '/deployment_maximum_percent\s*=\s*var\.'.preg_quote("{$role}_deployment_maximum_percent", '/').'/',
                $block,
                "module \"{$role}\" must wire deployment_maximum_percent from var.{$role}_deployment_maximum_percent."
            );
        }
    }

    public function test_scheduler_no_longer_hardcodes_deployment_percentage_literals(): void
    {
        $block = $this->extractModuleBlock($this->stagingMain(), 'scheduler');

        $this->assertDoesNotMatchRegularExpression(
            '/deployment_minimum_healthy_percent\s*=\s*0\b/',
            $block,
            'scheduler must no longer hardcode a literal 0 for deployment_minimum_healthy_percent.'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/deployment_maximum_percent\s*=\s*100\b/',
            $block,
            'scheduler must no longer hardcode a literal 100 for deployment_maximum_percent.'
        );
    }

    public function test_example_tfvars_overrides_all_eight_deployment_percentages_to_live_adoption_values(): void
    {
        $tfvars = $this->stagingTfvarsExample();

        foreach (self::SERVICE_ROLES as $role) {
            $this->assertMatchesRegularExpression(
                '/'.preg_quote("{$role}_deployment_minimum_healthy_percent", '/').'\s*=\s*0\b/',
                $tfvars,
                "terraform.tfvars.example must set {$role}_deployment_minimum_healthy_percent to 0 (live adoption value)."
            );
            $this->assertMatchesRegularExpression(
                '/'.preg_quote("{$role}_deployment_maximum_percent", '/').'\s*=\s*100\b/',
                $tfvars,
                "terraform.tfvars.example must set {$role}_deployment_maximum_percent to 100 (live adoption value)."
            );
        }
    }

    public function test_example_tfvars_documents_adoption_only_not_a_production_cutover_approval(): void
    {
        $tfvars = $this->stagingTfvarsExample();
        $this->assertMatchesRegularExpression('/state adoption\s+ONLY|adoption\s+ONLY/i', $tfvars);
        $this->assertMatchesRegularExpression('/not[\s#]+approve/i', $tfvars);
    }

    // ------------------------------------------------------------
    // Service tags
    // ------------------------------------------------------------

    public function test_ecs_service_module_tags_input_is_unchanged_and_used(): void
    {
        $vars = $this->ecsServiceModuleVariables();
        $tagsVar = $this->extractVariableBlock($vars, 'tags');
        $this->assertMatchesRegularExpression('/type\s*=\s*map\(string\)/', $tagsVar);
        $this->assertMatchesRegularExpression('/default\s*=\s*\{\}/', $tagsVar);

        $service = $this->extractResourceBlock($this->ecsServiceModuleMain(), 'aws_ecs_service', 'this');
        $this->assertMatchesRegularExpression('/^\s*tags\s*=\s*var\.tags\s*$/m', $service, 'aws_ecs_service.this must use the module\'s existing tags input, not a new mechanism.');
    }

    public function test_all_four_tag_root_variables_exist_default_to_empty_map(): void
    {
        $vars = $this->stagingVariables();

        foreach (self::SERVICE_ROLES as $role) {
            $block = $this->extractVariableBlock($vars, "{$role}_tags");
            $this->assertMatchesRegularExpression('/type\s*=\s*map\(string\)/', $block);
            $this->assertMatchesRegularExpression('/default\s*=\s*\{\}/', $block);
        }
    }

    public function test_all_four_module_calls_wire_tags_from_root_variables(): void
    {
        $staging = $this->stagingMain();

        foreach (self::SERVICE_ROLES as $role) {
            $block = $this->extractModuleBlock($staging, $role);
            $this->assertMatchesRegularExpression(
                '/tags\s*=\s*var\.'.preg_quote("{$role}_tags", '/').'/',
                $block,
                "module \"{$role}\" must wire tags from var.{$role}_tags."
            );
        }
    }

    public function test_example_tfvars_sets_web_tags_to_the_exact_live_five_tag_map(): void
    {
        $tfvars = $this->stagingTfvarsExample();

        preg_match('/web_tags\s*=\s*\{.*?\n\}/s', $tfvars, $matches);
        $this->assertNotEmpty($matches, 'Could not locate web_tags block in terraform.tfvars.example.');
        $block = $matches[0];

        $this->assertMatchesRegularExpression('/SourceCommit\s*=\s*"6a1affdaad2bc1c4a48c5e411b9e39056039cde9"/', $block);
        $this->assertMatchesRegularExpression('/Environment\s*=\s*"staging"/', $block);
        $this->assertMatchesRegularExpression('/ManagedBy\s*=\s*"manual-reviewed-deployment"/', $block);
        $this->assertMatchesRegularExpression('/ImageDigest\s*=\s*"sha256:92eecbeeef5225fcfe0f4256a0b375a773cd84c1f4974fc08b137811a27e46fd"/', $block);
        $this->assertMatchesRegularExpression('/Application\s*=\s*"FirmsBase"/', $block);
    }

    public function test_web_stale_image_digest_tag_is_preserved_not_corrected(): void
    {
        // The live ImageDigest tag value is known-stale (does not match the
        // running task definition's actual image digest). This mission
        // must preserve it verbatim, not "correct" it during adoption.
        $tfvars = $this->stagingTfvarsExample();
        $this->assertStringContainsString('sha256:92eecbeeef5225fcfe0f4256a0b375a773cd84c1f4974fc08b137811a27e46fd', $tfvars);

        $doc = $this->stateAdoptionPlan();
        $section = $this->extractSection($doc, '### 9\.20', '## 10\.');
        $this->assertMatchesRegularExpression('/stale/i', $section);
        $this->assertMatchesRegularExpression('/preserved\s+verbatim|preserve[ds]?\s+exactly/i', $section);
        $this->assertMatchesRegularExpression('/separate.{0,40}(later|explicitly reviewed).{0,40}metadata change/is', $section);
    }

    public function test_worker_critical_worker_scheduler_tags_are_not_overridden_in_example_tfvars(): void
    {
        $tfvars = $this->stagingTfvarsExample();

        foreach (['worker', 'critical_worker', 'scheduler'] as $role) {
            $this->assertDoesNotMatchRegularExpression(
                '/'.preg_quote("{$role}_tags", '/').'\s*=\s*\{/',
                $tfvars,
                "{$role}_tags must not be overridden in terraform.tfvars.example — live carries no tags for this role, so the {} default already matches."
            );
        }
    }

    public function test_documentation_records_the_provider_default_tags_residual_mismatch(): void
    {
        $doc = $this->stateAdoptionPlan();
        $section = $this->extractSection($doc, '### 9\.20', '## 10\.');

        $this->assertStringContainsString('default_tags', $section);
        $this->assertMatchesRegularExpression('/Project|Mission/', $section);
        $this->assertMatchesRegularExpression('/not achievable|cannot be achieved|residual/i', $section);
    }

    // ------------------------------------------------------------
    // Log-group architecture — already accurate, must remain so
    // ------------------------------------------------------------

    public function test_terraform_declares_seven_per_workload_log_groups_distinct_from_lives_shared_one(): void
    {
        $staging = $this->stagingMain();
        $this->assertMatchesRegularExpression(
            '/resource "aws_cloudwatch_log_group" "app" \{\s*for_each\s*=\s*toset\(local\.roles\)/s',
            $staging
        );
        $this->assertMatchesRegularExpression(
            '/name\s*=\s*"\/ecs\/\$\{var\.name_prefix\}\/\$\{each\.value\}"/',
            $staging
        );
    }

    public function test_documentation_records_log_group_architecture_as_unresolved_migration_not_drift(): void
    {
        $doc = $this->stateAdoptionPlan();
        $this->assertStringContainsString('/ecs/firmsbase-staging/app', $doc);

        $section = $this->extractSection($doc, '### 9\.20', '## 10\.');
        $this->assertStringContainsString('/ecs/firmsbase-staging/app', $section);
        $this->assertMatchesRegularExpression('/seven workload-specific log groups|7\s+per-role log groups|seven.{0,20}log groups/i', $section);
        $this->assertMatchesRegularExpression('/no change required|already accurately documented|no log group was created/i', $section);
    }

    // ------------------------------------------------------------
    // lifecycle.ignore_changes protection unmodified
    // ------------------------------------------------------------

    public function test_lifecycle_ignore_changes_still_protects_task_definition(): void
    {
        $block = $this->extractResourceBlock($this->ecsServiceModuleMain(), 'aws_ecs_service', 'this');

        $this->assertMatchesRegularExpression(
            '/lifecycle\s*\{\s*ignore_changes\s*=\s*\[\s*task_definition/s',
            $block,
            'aws_ecs_service.this must still protect task_definition via lifecycle.ignore_changes.'
        );
    }

    // ------------------------------------------------------------
    // No task-definition/role/policy/log-group resource address changed
    // ------------------------------------------------------------

    public function test_no_task_definition_role_policy_or_log_group_resource_address_changed(): void
    {
        $moduleMain = $this->ecsServiceModuleMain();
        $this->assertMatchesRegularExpression('/resource "aws_ecs_task_definition" "this"/', $moduleMain);
        $this->assertMatchesRegularExpression('/resource "aws_ecs_service" "this"/', $moduleMain);

        $iamMain = $this->readFile('infrastructure/ecs/modules/iam/main.tf');
        $this->assertMatchesRegularExpression('/resource "aws_iam_role" "task" \{/', $iamMain);

        $staging = $this->stagingMain();
        $this->assertMatchesRegularExpression('/resource "aws_cloudwatch_log_group" "app" \{/', $staging);
    }

    // ------------------------------------------------------------
    // Service import-readiness: corrected, no Group A/B/C-vs-rank contradiction
    // ------------------------------------------------------------

    public function test_manifest_prerequisite_no_longer_cites_the_stale_assign_public_ip_hard_stop(): void
    {
        foreach (self::SERVICE_ROLES as $role) {
            $address = $role === 'web' || $role === 'worker'
                ? "module.{$role}.aws_ecs_service.this[0]"
                : "module.{$role}.aws_ecs_service.this[0]";
            $entry = $this->manifestEntry($address);

            $this->assertStringNotContainsString(
                "module's ecs_service module hardcodes assign_public_ip=false",
                $entry['prerequisite']
            );
            $this->assertMatchesRegularExpression('/STALE CLAIM REMOVED/', $entry['prerequisite']);
            $this->assertMatchesRegularExpression('/is not a blocker|not a blocker/i', $entry['prerequisite']);
        }
    }

    public function test_all_four_services_remain_group_c_unchanged_by_this_pass(): void
    {
        foreach (self::SERVICE_ROLES as $role) {
            $entry = $this->manifestEntry("module.{$role}.aws_ecs_service.this[0]");
            $this->assertStringContainsString('Group C', $entry['notes']);
            $this->assertSame('import_then_migrate', $entry['classification']);
        }
    }

    public function test_scheduler_manifest_entry_is_not_simultaneously_group_b_and_group_c_only(): void
    {
        $entry = $this->manifestEntry('module.scheduler.aws_ecs_service.this[0]');
        $combined = $entry['notes'].' '.$entry['prerequisite'];

        $this->assertStringNotContainsString('Group B', $combined);
        $this->assertMatchesRegularExpression('/1 of 4/', $entry['notes']);
    }

    public function test_readiness_rank_is_recorded_in_the_documented_order(): void
    {
        $ranks = [
            'scheduler' => '1 of 4',
            'worker' => '2 of 4',
            'critical_worker' => '3 of 4',
            'web' => '4 of 4',
        ];

        foreach ($ranks as $role => $rank) {
            $entry = $this->manifestEntry("module.{$role}.aws_ecs_service.this[0]");
            $this->assertStringContainsString($rank, $entry['notes'], "{$role}'s manifest notes must record readiness rank {$rank}.");
        }

        $doc = $this->stateAdoptionPlan();
        $section = $this->extractSection($doc, '### 9\.20', '## 10\.');
        $this->assertMatchesRegularExpression('/scheduler.{0,20}worker.{0,30}critical-worker.{0,20}web/is', $section);
    }

    public function test_readiness_rank_is_documented_as_a_separate_axis_from_group_classification(): void
    {
        $doc = $this->stateAdoptionPlan();
        $section = $this->extractSection($doc, '### 9\.20', '## 10\.');

        $this->assertMatchesRegularExpression('/separate axis|different axes/i', $section);
        $this->assertMatchesRegularExpression('/remain(s)?\s+Group C[\s\S]{0,60}unchanged by\s+this pass|regardless of rank/i', $section);
    }

    // ------------------------------------------------------------
    // Shared task-role IAM read blocker
    // ------------------------------------------------------------

    public function test_manifest_ses_send_policy_note_no_longer_asserts_unverified_actions(): void
    {
        $entry = $this->manifestEntry('module.iam.aws_iam_role_policy.task_web_ses_send[0]');
        $notes = $entry['notes'];

        $this->assertStringNotContainsString(
            'granting both ses:SendEmail and ses:SendRawEmail',
            $notes
        );
        $this->assertMatchesRegularExpression('/AccessDenied/', $notes);
        $this->assertMatchesRegularExpression('/never actually read|inference from the policy.s name|remain unread/i', $notes);
    }

    public function test_documentation_records_the_ses_policy_read_blocker_without_inferring_actions(): void
    {
        $doc = $this->stateAdoptionPlan();
        $section = $this->extractSection($doc, '### 9\.20', '## 10\.');

        $this->assertStringContainsString('FirmsVaultStagingSesSend', $section);
        $this->assertStringContainsString('firmsbase-staging-ecs-task-role', $section);
        $this->assertMatchesRegularExpression('/AccessDenied/', $section);
        $this->assertMatchesRegularExpression('/ListRolePolicies|list-role-policies/i', $section);
        $this->assertMatchesRegularExpression('/cannot be finalized|remains open|not.{0,20}granted/i', $section);
    }

    public function test_no_permission_was_granted_in_this_pass(): void
    {
        $doc = $this->stateAdoptionPlan();
        $section = $this->extractSection($doc, '### 9\.20', '## 10\.');
        $this->assertMatchesRegularExpression('/no permission was granted/i', $section);
    }
}
