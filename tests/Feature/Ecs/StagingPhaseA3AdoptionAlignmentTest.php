<?php

declare(strict_types=1);

namespace Tests\Feature\Ecs;

use Tests\TestCase;

/**
 * Proves the Phase A3 adoption-alignment correction (see
 * docs/ecs/state-adoption-plan.md §9.12/§9.13): ECS launch-mode and
 * desired-count modeling, cluster capacity-provider representability, and
 * the IAM inline-policy name correction, against the real, committed
 * files — never against a live `terraform plan`/`apply`/`import` (no AWS
 * contact, no credentials needed, fully deterministic), mirroring this
 * repo's AlbTargetGroupAdoptionTest/StagingPhaseA2RuleImportIdsTest
 * philosophy of reading real committed files directly.
 */
class StagingPhaseA3AdoptionAlignmentTest extends TestCase
{
    private const SERVICE_CALLERS = [
        'web', 'worker', 'critical_worker', 'scheduler', 'migrate', 'maintenance', 'ses_consumer',
    ];

    private const PHASE_A3_ADDRESSES = [
        'module.alb.aws_lb_target_group.web',
        'module.critical_worker.aws_ecs_service.this[0]',
        'module.ecr.aws_ecr_repository.app',
        'module.ecs_cluster.aws_ecs_cluster.this',
        'module.ecs_cluster.aws_ecs_cluster_capacity_providers.this',
        'module.elasticache.aws_elasticache_replication_group.this',
        'module.elasticache.aws_elasticache_subnet_group.this',
        'module.iam.aws_iam_role.task_execution',
        'module.iam.aws_iam_role_policy.task_execution',
        'module.scheduler.aws_ecs_service.this[0]',
        'module.web.aws_ecs_service.this[0]',
        'module.worker.aws_ecs_service.this[0]',
    ];

    private function ecsServiceModuleMain(): string
    {
        return $this->readFile('infrastructure/ecs/modules/ecs_service/main.tf');
    }

    private function ecsServiceModuleVariables(): string
    {
        return $this->readFile('infrastructure/ecs/modules/ecs_service/variables.tf');
    }

    private function ecsClusterModuleMain(): string
    {
        return $this->readFile('infrastructure/ecs/modules/ecs_cluster/main.tf');
    }

    private function ecsClusterModuleVariables(): string
    {
        return $this->readFile('infrastructure/ecs/modules/ecs_cluster/variables.tf');
    }

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

    // ------------------------------------------------------------
    // ECS launch mode: use_capacity_provider_strategy
    // ------------------------------------------------------------

    public function test_use_capacity_provider_strategy_variable_has_no_default(): void
    {
        $block = $this->extractVariableBlock($this->ecsServiceModuleVariables(), 'use_capacity_provider_strategy');

        $this->assertStringContainsString('type        = bool', $block);
        $this->assertDoesNotMatchRegularExpression('/default\s*=/', $block, 'use_capacity_provider_strategy must have no default — every caller must decide explicitly.');
    }

    public function test_ecs_service_resource_never_sets_both_launch_type_and_capacity_provider_strategy_unconditionally(): void
    {
        $block = $this->extractResourceBlock($this->ecsServiceModuleMain(), 'aws_ecs_service', 'this');

        $this->assertMatchesRegularExpression(
            '/launch_type\s*=\s*var\.use_capacity_provider_strategy\s*\?\s*null\s*:\s*"FARGATE"/',
            $block,
            'launch_type must be null when use_capacity_provider_strategy=true and "FARGATE" when false.'
        );

        $this->assertMatchesRegularExpression(
            '/dynamic "capacity_provider_strategy" \{\s*for_each\s*=\s*var\.use_capacity_provider_strategy \? \[1\] : \[\]/s',
            $block,
            'capacity_provider_strategy must be a dynamic block gated on the same boolean, omitted entirely when false.'
        );

        // The old unconditional (non-dynamic) block must be gone.
        $this->assertDoesNotMatchRegularExpression(
            '/^\s*capacity_provider_strategy \{\s*\n\s*capacity_provider = var\.capacity_provider/m',
            $block,
            'The old unconditional capacity_provider_strategy block must be replaced by the dynamic, gated version.'
        );
    }

