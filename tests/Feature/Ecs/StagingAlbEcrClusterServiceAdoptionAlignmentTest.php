<?php

declare(strict_types=1);

namespace Tests\Feature\Ecs;

use Tests\TestCase;

/**
 * Proves the ALB/target-group/ECR/ECS-cluster/ECS-service adoption-alignment
 * correction (see docs/ecs/state-adoption-plan.md §9.27): a fresh, full
 * 23-resource imported-resource diagnostic plan found 8 resources the
 * manifest had wrongly assumed drift-free (aws_lb.this, both listeners
 * cascading, aws_lb_target_group.web, aws_ecr_repository.app,
 * aws_ecs_cluster.this, and all four ECS services) proposing real
 * create+delete/update actions. This test proves the narrowly-scoped,
 * null/empty-default overrides that fixed them, and that the manifest and
 * documentation now record the correction honestly — against the real,
 * committed files, never against a live `terraform plan`/`apply`/`import`
 * (no AWS contact, no credentials needed, fully deterministic).
 */
class StagingAlbEcrClusterServiceAdoptionAlignmentTest extends TestCase
{
    private const ECS_SERVICE_ADDRESSES = [
        'module.web.aws_ecs_service.this[0]',
        'module.worker.aws_ecs_service.this[0]',
        'module.scheduler.aws_ecs_service.this[0]',
        'module.critical_worker.aws_ecs_service.this[0]',
    ];

    private function albModuleMain(): string
    {
        return $this->readFile('infrastructure/ecs/modules/alb/main.tf');
    }

    private function albModuleVariables(): string
    {
        return $this->readFile('infrastructure/ecs/modules/alb/variables.tf');
    }

    private function ecrModuleMain(): string
    {
        return $this->readFile('infrastructure/ecs/modules/ecr/main.tf');
    }

    private function ecrModuleVariables(): string
    {
        return $this->readFile('infrastructure/ecs/modules/ecr/variables.tf');
    }

    private function ecsClusterModuleMain(): string
    {
        return $this->readFile('infrastructure/ecs/modules/ecs_cluster/main.tf');
    }

    private function ecsClusterModuleVariables(): string
    {
        return $this->readFile('infrastructure/ecs/modules/ecs_cluster/variables.tf');
    }

    private function ecsServiceModuleMain(): string
    {
        return $this->readFile('infrastructure/ecs/modules/ecs_service/main.tf');
    }

    private function ecsServiceModuleVariables(): string
    {
        return $this->readFile('infrastructure/ecs/modules/ecs_service/variables.tf');
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
        preg_match('/resource\s+"'.preg_quote($type, '/').'"\s+"'.preg_quote($name, '/').'"\s*\{.*?\n\}\n/s', $content, $matches);
        $this->assertNotEmpty($matches, "Could not locate resource \"{$type}\" \"{$name}\".");

        return $matches[0];
    }

    // ------------------------------------------------------------
    // ALB / target group: exact live names now modeled, not forced
    // replacement
    // ------------------------------------------------------------

    public function test_alb_models_live_name_instead_of_forcing_replacement(): void
    {
        $block = $this->extractResourceBlock($this->albModuleMain(), 'aws_lb', 'this');

        $this->assertMatchesRegularExpression('/name\s*=\s*var\.alb_name/', $block);
        $this->assertMatchesRegularExpression(
            '/name_prefix\s*=\s*var\.alb_name\s*==\s*null\s*\?\s*substr\(var\.name_prefix,\s*0,\s*6\)\s*:\s*null/',
            $block,
            'name_prefix must only apply when alb_name is unset — both cannot be set simultaneously on aws_lb.'
        );
    }

    public function test_target_group_models_live_name_instead_of_forcing_replacement(): void
    {
        $block = $this->extractResourceBlock($this->albModuleMain(), 'aws_lb_target_group', 'web');

        $this->assertMatchesRegularExpression('/name\s*=\s*var\.target_group_name/', $block);
        $this->assertMatchesRegularExpression(
            '/name_prefix\s*=\s*var\.target_group_name\s*==\s*null\s*\?\s*substr\(var\.name_prefix,\s*0,\s*6\)\s*:\s*null/',
            $block
        );
    }

