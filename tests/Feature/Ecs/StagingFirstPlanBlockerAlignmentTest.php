<?php

declare(strict_types=1);

namespace Tests\Feature\Ecs;

use Tests\TestCase;

/**
 * Proves the first-plan-blocker adoption-alignment correction (see
 * docs/ecs/state-adoption-plan.md §9.24): the imported-resource drift
 * that caused the earlier maintenance-canary targeted plan to propose
 * changes outside its five-address allowlist is now resolved, the
 * foundation KMS/S3 wave remains a separate, unbuilt deployment wave, and
 * the output-evaluation errors from that plan are corrected safely.
 * Reads the real, committed files only (fully deterministic, no
 * credentials needed), mirroring this repo's established Ecs test
 * philosophy.
 */
class StagingFirstPlanBlockerAlignmentTest extends TestCase
{
    private function elasticacheModuleMain(): string
    {
        return $this->readFile('infrastructure/ecs/modules/elasticache/main.tf');
    }

    private function iamModuleMain(): string
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
    // Execution-role tag mutation prevented
    // ------------------------------------------------------------

    public function test_execution_role_has_scoped_tags_lifecycle_protection(): void
    {
        $block = $this->extractResourceBlock($this->iamModuleMain(), 'aws_iam_role', 'task_execution');

        $this->assertMatchesRegularExpression(
            '/lifecycle\s*\{[^}]*ignore_changes\s*=\s*\[\s*tags\s*,\s*tags_all\s*\]/s',
            $block,
            'aws_iam_role.task_execution must ignore_changes on tags and tags_all to prevent a real, live iam:TagRole mutation from provider default_tags.'
        );
    }

    public function test_no_other_iam_module_resource_gained_a_tags_lifecycle_block(): void
    {
        // Only the already-imported task_execution role needs this
        // protection — the seven new (not-yet-created) per-role task
        // roles should receive their tags normally on first creation.
        $main = $this->iamModuleMain();
        $taskRoleBlock = $this->extractResourceBlock($main, 'aws_iam_role', 'task');

        $this->assertDoesNotMatchRegularExpression(
            '/ignore_changes\s*=\s*\[\s*tags/',
            $taskRoleBlock,
            'aws_iam_role.task (the new per-role task roles) must not gain tags ignore_changes — they do not exist live yet.'
        );
    }

    // ------------------------------------------------------------
    // ElastiCache subnet-group description/tags preserved
    // ------------------------------------------------------------

    public function test_subnet_group_description_and_tags_are_explicit_with_scoped_lifecycle_protection(): void
    {
        $block = $this->extractResourceBlock($this->elasticacheModuleMain(), 'aws_elasticache_subnet_group', 'this');

        $this->assertMatchesRegularExpression('/description\s*=\s*coalesce\(var\.subnet_group_description/', $block);
        $this->assertMatchesRegularExpression('/tags\s*=\s*var\.tags/', $block);
        $this->assertMatchesRegularExpression(
            '/lifecycle\s*\{[^}]*ignore_changes\s*=\s*\[\s*tags\s*,\s*tags_all\s*\]/s',
            $block,
            'aws_elasticache_subnet_group.this must ignore_changes on tags/tags_all to preserve live adoption metadata.'
        );
    }

    // ------------------------------------------------------------
    // ElastiCache replication-group description, snapshot retention,
    // tags, and security-group identity preserved
    // ------------------------------------------------------------

    public function test_replication_group_description_and_snapshot_retention_are_explicit(): void
    {
        $block = $this->extractResourceBlock($this->elasticacheModuleMain(), 'aws_elasticache_replication_group', 'this');

        $this->assertMatchesRegularExpression('/description\s*=\s*coalesce\(var\.replication_group_description/', $block);
        $this->assertMatchesRegularExpression('/snapshot_retention_limit\s*=\s*var\.snapshot_retention_limit/', $block);
    }

