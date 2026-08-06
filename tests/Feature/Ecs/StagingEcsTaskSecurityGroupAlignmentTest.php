<?php

declare(strict_types=1);

namespace Tests\Feature\Ecs;

use Tests\TestCase;

/**
 * Proves the ECS-task security-group adoption-alignment correction (see
 * docs/ecs/state-adoption-plan.md §9.25): the same evidence-proven
 * name/description-modeling pattern already applied to
 * module.elasticache.aws_security_group.redis (§9.24) is now applied to
 * module.security_groups.aws_security_group.ecs_tasks, resolving the
 * cascading forced replacement of the RDS and Redis ingress rules that
 * reference it. Also proves the provider-schema backfill fields
 * (revoke_rules_on_delete, apply_immediately, auth_token_update_strategy)
 * are narrowly ignore_changes-protected with no security/availability
 * setting hidden, and that no maintenance/KMS/S3/task-definition/
 * ECS-service resource was added. Reads the real, committed files only
 * (fully deterministic, no credentials needed).
 */
class StagingEcsTaskSecurityGroupAlignmentTest extends TestCase
{
    private function securityGroupsModuleMain(): string
    {
        return $this->readFile('infrastructure/ecs/modules/security_groups/main.tf');
    }

    private function elasticacheModuleMain(): string
    {
        return $this->readFile('infrastructure/ecs/modules/elasticache/main.tf');
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

    private function extractResourceBlock(string $content, string $type, string $name): string
    {
        preg_match('/resource\s+"'.preg_quote($type, '/').'"\s+"'.preg_quote($name, '/').'"\s*\{.*?\n\}\n/s', $content, $matches);
        $this->assertNotEmpty($matches, "Could not locate resource \"{$type}\" \"{$name}\".");

        return $matches[0];
    }

    // ------------------------------------------------------------
    // ECS-task SG uses exact live fixed name/description — no
    // name_prefix remains selected when live has a fixed name
    // ------------------------------------------------------------

    public function test_ecs_tasks_sg_models_live_name_and_description_instead_of_forcing_replacement(): void
    {
        $block = $this->extractResourceBlock($this->securityGroupsModuleMain(), 'aws_security_group', 'ecs_tasks');

        $this->assertMatchesRegularExpression('/name\s*=\s*var\.ecs_tasks_security_group_name/', $block);
        $this->assertMatchesRegularExpression(
            '/name_prefix\s*=\s*var\.ecs_tasks_security_group_name\s*==\s*null\s*\?\s*"\$\{var\.name_prefix\}-ecs-tasks-"\s*:\s*null/',
            $block,
            'name_prefix must only apply when ecs_tasks_security_group_name is unset — both cannot be set simultaneously on aws_security_group.'
        );
        $this->assertMatchesRegularExpression('/description\s*=\s*coalesce\(var\.ecs_tasks_security_group_description/', $block);
    }

    public function test_staging_root_sets_ecs_tasks_overrides_to_the_exact_live_values_via_variables(): void
    {
        $staging = $this->stagingMain();
        preg_match('/module\s+"security_groups"\s*\{.*?\n\}\n/s', $staging, $matches);
        $this->assertNotEmpty($matches, 'Could not locate module "security_groups".');
        $block = $matches[0];

        $this->assertStringContainsString('ecs_tasks_security_group_name        = var.ecs_tasks_security_group_name', $block);
        $this->assertStringContainsString('ecs_tasks_security_group_description = var.ecs_tasks_security_group_description', $block);
    }

    public function test_exact_vpc_preserved(): void
    {
        $block = $this->extractResourceBlock($this->securityGroupsModuleMain(), 'aws_security_group', 'ecs_tasks');

        $this->assertMatchesRegularExpression('/vpc_id\s*=\s*var\.vpc_id/', $block);
    }

    // ------------------------------------------------------------
    // Tags do not mutate the live imported SG
    // ------------------------------------------------------------

    public function test_ecs_tasks_sg_has_scoped_tags_lifecycle_protection(): void
    {
        $block = $this->extractResourceBlock($this->securityGroupsModuleMain(), 'aws_security_group', 'ecs_tasks');

        $this->assertMatchesRegularExpression(
            '/ignore_changes\s*=\s*\[\s*revoke_rules_on_delete\s*,\s*tags\s*,\s*tags_all\s*\]/',
            $block,
            'aws_security_group.ecs_tasks must ignore_changes on tags/tags_all (adoption metadata) and revoke_rules_on_delete (provider bookkeeping).'
        );
    }

    public function test_no_provider_wide_ignore_tags_was_introduced(): void
    {
        $versions = $this->readFile('infrastructure/ecs/environments/staging/versions.tf');

        $this->assertDoesNotMatchRegularExpression(
            '/ignore_tags\s*\{/',
            $versions,
            'A provider-wide ignore_tags block must never be used — lifecycle protection must stay scoped to individual imported resources.'
        );
    }

    public function test_alb_security_group_models_live_name_and_description_instead_of_forcing_replacement(): void
    {
        // A subsequent mission found and corrected this — the identical
        // ForceNew pattern already fixed on the ECS-tasks and Redis
        // security groups. See docs/ecs/state-adoption-plan.md.
        $block = $this->extractResourceBlock($this->securityGroupsModuleMain(), 'aws_security_group', 'alb');

        $this->assertMatchesRegularExpression('/name\s*=\s*var\.alb_security_group_name/', $block);
        $this->assertMatchesRegularExpression(
            '/name_prefix\s*=\s*var\.alb_security_group_name\s*==\s*null\s*\?\s*"\$\{var\.name_prefix\}-alb-"\s*:\s*null/',
            $block
        );
        $this->assertMatchesRegularExpression('/description\s*=\s*coalesce\(var\.alb_security_group_description/', $block);
        $this->assertDoesNotMatchRegularExpression('/ecs_tasks_security_group_name/', $block, 'The ALB security group must not reference the ecs_tasks-specific override variables.');
    }

    public function test_alb_security_group_has_scoped_tags_and_revoke_rules_lifecycle_protection(): void
    {
        $block = $this->extractResourceBlock($this->securityGroupsModuleMain(), 'aws_security_group', 'alb');

        $this->assertMatchesRegularExpression('/revoke_rules_on_delete\s*=\s*false/', $block);
        $this->assertMatchesRegularExpression(
            '/ignore_changes\s*=\s*\[\s*revoke_rules_on_delete\s*,\s*tags\s*,\s*tags_all\s*\]/',
            $block
        );
    }

    public function test_staging_root_wires_alb_overrides_into_the_module_call(): void
    {
        $staging = $this->stagingMain();
        preg_match('/module\s+"security_groups"\s*\{.*?\n\}\n/s', $staging, $matches);
        $this->assertNotEmpty($matches, 'Could not locate module "security_groups".');
        $block = $matches[0];

        $this->assertStringContainsString('alb_security_group_name        = var.alb_security_group_name', $block);
        $this->assertStringContainsString('alb_security_group_description = var.alb_security_group_description', $block);
    }

    // ------------------------------------------------------------
    // Related ALB/RDS/Redis rule addresses remain unchanged
    // ------------------------------------------------------------

    public function test_related_rule_addresses_and_wiring_are_unchanged(): void
    {
        $secGroups = $this->securityGroupsModuleMain();

        $ingressBlock = $this->extractResourceBlock($secGroups, 'aws_security_group_rule', 'ecs_tasks_ingress_from_alb');
        $this->assertMatchesRegularExpression('/security_group_id\s*=\s*aws_security_group\.ecs_tasks\.id/', $ingressBlock);
        $this->assertMatchesRegularExpression('/source_security_group_id\s*=\s*aws_security_group\.alb\.id/', $ingressBlock);

        $rdsBlock = $this->extractResourceBlock($secGroups, 'aws_security_group_rule', 'rds_ingress_from_ecs_tasks');
        $this->assertMatchesRegularExpression('/source_security_group_id\s*=\s*aws_security_group\.ecs_tasks\.id/', $rdsBlock);
        $this->assertMatchesRegularExpression('/security_group_id\s*=\s*var\.existing_rds_security_group_id/', $rdsBlock);
    }

    public function test_redis_ingress_rule_retains_the_same_ecs_source_sg_reference(): void
    {
        $block = $this->extractResourceBlock($this->elasticacheModuleMain(), 'aws_security_group_rule', 'redis_ingress_from_ecs_tasks');

        $this->assertMatchesRegularExpression('/source_security_group_id\s*=\s*var\.ecs_tasks_security_group_id/', $block);
        $this->assertMatchesRegularExpression('/security_group_id\s*=\s*aws_security_group\.redis\.id/', $block);
        $this->assertMatchesRegularExpression('/from_port\s*=\s*6379/', $block);
        $this->assertMatchesRegularExpression('/to_port\s*=\s*6379/', $block);
        $this->assertMatchesRegularExpression('/protocol\s*=\s*"tcp"/', $block);
        $this->assertMatchesRegularExpression('/type\s*=\s*"ingress"/', $block);
    }

    // ------------------------------------------------------------
    // Rule descriptions preserve live's absence — no cosmetic text
    // added to already-imported live rules during adoption
    // ------------------------------------------------------------

    public function test_ecs_tasks_ingress_and_rds_ingress_rules_have_no_description(): void
    {
        // Freshly confirmed via aws ec2 describe-security-group-rules:
        // neither live rule has ever carried a description. Adding one
        // during adoption would be cosmetic, not evidence-backed.
        $secGroups = $this->securityGroupsModuleMain();

        $ingressBlock = $this->extractResourceBlock($secGroups, 'aws_security_group_rule', 'ecs_tasks_ingress_from_alb');
        $this->assertDoesNotMatchRegularExpression('/description\s*=/', $ingressBlock);

        $rdsBlock = $this->extractResourceBlock($secGroups, 'aws_security_group_rule', 'rds_ingress_from_ecs_tasks');
        $this->assertDoesNotMatchRegularExpression('/description\s*=/', $rdsBlock);
    }

    public function test_alb_ingress_https_rule_has_no_description(): void
    {
        $block = $this->extractResourceBlock($this->securityGroupsModuleMain(), 'aws_security_group_rule', 'alb_ingress_https');

        $this->assertDoesNotMatchRegularExpression('/description\s*=/', $block);
    }

    public function test_redis_ingress_rule_has_no_description(): void
    {
        $block = $this->extractResourceBlock($this->elasticacheModuleMain(), 'aws_security_group_rule', 'redis_ingress_from_ecs_tasks');

        $this->assertDoesNotMatchRegularExpression('/description\s*=/', $block);
    }

    // ------------------------------------------------------------
    // Provider-schema fields explicitly modeled or narrowly documented
    // ------------------------------------------------------------

    public function test_replication_group_provider_schema_fields_are_pinned_and_protected(): void
    {
        $block = $this->extractResourceBlock($this->elasticacheModuleMain(), 'aws_elasticache_replication_group', 'this');

        $this->assertMatchesRegularExpression('/apply_immediately\s*=\s*false/', $block);
        $this->assertMatchesRegularExpression('/auth_token_update_strategy\s*=\s*"ROTATE"/', $block);
        $this->assertMatchesRegularExpression(
            '/ignore_changes\s*=\s*\[\s*auth_token\s*,\s*apply_immediately\s*,\s*auth_token_update_strategy\s*,\s*tags\s*,\s*tags_all\s*\]/',
            $block
        );
    }

    public function test_redis_sg_revoke_rules_on_delete_is_pinned_and_protected(): void
    {
        $block = $this->extractResourceBlock($this->elasticacheModuleMain(), 'aws_security_group', 'redis');

        $this->assertMatchesRegularExpression('/revoke_rules_on_delete\s*=\s*false/', $block);
        $this->assertMatchesRegularExpression('/ignore_changes\s*=\s*\[\s*revoke_rules_on_delete\s*,\s*tags\s*,\s*tags_all\s*\]/', $block);
    }

    // ------------------------------------------------------------
    // No maintenance, KMS, S3, task-definition, or ECS-service
    // resource was added by this mission
    // ------------------------------------------------------------

    public function test_no_new_maintenance_kms_s3_or_task_definition_resource_was_added(): void
    {
        $staging = $this->stagingMain();

        $this->assertSame(1, preg_match_all('/^module\s+"maintenance"\s*\{/m', $staging), 'Exactly one module.maintenance call must exist — no duplicate or new instance added.');
        $this->assertSame(1, preg_match_all('/^module\s+"kms"\s*\{/m', $staging), 'Exactly one module.kms call must exist — no new instance added.');
        $this->assertSame(1, preg_match_all('/^module\s+"s3_documents"\s*\{/m', $staging), 'Exactly one module.s3_documents call must exist — no new instance added.');
    }

    public function test_foundation_wave_still_classified_new(): void
    {
        foreach ([
            'module.kms.aws_kms_key.this',
            'module.kms.aws_kms_alias.this',
            'module.s3_documents.aws_s3_bucket.documents',
        ] as $address) {
            $entry = $this->manifestEntry($address);
            $this->assertSame('new', $entry['classification'], "{$address} must remain classified \"new\" — this mission does not plan or create it.");
        }
    }

    public function test_manifest_totals_unchanged(): void
    {
        $manifest = $this->importManifest();
        $this->assertCount(95, $manifest['resources'], 'Manifest total resource count must remain 95 — only notes fields were corrected.');
    }

    public function test_manifest_records_the_ecs_tasks_sg_correction(): void
    {
        $entry = $this->manifestEntry('module.security_groups.aws_security_group.ecs_tasks');
        $this->assertMatchesRegularExpression('/CORRECTED 2026-08-06 \(§9\.25\)/u', $entry['notes']);
    }

    // ------------------------------------------------------------
    // Documentation records exact causes, classifications, and the
    // newly-discovered out-of-scope ALB SG blocker honestly
    // ------------------------------------------------------------

    public function test_documentation_records_the_exact_causes_and_classifications(): void
    {
        $doc = $this->stateAdoptionPlan();
        preg_match('/### 9\.25.*?(?=\n## \d)/s', $doc, $matches);
        $this->assertNotEmpty($matches, 'Could not locate §9.25 in state-adoption-plan.md.');
        $section = $matches[0];

        $this->assertStringContainsString('forces replacement', $section);
        $this->assertStringContainsString('not assumed', $section);
        $this->assertStringContainsString('module.security_groups.aws_security_group.alb', $section);
        $this->assertMatchesRegularExpression('/not corrected/i', $section);
        $this->assertStringContainsString('revoke_rules_on_delete', $section);
        $this->assertStringContainsString('apply_immediately', $section);
        $this->assertStringContainsString('auth_token_update_strategy', $section);
        $this->assertMatchesRegularExpression('/foundation\s+KMS\/S3\s+wave/', $section);
    }

    public function test_documentation_honestly_records_the_diagnostic_plan_is_not_clean(): void
    {
        $doc = $this->stateAdoptionPlan();
        preg_match('/### 9\.25.*?(?=\n## \d)/s', $doc, $matches);
        $this->assertNotEmpty($matches, 'Could not locate §9.25 in state-adoption-plan.md.');
        $section = $matches[0];

        $this->assertMatchesRegularExpression('/no-op/i', $section, 'The three genuinely zero-change resources must be recorded as machine-confirmed no-op.');
        $this->assertMatchesRegularExpression('/is NOT\s+zero-change/', $section);
        $this->assertMatchesRegularExpression('/No apply was performed/i', $section);
        $this->assertMatchesRegularExpression('/not\s+(?:represented\s+as|claimed\s+to\s+be)\s+clean/i', $section, 'The diagnostic plan must not be overstated as clean.');
    }
}