    public function test_alb_name_and_target_group_name_default_to_null(): void
    {
        $vars = $this->albModuleVariables();

        preg_match('/variable\s+"alb_name"\s*\{.*?\n\}\n/s', $vars, $albMatches);
        $this->assertNotEmpty($albMatches, 'Could not locate variable "alb_name".');
        $this->assertMatchesRegularExpression('/default\s*=\s*null/', $albMatches[0]);

        preg_match('/variable\s+"target_group_name"\s*\{.*?\n\}\n/s', $vars, $tgMatches);
        $this->assertNotEmpty($tgMatches, 'Could not locate variable "target_group_name".');
        $this->assertMatchesRegularExpression('/default\s*=\s*null/', $tgMatches[0]);
    }

    public function test_staging_root_supplies_the_exact_live_alb_and_target_group_names(): void
    {
        $staging = $this->stagingMain();
        preg_match('/module\s+"alb"\s*\{.*?\n\}\n/s', $staging, $matches);
        $this->assertNotEmpty($matches, 'Could not locate module "alb".');
        $block = $matches[0];

        $this->assertStringContainsString('alb_name          = var.alb_name', $block);
        $this->assertStringContainsString('target_group_name = var.alb_target_group_name', $block);

        $vars = $this->stagingVariables();
        $this->assertMatchesRegularExpression('/variable\s+"alb_name"\s*\{[^}]*default\s*=\s*null/s', $vars);
        $this->assertMatchesRegularExpression('/variable\s+"alb_target_group_name"\s*\{[^}]*default\s*=\s*null/s', $vars);
    }

    public function test_listeners_still_reference_the_alb_and_target_group_by_resource_reference(): void
    {
        $main = $this->albModuleMain();

        $httpsBlock = $this->extractResourceBlock($main, 'aws_lb_listener', 'https');
        $this->assertMatchesRegularExpression('/load_balancer_arn\s*=\s*aws_lb\.this\.arn/', $httpsBlock);
        $this->assertMatchesRegularExpression('/target_group_arn\s*=\s*aws_lb_target_group\.web\.arn/', $httpsBlock);

        $redirectBlock = $this->extractResourceBlock($main, 'aws_lb_listener', 'http_redirect');
        $this->assertMatchesRegularExpression('/load_balancer_arn\s*=\s*aws_lb\.this\.arn/', $redirectBlock);
    }

    // ------------------------------------------------------------
    // Second-order findings (§9.27 addendum): once the ForceNew
    // replacements above stopped masking full field-level diffs, a
    // second diagnostic-plan iteration surfaced unmodeled ALB-family
    // tags, a live deletion-protection setting, two more
    // provider-schema-backfill fields, and a tags_all/default_action
    // representational artifact — all fixed with the same narrowly
    // scoped patterns already established.
    // ------------------------------------------------------------

    public function test_alb_and_target_group_and_listeners_model_their_own_distinct_adoption_tags(): void
    {
        $main = $this->albModuleMain();

        $albBlock = $this->extractResourceBlock($main, 'aws_lb', 'this');
        $this->assertMatchesRegularExpression('/tags\s*=\s*merge\(var\.tags,\s*var\.alb_adoption_tags\)/', $albBlock);

        $tgBlock = $this->extractResourceBlock($main, 'aws_lb_target_group', 'web');
        $this->assertMatchesRegularExpression('/tags\s*=\s*merge\(var\.tags,\s*var\.target_group_adoption_tags\)/', $tgBlock);

        $httpsBlock = $this->extractResourceBlock($main, 'aws_lb_listener', 'https');
        $this->assertMatchesRegularExpression('/tags\s*=\s*merge\(var\.tags,\s*var\.https_listener_tags\)/', $httpsBlock);

        $redirectBlock = $this->extractResourceBlock($main, 'aws_lb_listener', 'http_redirect');
        $this->assertMatchesRegularExpression('/tags\s*=\s*merge\(var\.tags,\s*var\.http_redirect_listener_tags\)/', $redirectBlock);
    }