    public function test_replication_group_has_scoped_tags_lifecycle_protection_alongside_auth_token(): void
    {
        $block = $this->extractResourceBlock($this->elasticacheModuleMain(), 'aws_elasticache_replication_group', 'this');

        $this->assertMatchesRegularExpression(
            '/ignore_changes\s*=\s*\[\s*auth_token\s*,\s*tags\s*,\s*tags_all\s*\]/',
            $block,
            'aws_elasticache_replication_group.this must ignore_changes on auth_token (pre-existing) AND tags/tags_all (new).'
        );
    }

    public function test_replication_group_pins_newer_provider_schema_attributes_explicitly(): void
    {
        // apply_immediately was already explicit; auth_token_update_strategy
        // is newly pinned here. Both are provider-schema fields this
        // already-imported resource's live state predates — pinning them
        // to the shown default value doesn't eliminate the one-time
        // state-backfill plan action (that requires an actual apply, out
        // of scope for this mission), but does prove config is not itself
        // the cause. See docs/ecs/state-adoption-plan.md §9.24.
        $block = $this->extractResourceBlock($this->elasticacheModuleMain(), 'aws_elasticache_replication_group', 'this');

        $this->assertMatchesRegularExpression('/apply_immediately\s*=\s*false/', $block);
        $this->assertMatchesRegularExpression('/auth_token_update_strategy\s*=\s*"ROTATE"/', $block);
    }

    public function test_redis_security_group_pins_newer_provider_schema_attribute_explicitly(): void
    {
        $block = $this->extractResourceBlock($this->elasticacheModuleMain(), 'aws_security_group', 'redis');

        $this->assertMatchesRegularExpression('/revoke_rules_on_delete\s*=\s*false/', $block);
    }

    public function test_replication_group_security_group_ids_still_references_the_same_resource_address(): void
    {
        $block = $this->extractResourceBlock($this->elasticacheModuleMain(), 'aws_elasticache_replication_group', 'this');

        $this->assertMatchesRegularExpression(
            '/security_group_ids\s*=\s*\[\s*aws_security_group\.redis\.id\s*\]/',
            $block,
            'The replication group must still reference aws_security_group.redis.id directly — its resource address is unchanged, only that resource\'s own name/description arguments changed.'
        );
    }

    // ------------------------------------------------------------
    // Redis security group retains its exact imported identity —
    // no replacement, no rule-address change
    // ------------------------------------------------------------

    public function test_redis_security_group_models_live_name_and_description_instead_of_forcing_replacement(): void
    {
        $block = $this->extractResourceBlock($this->elasticacheModuleMain(), 'aws_security_group', 'redis');

        $this->assertMatchesRegularExpression('/name\s*=\s*var\.security_group_name/', $block);
        $this->assertMatchesRegularExpression(
            '/name_prefix\s*=\s*var\.security_group_name\s*==\s*null\s*\?\s*"\$\{var\.name_prefix\}-redis-"\s*:\s*null/',
            $block,
            'name_prefix must only apply when security_group_name is unset — both cannot be set simultaneously on aws_security_group.'
        );
        $this->assertMatchesRegularExpression('/description\s*=\s*coalesce\(var\.security_group_description/', $block);
    }

    public function test_redis_security_group_has_scoped_tags_lifecycle_protection(): void
    {
        $block = $this->extractResourceBlock($this->elasticacheModuleMain(), 'aws_security_group', 'redis');

        $this->assertMatchesRegularExpression(
            '/ignore_changes\s*=\s*\[\s*tags\s*,\s*tags_all\s*\]/',
            $block,
            'aws_security_group.redis must ignore_changes on tags/tags_all to preserve the live, manually-set adoption tag.'
        );
    }

    public function test_no_provider_wide_ignore_tags_was_introduced(): void
    {
        $versions = $this->readFile('infrastructure/ecs/environments/staging/versions.tf');

        $this->assertDoesNotMatchRegularExpression(
            '/ignore_tags\s*\{/',
            $versions,
            'A provider-wide ignore_tags block must never be used to solve these resources — lifecycle protection must stay scoped to the individual imported resources.'
        );
    }

