<?php

declare(strict_types=1);

namespace Tests\Feature\Ecs;

use Tests\TestCase;

/**
 * Proves the ElastiCache subnet-group membership correction (see
 * docs/ecs/state-adoption-plan.md §9.15): the module and staging adoption
 * bundle can now represent live's confirmed 6-subnet membership, decoupled
 * from ECS's own private_subnet_ids — against the real, committed files,
 * never against a live `terraform plan`/`apply`/`import` (no AWS contact,
 * no credentials needed, fully deterministic).
 */
class StagingElastiCacheSubnetAlignmentTest extends TestCase
{
    private const LIVE_SUBNET_IDS = [
        'subnet-020540b8377bb4d0e',
        'subnet-0d328451d742a4a3c',
        'subnet-07efcb5d4bcf5aa59',
        'subnet-04f36560361246d4b',
        'subnet-0631d53a7acde6530',
        'subnet-06cb2ddbdb7cf4d69',
    ];

    private function elasticacheModuleMain(): string
    {
        return $this->readFile('infrastructure/ecs/modules/elasticache/main.tf');
    }

    private function elasticacheModuleVariables(): string
    {
        return $this->readFile('infrastructure/ecs/modules/elasticache/variables.tf');
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

    private function extractSection(string $doc, string $startPattern, string $endPattern): string
    {
        preg_match('/'.$startPattern.'.*?(?='.$endPattern.')/s', $doc, $matches);
        $this->assertNotEmpty($matches, "Could not locate section matching /{$startPattern}/.");

        return $matches[0];
    }

    private function extractSubnetIdsFromTfvarsExample(): array
    {
        $tfvars = $this->stagingTfvarsExample();
        preg_match('/elasticache_subnet_ids\s*=\s*\[(.*?)\]/s', $tfvars, $matches);
        $this->assertNotEmpty($matches, 'Could not locate elasticache_subnet_ids block in terraform.tfvars.example.');

        preg_match_all('/"([^"]+)"/', $matches[1], $idMatches);

        return $idMatches[1];
    }

    // ------------------------------------------------------------
    // Module: required input, decoupled from private_subnet_ids
    // ------------------------------------------------------------

    public function test_module_subnet_ids_variable_has_no_default(): void
    {
        $block = $this->extractVariableBlock($this->elasticacheModuleVariables(), 'subnet_ids');

        $this->assertStringContainsString('type        = list(string)', $block);
        $this->assertDoesNotMatchRegularExpression('/default\s*=/', $block, 'subnet_ids must have no default — every caller must supply it explicitly.');
    }

    public function test_module_no_longer_declares_private_subnet_ids(): void
    {
        $vars = $this->elasticacheModuleVariables();

        $this->assertDoesNotMatchRegularExpression(
            '/variable "private_subnet_ids"/',
            $vars,
            'The elasticache module must not name its subnet input private_subnet_ids — the live group\'s membership is broader than ECS placement.'
        );
    }

    public function test_subnet_group_resource_uses_the_new_variable(): void
    {
        $block = $this->extractResourceBlock($this->elasticacheModuleMain(), 'aws_elasticache_subnet_group', 'this');

        $this->assertMatchesRegularExpression('/subnet_ids\s*=\s*var\.subnet_ids/', $block);
    }

    public function test_resource_address_is_unchanged(): void
    {
        $this->assertMatchesRegularExpression(
            '/resource "aws_elasticache_subnet_group" "this"/',
            $this->elasticacheModuleMain(),
            'The resource address module.elasticache.aws_elasticache_subnet_group.this must not change.'
        );
    }

    public function test_subnet_group_and_replication_group_remain_separate_resources(): void
    {
        $main = $this->elasticacheModuleMain();

        $this->assertMatchesRegularExpression('/resource "aws_elasticache_subnet_group" "this"/', $main);
        $this->assertMatchesRegularExpression('/resource "aws_elasticache_replication_group" "this"/', $main);

        preg_match_all('/resource "aws_elasticache_(subnet_group|replication_group)" "this"/', $main, $matches);
        $this->assertCount(2, $matches[0], 'Exactly two distinct resources (subnet group and replication group) must exist.');
    }

    // ------------------------------------------------------------
    // Staging root: fallback, wiring, ECS unaffected
    // ------------------------------------------------------------

    public function test_staging_root_variable_defaults_to_null(): void
    {
        $block = $this->extractVariableBlock($this->stagingVariables(), 'elasticache_subnet_ids');

        $this->assertStringContainsString('type        = list(string)', $block);
        $this->assertMatchesRegularExpression('/default\s*=\s*null/', $block);
    }

    public function test_staging_wires_the_effective_local_into_the_module_call(): void
    {
        $block = $this->extractModuleBlock($this->stagingMain(), 'elasticache');

        $this->assertMatchesRegularExpression('/subnet_ids\s*=\s*local\.elasticache_subnet_ids/', $block);
        $this->assertDoesNotMatchRegularExpression('/private_subnet_ids\s*=\s*var\.private_subnet_ids/', $block, 'module.elasticache must no longer be called with private_subnet_ids directly.');
    }

    public function test_effective_local_falls_back_to_private_subnet_ids(): void
    {
        $main = $this->stagingMain();

        $this->assertMatchesRegularExpression(
            '/elasticache_subnet_ids\s*=\s*coalesce\(var\.elasticache_subnet_ids,\s*var\.private_subnet_ids\)/',
            $main
        );
    }

    public function test_ecs_subnet_variables_are_unchanged(): void
    {
        $vars = $this->stagingVariables();

        $publicBlock = $this->extractVariableBlock($vars, 'public_subnet_ids');
        $this->assertStringContainsString('type = list(string)', $publicBlock);

        $privateBlock = $this->extractVariableBlock($vars, 'private_subnet_ids');
        $this->assertStringContainsString('type = list(string)', $privateBlock);

        // Every ECS service call site must still reference private_subnet_ids
        // directly and unconditionally — this correction must not touch ECS
        // task placement.
        $main = $this->stagingMain();
        $this->assertGreaterThanOrEqual(
            7,
            substr_count($main, 'subnet_ids         = var.private_subnet_ids'),
            'Every ECS service caller must still wire subnet_ids = var.private_subnet_ids directly, unaffected by this correction.'
        );
    }

    // ------------------------------------------------------------
    // terraform.tfvars.example: exactly the 6 live subnet IDs
    // ------------------------------------------------------------

    public function test_example_tfvars_supplies_exactly_the_six_live_subnet_ids(): void
    {
        $ids = $this->extractSubnetIdsFromTfvarsExample();

        sort($ids);
        $expected = self::LIVE_SUBNET_IDS;
        sort($expected);

        $this->assertSame($expected, $ids, 'terraform.tfvars.example must list exactly the 6 live subnet IDs, no more, no fewer.');
        $this->assertCount(6, array_unique($ids), 'All 6 subnet IDs must be unique.');
    }

    // ------------------------------------------------------------
    // import-manifest.json
    // ------------------------------------------------------------

    public function test_manifest_records_the_permission_resolution_and_subnet_fix(): void
    {
        $entry = $this->manifestEntry('module.elasticache.aws_elasticache_subnet_group.this');

        $this->assertStringContainsString('GRANTED', $entry['prerequisite']);
        $this->assertStringContainsString('6 subnets', $entry['prerequisite']);
        $this->assertMatchesRegularExpression('/does NOT authorize removing/i', $entry['prerequisite']);
        $this->assertStringContainsString('module.elasticache.aws_elasticache_subnet_group.this', $entry['prerequisite']);
    }

    public function test_manifest_totals_and_classification_unchanged(): void
    {
        $manifest = $this->importManifest();
        $summary = $manifest['summary'];

        $this->assertSame(66, $summary['new']);
        $this->assertSame(8, $summary['import_unchanged']);
        $this->assertSame(15, $summary['import_then_migrate']);
        $this->assertSame(6, $summary['do_not_import']);
        $this->assertSame(95, $summary['total']);

        $this->assertSame(
            'import_then_migrate',
            $this->manifestEntry('module.elasticache.aws_elasticache_subnet_group.this')['classification']
        );
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
    // Documentation
    // ------------------------------------------------------------

    public function test_documentation_records_the_former_two_versus_six_mismatch(): void
    {
        $section = $this->extractSection($this->stateAdoptionPlan(), '### 9\.15', '## 10\.');

        $this->assertMatchesRegularExpression('/6\s+subnets/', $section);
        $this->assertMatchesRegularExpression('/only\s+2\s+subnets|2\s+subnets/', $section);
        $this->assertMatchesRegularExpression('/not\s+adoption-safe/i', $section);
    }

    public function test_documentation_records_tag_read_permission_resolved(): void
    {
        $doc = $this->stateAdoptionPlan();
        $section = $this->extractSection($doc, '### 9\.13', '### 9\.14');

        $this->assertMatchesRegularExpression('/now\s+granted/i', $section);
        $this->assertStringContainsString('RESOLVED', $doc);
    }

    public function test_documentation_states_subnet_and_replication_groups_remain_separate_imports(): void
    {
        $section = $this->extractSection($this->stateAdoptionPlan(), '### 9\.15', '## 10\.');

        $this->assertStringContainsString('module.elasticache.aws_elasticache_replication_group.this', $section);
        $this->assertMatchesRegularExpression('/later,?\s+separate\s+import/i', $section);
    }

    public function test_documentation_states_import_does_not_authorize_removing_subnets(): void
    {
        $section = $this->extractSection($this->stateAdoptionPlan(), '### 9\.15', '## 10\.');

        $this->assertMatchesRegularExpression('/does\s+\*\*not\*\*\s+authorize\s+removing/i', $section);
        $this->assertMatchesRegularExpression('/availability,\s*\n?\s*failover,\s*\n?\s*networking,\s*\n?\s*and\s*\n?\s*maintenance\s*\n?\s*review/i', $section);
    }

    public function test_variable_inventory_records_the_correction(): void
    {
        $doc = $this->variableInventory();

        $this->assertMatchesRegularExpression('/6\s+subnets/', $doc);
        $this->assertStringContainsString('elasticache_subnet_ids', $doc);
        $this->assertMatchesRegularExpression('/RESOLVED/i', $doc);
    }
}