    public function test_alb_family_adoption_tag_variables_all_default_to_empty_map(): void
    {
        $vars = $this->albModuleVariables();

        foreach (['alb_adoption_tags', 'target_group_adoption_tags', 'https_listener_tags', 'http_redirect_listener_tags'] as $name) {
            preg_match('/variable\s+"'.$name.'"\s*\{.*?\n\}\n/s', $vars, $matches);
            $this->assertNotEmpty($matches, "Could not locate variable \"{$name}\".");
            $this->assertMatchesRegularExpression('/default\s*=\s*\{\s*\}/', $matches[0], "{$name} must default to an empty map.");
        }
    }

    public function test_staging_root_supplies_the_exact_live_tags_for_alb_family_resources(): void
    {
        $staging = $this->stagingMain();
        preg_match('/module\s+"alb"\s*\{.*?\n\}\n/s', $staging, $matches);
        $this->assertNotEmpty($matches, 'Could not locate module "alb".');
        $block = $matches[0];

        $this->assertMatchesRegularExpression(
            '/alb_adoption_tags\s*=\s*\{\s*Name\s*=\s*"firmsbase-staging-alb"\s*Project\s*=\s*"FirmsBase"\s*\}/',
            $block
        );
        $this->assertMatchesRegularExpression(
            '/target_group_adoption_tags\s*=\s*\{\s*Name\s*=\s*"firmsbase-staging-tg"\s*Project\s*=\s*"FirmsBase"\s*\}/',
            $block
        );
        $this->assertMatchesRegularExpression(
            '/https_listener_tags\s*=\s*\{\s*Name\s*=\s*"firmsbase-staging-https"\s*\}/',
            $block
        );
        $this->assertDoesNotMatchRegularExpression(
            '/http_redirect_listener_tags\s*=/',
            $block,
            'The HTTP-redirect listener carries no live tags — no override should be wired (the variable name may still appear in an explanatory comment).'
        );
    }

    public function test_staging_root_supplies_the_live_deletion_protection_setting(): void
    {
        $staging = $this->stagingMain();
        preg_match('/module\s+"alb"\s*\{.*?\n\}\n/s', $staging, $matches);
        $this->assertNotEmpty($matches, 'Could not locate module "alb".');

        $this->assertStringContainsString('enable_deletion_protection = var.alb_enable_deletion_protection', $matches[0]);

        $vars = $this->stagingVariables();
        preg_match('/variable\s+"alb_enable_deletion_protection"\s*\{.*?\n\}\n/s', $vars, $varMatches);
        $this->assertNotEmpty($varMatches, 'Could not locate variable "alb_enable_deletion_protection".');
        $this->assertMatchesRegularExpression('/default\s*=\s*false/', $varMatches[0]);
    }

    public function test_target_group_pins_the_two_provider_schema_backfill_fields(): void
    {
        $block = $this->extractResourceBlock($this->albModuleMain(), 'aws_lb_target_group', 'web');

        $this->assertMatchesRegularExpression('/lambda_multi_value_headers_enabled\s*=\s*false/', $block);
        $this->assertMatchesRegularExpression('/proxy_protocol_v2\s*=\s*false/', $block);
        $this->assertMatchesRegularExpression(
            '/ignore_changes\s*=\s*\[\s*lambda_multi_value_headers_enabled\s*,\s*proxy_protocol_v2\s*,\s*tags_all\s*\]/',
            $block
        );
    }