    public function test_security_group_rule_address_and_wiring_are_unchanged(): void
    {
        $block = $this->extractResourceBlock($this->elasticacheModuleMain(), 'aws_security_group_rule', 'redis_ingress_from_ecs_tasks');

        $this->assertMatchesRegularExpression('/security_group_id\s*=\s*aws_security_group\.redis\.id/', $block);
        $this->assertMatchesRegularExpression('/source_security_group_id\s*=\s*var\.ecs_tasks_security_group_id/', $block);
        $this->assertMatchesRegularExpression('/from_port\s*=\s*6379/', $block);
        $this->assertMatchesRegularExpression('/to_port\s*=\s*6379/', $block);
    }

    // ------------------------------------------------------------
    // Staging root wires the 5 new overrides through unchanged
    // ------------------------------------------------------------

    public function test_staging_root_wires_all_five_elasticache_overrides_into_the_module_call(): void
    {
        $staging = $this->stagingMain();
        preg_match('/module\s+"elasticache"\s*\{.*?\n\}\n/s', $staging, $matches);
        $this->assertNotEmpty($matches, 'Could not locate module "elasticache".');
        $block = $matches[0];

        foreach ([
            'security_group_name           = var.elasticache_security_group_name',
            'security_group_description    = var.elasticache_security_group_description',
            'subnet_group_description      = var.elasticache_subnet_group_description',
            'replication_group_description = var.elasticache_replication_group_description',
            'snapshot_retention_limit      = var.elasticache_snapshot_retention_limit',
        ] as $expected) {
            $this->assertStringContainsString($expected, $block, "module \"elasticache\" must wire through: {$expected}");
        }
    }

    // ------------------------------------------------------------
    // Foundation wave (KMS/S3) remains separate and unbuilt
    // ------------------------------------------------------------

    public function test_foundation_wave_resources_remain_classified_new(): void
    {
        $addresses = [
            'module.kms.aws_kms_key.this',
            'module.kms.aws_kms_alias.this',
            'module.s3_documents.aws_s3_bucket.documents',
            'module.s3_documents.aws_s3_bucket_public_access_block.documents',
            'module.s3_documents.aws_s3_bucket_versioning.documents',
            'module.s3_documents.aws_s3_bucket_server_side_encryption_configuration.documents',
            'module.s3_documents.aws_s3_bucket_ownership_controls.documents',
        ];

        foreach ($addresses as $address) {
            $entry = $this->manifestEntry($address);
            $this->assertSame('new', $entry['classification'], "{$address} must remain classified \"new\" — this mission does not plan or create it.");
        }
    }

    public function test_documentation_honestly_records_the_diagnostic_plans_remaining_findings(): void
    {
        $doc = $this->stateAdoptionPlan();
        preg_match('/### 9\.24.*?(?=\n## \d)/s', $doc, $matches);
        $this->assertNotEmpty($matches, 'Could not locate §9.24 in state-adoption-plan.md.');
        $section = $matches[0];

        $this->assertStringContainsString('did not fully succeed', $section, 'The diagnostic-plan outcome must be recorded honestly, not overstated as fully clean.');
        $this->assertStringContainsString('module.security_groups.aws_security_group.ecs_tasks', $section);
        $this->assertMatchesRegularExpression('/separate,\s*explicitly-authorized follow-up mission/', $section);
        $this->assertStringContainsString('revoke_rules_on_delete', $section);
        $this->assertStringContainsString('auth_token_update_strategy', $section);
    }

    public function test_maintenance_s3_documents_policy_still_grants_get_put_delete_list_and_kms(): void
    {
        preg_match('/data\s+"aws_iam_policy_document"\s+"task_s3_documents"\s*\{.*?\n\}\n/s', $this->iamModuleMain(), $matches);
        $this->assertNotEmpty($matches, 'Could not locate data.aws_iam_policy_document.task_s3_documents.');
        $block = $matches[0];

        foreach (['s3:GetObject', 's3:PutObject', 's3:DeleteObject', 's3:ListBucket', 'kms:Decrypt', 'kms:GenerateDataKey'] as $action) {
            $this->assertStringContainsString($action, $block, "maintenance's S3-documents policy must still grant {$action} — not weakened to work around the foundation-wave dependency.");
        }
    }