    public function test_every_service_caller_supplies_use_capacity_provider_strategy_false(): void
    {
        $staging = $this->stagingMain();

        foreach (self::SERVICE_CALLERS as $caller) {
            $block = $this->extractModuleBlock($staging, $caller);

            $this->assertMatchesRegularExpression(
                '/use_capacity_provider_strategy\s*=\s*false/',
                $block,
                "module \"{$caller}\" must supply use_capacity_provider_strategy = false, matching live launchType=FARGATE."
            );
        }
    }

    public function test_no_repository_caller_of_ecs_service_omits_use_capacity_provider_strategy(): void
    {
        $stagingDir = base_path('infrastructure/ecs');
        $callSites = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($stagingDir));
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'tf') {
                continue;
            }
            $content = file_get_contents($file->getPathname());
            if (preg_match_all('/module\s+"[a-zA-Z0-9_]+"\s*\{[^}]*source\s*=\s*"[^"]*modules\/ecs_service"/s', $content, $m)) {
                foreach ($m[0] as $block) {
                    $callSites[] = [$file->getPathname(), $block];
                }
            }
        }

        $this->assertSame(7, count($callSites), 'Expected exactly 7 ecs_service module call sites in infrastructure/ecs.');
    }

    // ------------------------------------------------------------
    // ECS desired counts
    // ------------------------------------------------------------

    public function test_desired_count_variables_preserve_original_defaults(): void
    {
        $vars = $this->stagingVariables();

        $web = $this->extractVariableBlock($vars, 'web_desired_count');
        $this->assertMatchesRegularExpression('/default\s*=\s*2/', $web);

        $worker = $this->extractVariableBlock($vars, 'worker_desired_count');
        $this->assertMatchesRegularExpression('/default\s*=\s*2/', $worker);

        $criticalWorker = $this->extractVariableBlock($vars, 'critical_worker_desired_count');
        $this->assertMatchesRegularExpression('/default\s*=\s*1/', $criticalWorker);

        $scheduler = $this->extractVariableBlock($vars, 'scheduler_desired_count');
        $this->assertMatchesRegularExpression('/default\s*=\s*1/', $scheduler);
    }

    public function test_desired_count_variables_are_wired_into_the_module_calls(): void
    {
        $staging = $this->stagingMain();

        $this->assertMatchesRegularExpression('/desired_count\s*=\s*var\.web_desired_count/', $this->extractModuleBlock($staging, 'web'));
        $this->assertMatchesRegularExpression('/desired_count\s*=\s*var\.worker_desired_count/', $this->extractModuleBlock($staging, 'worker'));
        $this->assertMatchesRegularExpression('/desired_count\s*=\s*var\.critical_worker_desired_count/', $this->extractModuleBlock($staging, 'critical_worker'));
        $this->assertMatchesRegularExpression('/desired_count\s*=\s*var\.scheduler_desired_count/', $this->extractModuleBlock($staging, 'scheduler'));
    }

    public function test_example_tfvars_sets_all_four_desired_counts_to_the_live_adoption_value_of_one(): void
    {
        $tfvars = $this->stagingTfvarsExample();

        $this->assertMatchesRegularExpression('/web_desired_count\s*=\s*1\b/', $tfvars);
        $this->assertMatchesRegularExpression('/worker_desired_count\s*=\s*1\b/', $tfvars);
        $this->assertMatchesRegularExpression('/critical_worker_desired_count\s*=\s*1\b/', $tfvars);
        $this->assertMatchesRegularExpression('/scheduler_desired_count\s*=\s*1\b/', $tfvars);
    }

    // ------------------------------------------------------------
    // Cluster capacity-provider representability
    // ------------------------------------------------------------

    public function test_ecs_cluster_module_exposes_config_known_capacity_provider_variables(): void
    {
        $vars = $this->ecsClusterModuleVariables();

        $capacityProviders = $this->extractVariableBlock($vars, 'capacity_providers');
        $this->assertMatchesRegularExpression('/default\s*=\s*\["FARGATE",\s*"FARGATE_SPOT"\]/', $capacityProviders);

        $defaultProvider = $this->extractVariableBlock($vars, 'default_capacity_provider');
        $this->assertMatchesRegularExpression('/default\s*=\s*"FARGATE"/', $defaultProvider);
    }

    public function test_default_capacity_provider_strategy_block_is_dynamic_and_gated_on_non_empty_list(): void
    {
        $block = $this->extractResourceBlock($this->ecsClusterModuleMain(), 'aws_ecs_cluster_capacity_providers', 'this');

        $this->assertMatchesRegularExpression(
            '/capacity_providers\s*=\s*var\.capacity_providers/',
            $block
        );

        $this->assertMatchesRegularExpression(
            '/dynamic "default_capacity_provider_strategy" \{\s*for_each\s*=\s*length\(var\.capacity_providers\)\s*>\s*0/s',
            $block,
            'default_capacity_provider_strategy must be a dynamic block, omitted when capacity_providers is empty.'
        );
    }

    public function test_staging_wires_ecs_capacity_provider_variables_and_sets_empty_for_adoption(): void
    {
        $block = $this->extractModuleBlock($this->stagingMain(), 'ecs_cluster');

        $this->assertMatchesRegularExpression('/capacity_providers\s*=\s*var\.ecs_capacity_providers/', $block);
        $this->assertMatchesRegularExpression('/default_capacity_provider\s*=\s*var\.ecs_default_capacity_provider/', $block);

        $tfvars = $this->stagingTfvarsExample();
        $this->assertMatchesRegularExpression('/ecs_capacity_providers\s*=\s*\[\]/', $tfvars);
    }

    public function test_ecs_capacity_providers_staging_variable_preserves_original_default(): void
    {
        $block = $this->extractVariableBlock($this->stagingVariables(), 'ecs_capacity_providers');
        $this->assertMatchesRegularExpression('/default\s*=\s*\["FARGATE",\s*"FARGATE_SPOT"\]/', $block);
    }

    public function test_resource_address_for_capacity_providers_is_unchanged(): void
    {
        $this->assertMatchesRegularExpression(
            '/resource "aws_ecs_cluster_capacity_providers" "this"/',
            $this->ecsClusterModuleMain(),
            'The resource address module.ecs_cluster.aws_ecs_cluster_capacity_providers.this must not change.'
        );
    }

    // ------------------------------------------------------------
    // IAM inline-policy name
    // ------------------------------------------------------------

    public function test_task_execution_policy_name_variable_has_no_default(): void
    {
        $block = $this->extractVariableBlock($this->iamModuleVariables(), 'task_execution_policy_name');

        $this->assertStringContainsString('type        = string', $block);
        $this->assertDoesNotMatchRegularExpression('/default\s*=/', $block, 'task_execution_policy_name must have no default.');
    }

    public function test_iam_role_policy_resource_uses_the_variable_not_a_hardcoded_name(): void
    {
        $block = $this->extractResourceBlock($this->iamModuleMain(), 'aws_iam_role_policy', 'task_execution');

        $this->assertMatchesRegularExpression('/name\s*=\s*var\.task_execution_policy_name/', $block);
        $this->assertDoesNotMatchRegularExpression('/name\s*=\s*"\$\{var\.name_prefix\}-task-execution"/', $block);
    }

    public function test_staging_wires_the_live_adoption_policy_name(): void
    {
        $block = $this->extractModuleBlock($this->stagingMain(), 'iam');
        $this->assertMatchesRegularExpression('/task_execution_policy_name\s*=\s*var\.iam_task_execution_policy_name/', $block);

        $rootVar = $this->extractVariableBlock($this->stagingVariables(), 'iam_task_execution_policy_name');
        $this->assertDoesNotMatchRegularExpression('/default\s*=/', $rootVar, 'iam_task_execution_policy_name must have no default at the staging root either.');

        $tfvars = $this->stagingTfvarsExample();
        $this->assertMatchesRegularExpression('/iam_task_execution_policy_name\s*=\s*"FirmsBaseStagingSecretsAccess"/', $tfvars);
    }

    public function test_resource_address_for_task_execution_policy_is_unchanged(): void
    {
        $this->assertMatchesRegularExpression(
            '/resource "aws_iam_role_policy" "task_execution"/',
            $this->iamModuleMain(),
            'The resource address module.iam.aws_iam_role_policy.task_execution must not change.'
        );
    }

    // ------------------------------------------------------------
    // import-manifest.json: 12 addresses documented, totals unchanged
    // ------------------------------------------------------------

    public function test_all_twelve_phase_a3_addresses_are_documented_with_a_readiness_group(): void
    {
        foreach (self::PHASE_A3_ADDRESSES as $address) {
            $entry = $this->manifestEntry($address);
            $combined = $entry['notes'].' '.$entry['prerequisite'];

            $this->assertMatchesRegularExpression(
                '/Group [ABC]/',
                $combined,
                "{$address}'s manifest entry must record its Group A/B/C readiness classification."
            );
        }
    }

    public function test_ecr_is_documented_as_the_group_a_canary(): void
    {
        $entry = $this->manifestEntry('module.ecr.aws_ecr_repository.app');
        $combined = $entry['notes'].' '.$entry['prerequisite'];

        $this->assertStringContainsString('Group A', $combined);
        $this->assertStringContainsString('canary', strtolower($combined));
    }

    public function test_ecs_services_are_documented_as_group_c_not_safe_merely_because_import_is_safe(): void
    {
        foreach (['module.web.aws_ecs_service.this[0]', 'module.worker.aws_ecs_service.this[0]', 'module.critical_worker.aws_ecs_service.this[0]', 'module.scheduler.aws_ecs_service.this[0]'] as $address) {
            $entry = $this->manifestEntry($address);

            $this->assertStringContainsString('Group C', $entry['notes']);
        }
    }

    public function test_capacity_providers_resource_documents_the_corrected_stale_claim(): void
    {
        $entry = $this->manifestEntry('module.ecs_cluster.aws_ecs_cluster_capacity_providers.this');

        $this->assertStringContainsString('WRONG', $entry['prerequisite']);
        $this->assertStringContainsString('Group C', $entry['notes']);
    }

    public function test_manifest_totals_unchanged_since_no_classification_genuinely_changed(): void
    {
        $manifest = $this->importManifest();
        $summary = $manifest['summary'];

        $this->assertSame(66, $summary['new']);
        $this->assertSame(6, $summary['import_unchanged']);
        $this->assertSame(16, $summary['import_then_migrate']);
        $this->assertSame(6, $summary['do_not_import']);
        $this->assertSame(94, $summary['total']);

        foreach (self::PHASE_A3_ADDRESSES as $address) {
            $entry = $this->manifestEntry($address);
            $this->assertSame('import_then_migrate', $entry['classification'], "{$address}'s classification must not change in this correction.");
        }
    }

    public function test_manifest_no_credential_or_secret_value_is_present(): void
    {
        $raw = file_get_contents(base_path('infrastructure/ecs/environments/staging/import-manifest.json'));
        $this->assertNotFalse($raw);

        $this->assertDoesNotMatchRegularExpression('/AKIA[0-9A-Z]{16}/', $raw);
        $this->assertStringNotContainsString('-----BEGIN', $raw);
        $this->assertStringNotContainsString('REDIS_PASSWORD', $raw);
    }

    // ------------------------------------------------------------
    // Documentation: the 12 required points
    // ------------------------------------------------------------

    public function test_documentation_states_phase_a2_is_complete_with_ten_managed_resources(): void
    {
        $doc = $this->stateAdoptionPlan();
        $this->assertMatchesRegularExpression('/Phase A2 is complete/i', $doc);
        $this->assertMatchesRegularExpression('/10 managed resources/', $doc);
    }

    public function test_documentation_states_twelve_import_then_migrate_resources_remain(): void
    {
        $doc = $this->stateAdoptionPlan();
        $this->assertMatchesRegularExpression('/12\s+addresses|remaining\s+12|all\s+12/i', $doc);
    }

    public function test_documentation_recommends_ecr_as_the_canary(): void
    {
        $doc = $this->stateAdoptionPlan();
        preg_match('/### 9\.12.*?(?=### 9\.13)/s', $doc, $matches);
        $this->assertNotEmpty($matches, 'Could not locate §9.12.');

        $this->assertStringContainsString('module.ecr.aws_ecr_repository.app', $matches[0]);
        $this->assertMatchesRegularExpression('/Group A.{0,80}canary/is', $matches[0]);
    }

    public function test_documentation_states_live_cluster_capacity_provider_association_is_empty(): void
    {
        $doc = $this->stateAdoptionPlan();
        $this->assertMatchesRegularExpression('/capacityProviders:\s*\[\]/', $doc);
    }

    public function test_documentation_states_services_use_launch_type_fargate_not_strategy(): void
    {
        $doc = $this->stateAdoptionPlan();
        $this->assertMatchesRegularExpression('/launchType=FARGATE/', $doc);
        $this->assertMatchesRegularExpression('/capacityProviderStrategy=null/', $doc);
    }

    public function test_documentation_states_live_desired_counts_are_all_one(): void
    {
        $doc = $this->variableInventory();
        $this->assertMatchesRegularExpression('/live desired counts are all `?1`?/i', $doc);
    }

    public function test_documentation_states_previous_web_worker_desired_counts_were_two(): void
    {
        $doc = $this->stateAdoptionPlan();
        preg_match('/### 9\.12.*?(?=### 9\.13)/s', $doc, $matches);
        $this->assertNotEmpty($matches);
        $this->assertMatchesRegularExpression('/Terraform\s+previously\s+declared\s+`?web`?\/`?worker`?\s+at\s+`?2`?/', $matches[0]);
    }

    public function test_documentation_states_iam_inline_policy_live_name(): void
    {
        $doc = $this->stateAdoptionPlan();
        $this->assertStringContainsString('FirmsBaseStagingSecretsAccess', $doc);
    }

    public function test_documentation_states_policy_content_migration_remains_separate(): void
    {
        $doc = $this->stateAdoptionPlan();
        preg_match('/### 9\.12.*?(?=### 9\.13)/s', $doc, $matches);
        $this->assertNotEmpty($matches);
        $this->assertMatchesRegularExpression('/separate.{0,40}undecided migration|policy.{0,20}content.{0,40}separate/is', $matches[0]);
    }

    public function test_documentation_states_elasticache_tag_read_permission_unresolved(): void
    {
        $doc = $this->stateAdoptionPlan();
        preg_match('/### 9\.13.*?(?=## 10\.)/s', $doc, $matches);
        $this->assertNotEmpty($matches, 'Could not locate §9.13.');
        $section = $matches[0];

        $this->assertStringContainsString('elasticache:ListTagsForResource', $section);
        $this->assertStringContainsString('AccessDenied', $section);
        $this->assertStringContainsString('arn:aws:elasticache:us-east-1:603013471426:replicationgroup:firmsbase-staging-redis', $section);
        $this->assertStringContainsString('arn:aws:elasticache:us-east-1:603013471426:subnetgroup:firmsbase-staging-cache-subnets', $section);
        $this->assertMatchesRegularExpression('/[Nn]ot granted in this mission/', $section);
    }

    public function test_documentation_does_not_overclaim_iam_list_role_tags_as_required(): void
    {
        $doc = $this->stateAdoptionPlan();
        preg_match('/### 9\.13.*?(?=## 10\.)/s', $doc, $matches);
        $this->assertNotEmpty($matches);
        $section = $matches[0];

        $this->assertStringContainsString('iam:ListRoleTags', $section);
        $this->assertMatchesRegularExpression('/not.{0,20}claimed as a required|is \*not\* claimed/is', $section);
    }

    public function test_documentation_approves_no_deployment_scaling_or_migration(): void
    {
        $doc = $this->stateAdoptionPlan();
        preg_match('/### 9\.12.*?(?=### 9\.13)/s', $doc, $matches);
        $this->assertNotEmpty($matches);

        $this->assertMatchesRegularExpression(
            '/authorizes\s+no\s+import,\s+apply,\s+ECS\s+deployment,\s+scaling\s+change,\s+capacity-provider\s+association,\s+IAM\s+permission\s+migration,\s+or\s+description-drift\s+reconciliation/',
            $matches[0]
        );
    }
}