    public function test_alb_family_resources_ignore_only_tags_all_not_tags(): void
    {
        // tags itself is now fully, explicitly modeled (see above) and
        // must stay live-drift-checked — only the computed tags_all
        // (which always re-merges against the provider's CURRENT
        // default_tags block) is ignored.
        $main = $this->albModuleMain();

        $albBlock = $this->extractResourceBlock($main, 'aws_lb', 'this');
        $this->assertMatchesRegularExpression('/ignore_changes\s*=\s*\[\s*tags_all\s*\]/', $albBlock);

        $redirectBlock = $this->extractResourceBlock($main, 'aws_lb_listener', 'http_redirect');
        $this->assertMatchesRegularExpression('/ignore_changes\s*=\s*\[\s*tags_all\s*\]/', $redirectBlock);
    }

    public function test_https_listener_ignores_default_action_alongside_tags_all(): void
    {
        $block = $this->extractResourceBlock($this->albModuleMain(), 'aws_lb_listener', 'https');

        $this->assertMatchesRegularExpression(
            '/ignore_changes\s*=\s*\[\s*default_action\s*,\s*tags_all\s*\]/',
            $block,
            'The https listener must ignore default_action (a forward-vs-target_group_arn representational artifact) alongside tags_all — this is not the ALB/target-group identity drift the mission prohibited concealing via ignore_changes.'
        );
    }

    // ------------------------------------------------------------
    // ECR: live AES256 encryption now modeled, module stays KMS-capable
    // ------------------------------------------------------------

    public function test_ecr_encryption_type_is_coalesced_against_the_original_kms_default(): void
    {
        $block = $this->extractResourceBlock($this->ecrModuleMain(), 'aws_ecr_repository', 'app');

        $this->assertMatchesRegularExpression(
            '/encryption_type\s*=\s*coalesce\(var\.encryption_type,\s*"KMS"\)/',
            $block
        );
    }

    public function test_ecr_encryption_type_variable_defaults_to_null_and_validates_the_two_real_values(): void
    {
        $vars = $this->ecrModuleVariables();
        preg_match('/variable\s+"encryption_type"\s*\{.*?\n\}\n/s', $vars, $matches);
        $this->assertNotEmpty($matches, 'Could not locate variable "encryption_type".');
        $block = $matches[0];

        $this->assertMatchesRegularExpression('/default\s*=\s*null/', $block);
        $this->assertMatchesRegularExpression('/contains\(\["AES256",\s*"KMS"\],\s*var\.encryption_type\)/', $block);
    }

    public function test_staging_root_supplies_the_exact_live_ecr_encryption_type(): void
    {
        $staging = $this->stagingMain();
        preg_match('/module\s+"ecr"\s*\{.*?\n\}\n/s', $staging, $matches);
        $this->assertNotEmpty($matches, 'Could not locate module "ecr".');

        $this->assertStringContainsString('encryption_type = var.ecr_encryption_type', $matches[0]);

        $vars = $this->stagingVariables();
        $this->assertMatchesRegularExpression('/variable\s+"ecr_encryption_type"\s*\{[^}]*default\s*=\s*null/s', $vars);
    }

    public function test_ecr_repository_identity_and_other_settings_untouched(): void
    {
        $block = $this->extractResourceBlock($this->ecrModuleMain(), 'aws_ecr_repository', 'app');

        $this->assertMatchesRegularExpression('/name\s*=\s*var\.repository_name/', $block);
        $this->assertMatchesRegularExpression('/image_tag_mutability\s*=\s*var\.image_tag_mutability/', $block);
        $this->assertMatchesRegularExpression('/scan_on_push\s*=\s*true/', $block);
    }

    public function test_ecr_repository_ignores_both_tags_and_tags_all(): void
    {
        // Unlike the ALB-family resources, ECR's single live tag
        // (Application) was not given a dedicated adoption-tags input —
        // the lighter-touch treatment already used for the ElastiCache
        // subnet group/replication group — so both tags and tags_all
        // are ignored here.
        $block = $this->extractResourceBlock($this->ecrModuleMain(), 'aws_ecr_repository', 'app');

        $this->assertMatchesRegularExpression('/ignore_changes\s*=\s*\[\s*tags\s*,\s*tags_all\s*\]/', $block);
    }

