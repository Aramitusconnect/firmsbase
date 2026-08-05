<?php

declare(strict_types=1);

namespace Tests\Feature\Ecs;

use Tests\TestCase;

/**
 * Proves the ECS cluster Container Insights correction (see
 * docs/ecs/state-adoption-plan.md §9.14): the ecs_cluster module and
 * staging adoption bundle can now represent live's confirmed
 * containerInsights=disabled setting, correcting the prior pass's stale
 * "no known drift" claim — against the real, committed files, never
 * against a live `terraform plan`/`apply`/`import` (no AWS contact, no
 * credentials needed, fully deterministic).
 */
class StagingEcsClusterContainerInsightsTest extends TestCase
{
    private function ecsClusterModuleMain(): string
    {
        return $this->readFile('infrastructure/ecs/modules/ecs_cluster/main.tf');
    }

    private function ecsClusterModuleVariables(): string
    {
        return $this->readFile('infrastructure/ecs/modules/ecs_cluster/variables.tf');
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

    // ------------------------------------------------------------
    // Module: required input, no default
    // ------------------------------------------------------------

    public function test_module_container_insights_enabled_has_no_default(): void
    {
        $block = $this->extractVariableBlock($this->ecsClusterModuleVariables(), 'container_insights_enabled');

        $this->assertStringContainsString('type        = bool', $block);
        $this->assertDoesNotMatchRegularExpression('/default\s*=/', $block, 'container_insights_enabled must have no module-level default.');
    }

    public function test_setting_block_is_conditional_on_the_variable(): void
    {
        $block = $this->extractResourceBlock($this->ecsClusterModuleMain(), 'aws_ecs_cluster', 'this');

        $this->assertMatchesRegularExpression(
            '/value\s*=\s*var\.container_insights_enabled\s*\?\s*"enabled"\s*:\s*"disabled"/',
            $block,
            'containerInsights must render "enabled" or "disabled" based on var.container_insights_enabled.'
        );
        $this->assertDoesNotMatchRegularExpression('/value\s*=\s*"enabled"\s*$/m', $block, 'The old hardcoded "enabled" literal must be gone.');
    }

    public function test_resource_address_is_unchanged(): void
    {
        $this->assertMatchesRegularExpression(
            '/resource "aws_ecs_cluster" "this"/',
            $this->ecsClusterModuleMain(),
            'The resource address module.ecs_cluster.aws_ecs_cluster.this must not change.'
        );
    }

    public function test_cluster_and_capacity_provider_association_remain_separate_resources(): void
    {
        $main = $this->ecsClusterModuleMain();

        $this->assertMatchesRegularExpression('/resource "aws_ecs_cluster" "this"/', $main);
        $this->assertMatchesRegularExpression('/resource "aws_ecs_cluster_capacity_providers" "this"/', $main);

        // The two resources must remain distinct — the capacity-providers
        // resource must not be folded into (or renamed to overlap) the
        // cluster resource itself.
        preg_match_all('/resource "aws_ecs_cluster(_capacity_providers)?" "this"/', $main, $matches);
        $this->assertCount(2, $matches[0], 'Exactly two distinct resources (cluster and capacity-providers association) must exist.');
    }

    // ------------------------------------------------------------
    // Staging root: variable, default, wiring
    // ------------------------------------------------------------

    public function test_staging_root_variable_defaults_to_true(): void
    {
        $block = $this->extractVariableBlock($this->stagingVariables(), 'ecs_container_insights_enabled');

        $this->assertStringContainsString('type        = bool', $block);
        $this->assertMatchesRegularExpression('/default\s*=\s*true/', $block);
    }

    public function test_staging_passes_the_variable_into_the_module_call(): void
    {
        $block = $this->extractModuleBlock($this->stagingMain(), 'ecs_cluster');

        $this->assertMatchesRegularExpression(
            '/container_insights_enabled\s*=\s*var\.ecs_container_insights_enabled/',
            $block
        );
    }

    public function test_example_tfvars_records_the_live_adoption_override_of_false(): void
    {
        $tfvars = $this->stagingTfvarsExample();

        $this->assertMatchesRegularExpression('/ecs_container_insights_enabled\s*=\s*false/', $tfvars);
    }

    // ------------------------------------------------------------
    // import-manifest.json: stale claim corrected, classification/totals unchanged
    // ------------------------------------------------------------

    public function test_manifest_no_longer_claims_the_cluster_has_no_drift(): void
    {
        $entry = $this->manifestEntry('module.ecs_cluster.aws_ecs_cluster.this');

        // The prerequisite field legitimately quotes the OLD, now-corrected
        // claim ("...was WRONG") as historical narrative — that's expected
        // and fine. The notes field represents the CURRENT, active
        // classification and must not itself assert no-drift.
        $this->assertStringNotContainsString(
            'has no known drift',
            $entry['notes'],
            'The manifest notes (current classification) must not claim the cluster resource has no known drift.'
        );
        $this->assertStringContainsString('WRONG', $entry['prerequisite']);
        $this->assertStringContainsString('containerInsights', $entry['prerequisite']);
        $this->assertStringContainsString('Group A', $entry['notes']);
    }

    public function test_manifest_records_the_import_does_not_authorize_enabling_later(): void
    {
        $entry = $this->manifestEntry('module.ecs_cluster.aws_ecs_cluster.this');

        $this->assertMatchesRegularExpression('/does NOT authorize enabling/i', $entry['prerequisite']);
    }

    public function test_manifest_totals_and_classification_unchanged(): void
    {
        $manifest = $this->importManifest();
        $summary = $manifest['summary'];

        $this->assertSame(66, $summary['new']);
        $this->assertSame(6, $summary['import_unchanged']);
        $this->assertSame(16, $summary['import_then_migrate']);
        $this->assertSame(6, $summary['do_not_import']);
        $this->assertSame(94, $summary['total']);

        $this->assertSame(
            'import_then_migrate',
            $this->manifestEntry('module.ecs_cluster.aws_ecs_cluster.this')['classification']
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

    public function test_documentation_states_live_disabled_and_previously_hardcoded_enabled(): void
    {
        $section = $this->extractSection($this->stateAdoptionPlan(), '### 9\.14', '## 10\.');

        $this->assertMatchesRegularExpression('/live\s+`?containerInsights`?\s+is\s+(confirmed\s+)?`?disabled`?/i', $section);
        $this->assertMatchesRegularExpression('/previously\s+hardcoded\s+`?containerInsights\s*=\s*"enabled"`?/i', $section);
    }

    public function test_documentation_states_new_environment_default_remains_enabled(): void
    {
        $section = $this->extractSection($this->stateAdoptionPlan(), '### 9\.14', '## 10\.');

        $this->assertMatchesRegularExpression('/default\s+remains\s+`?"enabled"`?/i', $section);
    }

    public function test_documentation_states_import_does_not_authorize_enabling_later(): void
    {
        $section = $this->extractSection($this->stateAdoptionPlan(), '### 9\.14', '## 10\.');

        $this->assertMatchesRegularExpression('/does\s+\*\*not\*\*\s+authorize\s+enabling/i', $section);
        $this->assertMatchesRegularExpression('/separate\s+decision\s+requiring\s+its\s+own\s+explicit\s+cost\s+and\s+observability\s+review/i', $section);
    }

    public function test_documentation_states_cluster_and_capacity_providers_are_separate_resources(): void
    {
        $section = $this->extractSection($this->stateAdoptionPlan(), '### 9\.14', '## 10\.');

        $this->assertStringContainsString('module.ecs_cluster.aws_ecs_cluster_capacity_providers.this', $section);
        $this->assertMatchesRegularExpression('/different\s+Terraform\s+resource\s+address/i', $section);
        $this->assertMatchesRegularExpression('/later,?\s+independent\s+import/i', $section);
    }

    public function test_no_documentation_claims_the_cluster_currently_has_no_drift(): void
    {
        // §9.14 legitimately quotes the OLD, now-corrected claim as
        // historical narrative ("...was WRONG"). What must NOT exist
        // anywhere is an unqualified, standalone claim outside that
        // corrective context — i.e. the phrase must always appear paired
        // with "WRONG" nearby, never asserted bare.
        foreach ([$this->stateAdoptionPlan(), $this->variableInventory()] as $doc) {
            if (! str_contains($doc, 'no known drift')) {
                continue;
            }

            $pos = strpos($doc, 'no known drift');
            $nearby = substr($doc, max(0, $pos - 200), 260);
            $this->assertMatchesRegularExpression(
                '/wrong/i',
                $nearby,
                'Any mention of "no known drift" must appear only as part of the corrective narrative explaining the prior claim was wrong, never as a bare, current assertion.'
            );
        }
    }

    public function test_variable_inventory_records_the_correction(): void
    {
        $doc = $this->variableInventory();

        $this->assertMatchesRegularExpression('/containerInsights.{0,40}disabled/is', $doc);
        $this->assertStringContainsString('container_insights_enabled', $doc);
        $this->assertStringContainsString('ecs_container_insights_enabled', $doc);
    }
}