    public function test_maintenance_log_group_still_references_the_kms_key(): void
    {
        $staging = $this->stagingMain();
        preg_match('/resource\s+"aws_cloudwatch_log_group"\s+"app"\s*\{.*?\n\}\n/s', $staging, $matches);
        $this->assertNotEmpty($matches, 'Could not locate aws_cloudwatch_log_group.app.');

        $this->assertMatchesRegularExpression(
            '/kms_key_id\s*=\s*module\.kms\.key_arn/',
            $matches[0],
            'The log group must still reference module.kms.key_arn — KMS encryption was not weakened to bypass the foundation-wave dependency.'
        );
    }

    // ------------------------------------------------------------
    // Output-evaluation errors corrected safely
    // ------------------------------------------------------------

    public function test_ses_consumer_outputs_use_try_not_a_fake_placeholder(): void
    {
        $outputs = $this->stagingOutputs();

        $this->assertMatchesRegularExpression(
            '/value\s*=\s*try\(module\.iam\.task_role_arns\["ses_consumer"\],\s*null\)/',
            $outputs
        );
        $this->assertMatchesRegularExpression(
            '/value\s*=\s*try\(aws_cloudwatch_log_group\.app\["ses-consumer"\]\.name,\s*null\)/',
            $outputs
        );

        // Never a fake placeholder ARN/endpoint/ID.
        $this->assertDoesNotMatchRegularExpression('/arn:aws:iam::000000000000/', $outputs);
        $this->assertDoesNotMatchRegularExpression('/"placeholder"/', $outputs);
    }

    public function test_task_definition_arns_output_was_not_touched(): void
    {
        // This output never errored under the original targeted plan (it
        // is not a for_each-map hardcoded-key lookup) — confirm it was
        // left exactly as-is, not incidentally rewritten.
        $outputs = $this->stagingOutputs();

        $this->assertMatchesRegularExpression(
            '/output\s+"task_definition_arns"\s*\{\s*value\s*=\s*\{\s*web\s*=\s*module\.web\.task_definition_arn/s',
            $outputs
        );
    }

    // ------------------------------------------------------------
    // Manifest and documentation consistency
    // ------------------------------------------------------------

    public function test_manifest_totals_and_addresses_unchanged_by_this_correction(): void
    {
        // The correction touches only notes fields on already-"new"/
        // already-imported entries — no address added, removed, or
        // reclassified as a byproduct.
        $manifest = $this->importManifest();
        $resources = collect($manifest['resources']);

        foreach ([
            'module.iam.aws_iam_role.task_execution',
            'module.elasticache.aws_elasticache_subnet_group.this',
            'module.elasticache.aws_elasticache_replication_group.this',
            'module.elasticache.aws_security_group.redis',
        ] as $address) {
            $entry = $resources->firstWhere('address', $address);
            $this->assertNotNull($entry, "{$address} must still be present in the manifest.");
            $this->assertMatchesRegularExpression('/CORRECTED 2026-08-06 \(§9\.24\)/u', $entry['notes'], "{$address}'s notes must record the §9.24 correction.");
        }
    }

    public function test_documentation_records_the_evidence_backed_causes_without_secret_values(): void
    {
        $doc = $this->stateAdoptionPlan();
        preg_match('/### 9\.24.*?(?=\n## \d)/s', $doc, $matches);
        $this->assertNotEmpty($matches, 'Could not locate §9.24 in state-adoption-plan.md.');
        $section = $matches[0];

        $this->assertStringContainsString('ForceNew', $section);
        $this->assertStringContainsString('foundation deployment wave', $section);
        $this->assertMatchesRegularExpression('/Invalid index/i', $section);
        $this->assertStringContainsString('not approved', $section);

        // No secret value, Redis token, or SecretString ever appears.
        $this->assertDoesNotMatchRegularExpression('/SecretString/i', $section);
        $this->assertDoesNotMatchRegularExpression('/redis_auth_token\s*=\s*"[^$]/i', $section);
    }
}