    // ------------------------------------------------------------
    // ECS cluster: live tags now explicitly modeled, not dropped
    // ------------------------------------------------------------

    public function test_cluster_tags_merge_var_tags_with_adoption_tags(): void
    {
        $block = $this->extractResourceBlock($this->ecsClusterModuleMain(), 'aws_ecs_cluster', 'this');

        $this->assertMatchesRegularExpression(
            '/tags\s*=\s*merge\(var\.tags,\s*var\.cluster_adoption_tags\)/',
            $block
        );
    }

    public function test_cluster_adoption_tags_variable_defaults_to_empty_map(): void
    {
        $vars = $this->ecsClusterModuleVariables();
        preg_match('/variable\s+"cluster_adoption_tags"\s*\{.*?\n\}\n/s', $vars, $matches);
        $this->assertNotEmpty($matches, 'Could not locate variable "cluster_adoption_tags".');

        $this->assertMatchesRegularExpression('/type\s*=\s*map\(string\)/', $matches[0]);
        $this->assertMatchesRegularExpression('/default\s*=\s*\{\s*\}/', $matches[0]);
    }

    public function test_staging_root_supplies_the_exact_live_cluster_tags_directly(): void
    {
        $staging = $this->stagingMain();
        preg_match('/module\s+"ecs_cluster"\s*\{.*?\n\}\n/s', $staging, $matches);
        $this->assertNotEmpty($matches, 'Could not locate module "ecs_cluster".');
        $block = $matches[0];

        $this->assertMatchesRegularExpression(
            '/cluster_adoption_tags\s*=\s*\{\s*Application\s*=\s*"FirmsBase"\s*Name\s*=\s*"firmsbase-staging-cluster"\s*\}/',
            $block
        );
    }

    public function test_cluster_ignores_only_tags_all_not_tags(): void
    {
        // tags is fully, explicitly modeled via cluster_adoption_tags
        // above and matches live exactly — only the computed tags_all
        // (re-merges against the provider's CURRENT default_tags block)
        // is ignored.
        $block = $this->extractResourceBlock($this->ecsClusterModuleMain(), 'aws_ecs_cluster', 'this');

        $this->assertMatchesRegularExpression('/ignore_changes\s*=\s*\[\s*tags_all\s*\]/', $block);
    }

    // ------------------------------------------------------------
    // ECS services: enable_ecs_managed_tags/propagate_tags/
    // wait_for_steady_state now explicitly modeled
    // ------------------------------------------------------------

    public function test_ecs_service_wires_managed_tags_and_propagate_tags_from_variables(): void
    {
        $block = $this->extractResourceBlock($this->ecsServiceModuleMain(), 'aws_ecs_service', 'this');

        $this->assertMatchesRegularExpression('/enable_ecs_managed_tags\s*=\s*var\.enable_ecs_managed_tags/', $block);
        $this->assertMatchesRegularExpression('/propagate_tags\s*=\s*var\.propagate_tags/', $block);
        $this->assertMatchesRegularExpression('/wait_for_steady_state\s*=\s*false/', $block);
    }

    public function test_ecs_service_ignore_changes_now_also_protects_wait_for_steady_state(): void
    {
        $block = $this->extractResourceBlock($this->ecsServiceModuleMain(), 'aws_ecs_service', 'this');

        $this->assertMatchesRegularExpression(
            '/ignore_changes\s*=\s*\[\s*task_definition,[^\]]*\btags\b,[^\]]*\btags_all\b,[^\]]*\bwait_for_steady_state\b/s',
            $block,
            'ignore_changes must still protect task_definition/tags/tags_all (unmodified) and now also wait_for_steady_state.'
        );
    }

    public function test_managed_tags_and_propagate_tags_variables_default_to_the_aws_api_defaults(): void
    {
        $vars = $this->ecsServiceModuleVariables();

        preg_match('/variable\s+"enable_ecs_managed_tags"\s*\{.*?\n\}\n/s', $vars, $managedMatches);
        $this->assertNotEmpty($managedMatches, 'Could not locate variable "enable_ecs_managed_tags".');
        $this->assertMatchesRegularExpression('/default\s*=\s*false/', $managedMatches[0]);

        preg_match('/variable\s+"propagate_tags"\s*\{.*?\n\}\n/s', $vars, $propagateMatches);
        $this->assertNotEmpty($propagateMatches, 'Could not locate variable "propagate_tags".');
        $this->assertMatchesRegularExpression('/default\s*=\s*"NONE"/', $propagateMatches[0]);
        $this->assertMatchesRegularExpression('/contains\(\["NONE",\s*"SERVICE",\s*"TASK_DEFINITION"\]/', $propagateMatches[0]);
    }

    public function test_worker_scheduler_critical_worker_override_to_the_exact_live_values(): void
    {
        $staging = $this->stagingMain();

        foreach (['worker', 'critical_worker', 'scheduler'] as $moduleName) {
            preg_match('/module\s+"'.$moduleName.'"\s*\{.*?\n\}\n/s', $staging, $matches);
            $this->assertNotEmpty($matches, "Could not locate module \"{$moduleName}\".");

            $this->assertStringContainsString('enable_ecs_managed_tags = true', $matches[0]);
            $this->assertStringContainsString('propagate_tags          = "TASK_DEFINITION"', $matches[0]);
        }
    }

    public function test_web_module_call_does_not_override_managed_tags_since_live_already_matches_the_default(): void
    {
        $staging = $this->stagingMain();
        preg_match('/module\s+"web"\s*\{.*?\n\}\n/s', $staging, $matches);
        $this->assertNotEmpty($matches, 'Could not locate module "web".');

        $this->assertStringNotContainsString('enable_ecs_managed_tags', $matches[0]);
        $this->assertStringNotContainsString('propagate_tags', $matches[0]);
    }

    public function test_service_identity_networking_and_deployment_fields_untouched(): void
    {
        $block = $this->extractResourceBlock($this->ecsServiceModuleMain(), 'aws_ecs_service', 'this');

        $this->assertMatchesRegularExpression('/desired_count\s*=\s*var\.desired_count/', $block);
        $this->assertMatchesRegularExpression('/task_definition\s*=\s*aws_ecs_task_definition\.this\.arn/', $block);
        $this->assertMatchesRegularExpression('/deployment_minimum_healthy_percent\s*=\s*var\.deployment_minimum_healthy_percent/', $block);
        $this->assertMatchesRegularExpression('/deployment_maximum_percent\s*=\s*var\.deployment_maximum_percent/', $block);
        $this->assertMatchesRegularExpression('/health_check_grace_period_seconds\s*=\s*var\.attach_target_group\s*\?\s*60\s*:\s*null/', $block);
    }

    // ------------------------------------------------------------
    // terraform.tfvars.example documents every new variable
    // ------------------------------------------------------------

    public function test_tfvars_example_documents_the_new_alb_ecr_overrides(): void
    {
        $example = $this->stagingTfvarsExample();

        $this->assertMatchesRegularExpression('/alb_name\s*=\s*"firmsbase-staging-alb"/', $example);
        $this->assertMatchesRegularExpression('/alb_target_group_name\s*=\s*"firmsbase-staging-tg"/', $example);
        $this->assertMatchesRegularExpression('/alb_enable_deletion_protection\s*=\s*true/', $example);
        $this->assertMatchesRegularExpression('/ecr_encryption_type\s*=\s*"AES256"/', $example);
    }

    // ------------------------------------------------------------
    // import-manifest.json: corrected honestly, classification/totals
    // unchanged
    // ------------------------------------------------------------

    public function test_manifest_records_the_correction_for_all_eight_affected_addresses(): void
    {
        $addresses = array_merge([
            'module.alb.aws_lb.this',
            'module.alb.aws_lb_target_group.web',
            'module.ecr.aws_ecr_repository.app',
            'module.ecs_cluster.aws_ecs_cluster.this',
        ], self::ECS_SERVICE_ADDRESSES);

        foreach ($addresses as $address) {
            $entry = $this->manifestEntry($address);
            $this->assertStringContainsString(
                'CORRECTED 2026-08-06',
                $entry['notes'],
                "{$address}'s manifest notes must record the 2026-08-06 correction."
            );
            $this->assertStringContainsString('§9.27', $entry['notes']);
        }
    }

    public function test_manifest_no_longer_lets_the_stale_alb_no_drift_claim_stand_uncorrected(): void
    {
        $entry = $this->manifestEntry('module.alb.aws_lb.this');

        $this->assertStringContainsString('WRONG', $entry['notes']);
        $this->assertStringContainsString('replace_paths', $entry['notes']);
    }

    public function test_manifest_totals_and_classifications_unchanged(): void
    {
        $manifest = $this->importManifest();
        $summary = $manifest['summary'];

        $this->assertSame(66, $summary['new']);
        $this->assertSame(8, $summary['import_unchanged']);
        $this->assertSame(15, $summary['import_then_migrate']);
        $this->assertSame(6, $summary['do_not_import']);
        $this->assertSame(95, $summary['total']);
        $this->assertCount(95, $manifest['resources']);

        $this->assertSame('import_unchanged', $this->manifestEntry('module.alb.aws_lb.this')['classification']);
        $this->assertSame('import_then_migrate', $this->manifestEntry('module.alb.aws_lb_target_group.web')['classification']);
        $this->assertSame('import_then_migrate', $this->manifestEntry('module.ecr.aws_ecr_repository.app')['classification']);
        $this->assertSame('import_then_migrate', $this->manifestEntry('module.ecs_cluster.aws_ecs_cluster.this')['classification']);
        foreach (self::ECS_SERVICE_ADDRESSES as $address) {
            $this->assertSame('import_then_migrate', $this->manifestEntry($address)['classification']);
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
    // Documentation records exact causes and the configuration-only,
    // no-apply nature of this correction
    // ------------------------------------------------------------

    public function test_documentation_records_the_exact_replace_paths_causes(): void
    {
        $doc = $this->stateAdoptionPlan();
        preg_match('/### 9\.27.*?(?=\n## \d)/s', $doc, $matches);
        $this->assertNotEmpty($matches, 'Could not locate §9.27 in state-adoption-plan.md.');
        $section = $matches[0];

        $this->assertStringContainsString('replace_paths: [name_prefix]', $section);
        $this->assertStringContainsString('encryption_configuration.0.encryption_type', $section);
        $this->assertStringContainsString('AES256', $section);
        $this->assertStringContainsString('Application', $section);
        $this->assertStringContainsString('TASK_DEFINITION', $section);
    }

    public function test_documentation_honestly_records_no_apply_was_run(): void
    {
        $doc = $this->stateAdoptionPlan();
        preg_match('/### 9\.27.*?(?=\n## \d)/s', $doc, $matches);
        $this->assertNotEmpty($matches, 'Could not locate §9.27 in state-adoption-plan.md.');
        $section = $matches[0];

        $this->assertMatchesRegularExpression('/No\s+`apply`\s+was\s+run/i', $section);
        $this->assertMatchesRegularExpression('/genuinely\s+no-op/i', $section);
    }

    public function test_variable_inventory_records_item_twenty(): void
    {
        $doc = $this->variableInventory();

        $this->assertMatchesRegularExpression('/20\.\s+\*\*ALB, target group, ECR repository, ECS cluster, and four ECS/', $doc);
        $this->assertStringContainsString('§9.27', $doc);
    }
}
