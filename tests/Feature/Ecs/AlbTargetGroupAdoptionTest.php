<?php

declare(strict_types=1);

namespace Tests\Feature\Ecs;

use Tests\TestCase;

/**
 * Proves the ALB target-group health-check adoption correction (matcher
 * variable added, staging-level path/interval/matcher overrides wired,
 * import-manifest.json reclassified, docs updated) against the real,
 * committed files — never against a live `terraform plan`/`apply`/`import`
 * (no AWS contact, no credentials needed, fully deterministic), mirroring
 * this repo's existing SesConsumerTerraformIamTest philosophy of reading
 * real committed files directly. `terraform validate`/`fmt`/`test` (run
 * separately, not by PHPUnit) prove the HCL itself is valid; these tests
 * prove the specific adoption-classification properties this correction
 * requires. See docs/ecs/state-adoption-plan.md §9.5.
 */
class AlbTargetGroupAdoptionTest extends TestCase
{
    private function albModuleMain(): string
    {
        return $this->readFile('infrastructure/ecs/modules/alb/main.tf');
    }

    private function albModuleVariables(): string
    {
        return $this->readFile('infrastructure/ecs/modules/alb/variables.tf');
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

    private function importManifest(): array
    {
        $path = base_path('infrastructure/ecs/environments/staging/import-manifest.json');
        $contents = file_get_contents($path);
        $this->assertNotFalse($contents, 'Failed to read import-manifest.json');

        $decoded = json_decode($contents, true);
        $this->assertIsArray($decoded, 'import-manifest.json did not decode to an array');

        return $decoded;
    }

    private function readFile(string $relativePath): string
    {
        $path = base_path($relativePath);
        $contents = file_get_contents($path);
        $this->assertNotFalse($contents, "Failed to read {$relativePath}");

        return $contents;
    }

    // ------------------------------------------------------------
    // ALB module: matcher is a real variable, not a hardcoded literal
    // ------------------------------------------------------------

    public function test_the_alb_module_matcher_uses_the_health_check_matcher_variable(): void
    {
        preg_match('/resource "aws_lb_target_group" "web".*?\n}/s', $this->albModuleMain(), $matches);
        $this->assertNotEmpty($matches, 'Could not locate the aws_lb_target_group.web resource block.');

        $this->assertMatchesRegularExpression(
            '/matcher\s*=\s*var\.health_check_matcher/',
            $matches[0],
            'matcher must be wired to var.health_check_matcher, not a hardcoded literal.'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/matcher\s*=\s*"200"/',
            $matches[0],
            'The old hardcoded matcher = "200" literal must be gone.'
        );
    }

    public function test_the_alb_module_health_check_matcher_variable_defaults_to_200(): void
    {
        $block = $this->extractVariableBlock($this->albModuleVariables(), 'health_check_matcher');

        $this->assertStringContainsString('type        = string', $block);
        $this->assertMatchesRegularExpression(
            '/default\s*=\s*"200"/',
            $block,
            'health_check_matcher must default to "200" — the module\'s original design — so a brand-new environment is unaffected.'
        );
        $this->assertStringContainsString('validation {', $block);
    }

    // ------------------------------------------------------------
    // Staging: all three target-group variables wired through
    // ------------------------------------------------------------

    public function test_staging_wires_all_three_target_group_variables_into_the_alb_module_call(): void
    {
        preg_match('/module "alb" \{.*?\n}/s', $this->stagingMain(), $matches);
        $this->assertNotEmpty($matches, 'Could not locate module "alb" block in staging main.tf.');
        $block = $matches[0];

        $this->assertMatchesRegularExpression('/readiness_health_check_path\s*=\s*var\.alb_health_check_path/', $block);
        $this->assertMatchesRegularExpression('/health_check_interval_seconds\s*=\s*var\.alb_health_check_interval_seconds/', $block);
        $this->assertMatchesRegularExpression('/health_check_matcher\s*=\s*var\.alb_health_check_matcher/', $block);
    }

    public function test_staging_target_group_variable_defaults_remain_the_original_design_values(): void
    {
        $pathBlock = $this->extractVariableBlock($this->stagingVariables(), 'alb_health_check_path');
        $this->assertMatchesRegularExpression('/default\s*=\s*"\/readyz"/', $pathBlock);

        $intervalBlock = $this->extractVariableBlock($this->stagingVariables(), 'alb_health_check_interval_seconds');
        $this->assertMatchesRegularExpression('/default\s*=\s*15/', $intervalBlock);

        $matcherBlock = $this->extractVariableBlock($this->stagingVariables(), 'alb_health_check_matcher');
        $this->assertMatchesRegularExpression('/default\s*=\s*"200"/', $matcherBlock);
        $this->assertStringContainsString('validation {', $matcherBlock);
    }

    public function test_example_tfvars_declares_the_exact_live_compatible_adoption_values(): void
    {
        $tfvars = $this->stagingTfvarsExample();

        $this->assertMatchesRegularExpression('/alb_health_check_path\s*=\s*"\/up"/', $tfvars);
        $this->assertMatchesRegularExpression('/alb_health_check_interval_seconds\s*=\s*30/', $tfvars);
        $this->assertMatchesRegularExpression('/alb_health_check_matcher\s*=\s*"200-399"/', $tfvars);
    }

    // ------------------------------------------------------------
    // import-manifest.json: reclassified, totals updated
    // ------------------------------------------------------------

    public function test_manifest_classifies_the_target_group_as_import_then_migrate(): void
    {
        $manifest = $this->importManifest();

        $entry = collect($manifest['resources'])->firstWhere('address', 'module.alb.aws_lb_target_group.web');
        $this->assertNotNull($entry, 'Could not find module.alb.aws_lb_target_group.web in import-manifest.json.');

        $this->assertSame('import_then_migrate', $entry['classification']);
        $this->assertSame(
            'arn:aws:elasticloadbalancing:us-east-1:603013471426:targetgroup/firmsbase-staging-tg/1830c01b9aaac37d',
            $entry['import_id'],
            'The import ARN must be preserved exactly, unchanged by the reclassification.'
        );
        $this->assertNotNull($entry['prerequisite'], 'A reclassified import_then_migrate entry must document its blocking prerequisite.');

        foreach (['path', 'interval', 'matcher'] as $mismatch) {
            $this->assertStringContainsString(
                $mismatch,
                strtolower($entry['notes']),
                "The target group's manifest notes must mention the {$mismatch} mismatch, not just the previously-documented path."
            );
        }
    }

    public function test_manifest_summary_totals_are_exactly_66_6_16_6_94(): void
    {
        $manifest = $this->importManifest();
        $summary = $manifest['summary'];

        // import_unchanged/import_then_migrate shifted from 10/12 to 6/16
        // on 2026-08-04 when four aws_security_group_rule addresses were
        // reclassified for description drift — see
        // docs/ecs/state-adoption-plan.md §9.11. This target group's own
        // classification (import_then_migrate) and totals-sum invariant are
        // unaffected by that correction.
        $this->assertSame(66, $summary['new']);
        $this->assertSame(8, $summary['import_unchanged']);
        $this->assertSame(15, $summary['import_then_migrate']);
        $this->assertSame(6, $summary['do_not_import']);
        $this->assertSame(95, $summary['total']);
    }

    public function test_manifest_no_credential_or_secret_value_is_present(): void
    {
        $raw = file_get_contents(base_path('infrastructure/ecs/environments/staging/import-manifest.json'));
        $this->assertNotFalse($raw);

        $this->assertDoesNotMatchRegularExpression('/AKIA[0-9A-Z]{16}/', $raw, 'No AWS access key ID may appear in the manifest.');
        $this->assertStringNotContainsString('-----BEGIN', $raw, 'No PEM-encoded credential material may appear in the manifest.');
    }

    // ------------------------------------------------------------
    // docs/ecs/state-adoption-plan.md: Phase A2/A3 corrected
    // ------------------------------------------------------------

    public function test_phase_a2_documentation_no_longer_includes_the_target_group_import(): void
    {
        $doc = $this->stateAdoptionPlan();

        preg_match('/### Phase A2.*?(?=### Phase A3)/s', $doc, $matches);
        $this->assertNotEmpty($matches, 'Could not locate the Phase A2 section.');
        $phaseA2 = $matches[0];

        // Phase A2 shrank from 10 to 6 addresses on 2026-08-04 when four
        // aws_security_group_rule addresses were reclassified for
        // description drift — see docs/ecs/state-adoption-plan.md §9.11.
        $this->assertStringContainsString('6 addresses', $phaseA2, 'Phase A2 heading must declare 6 addresses.');

        preg_match('/```bash(.*?)```/s', $phaseA2, $codeBlock);
        $this->assertNotEmpty($codeBlock, 'Could not locate the Phase A2 import command block.');
        $this->assertStringNotContainsString(
            'aws_lb_target_group.web',
            $codeBlock[1],
            'Phase A2\'s import commands must no longer include the target group — it now belongs to Phase A3.'
        );
    }

    public function test_phase_a3_documentation_records_all_three_mismatches_and_includes_the_target_group(): void
    {
        $doc = $this->stateAdoptionPlan();

        preg_match('/### Phase A3.*?(?=### Phase B)/s', $doc, $matches);
        $this->assertNotEmpty($matches, 'Could not locate the Phase A3 section.');
        $phaseA3 = $matches[0];

        // Phase A3 grew from 12 to 16 addresses on 2026-08-04 for the same
        // reclassification — see docs/ecs/state-adoption-plan.md §9.11.
        $this->assertStringContainsString('16 addresses', $phaseA3, 'Phase A3 heading must declare 16 addresses.');
        $this->assertStringContainsString('module.alb.aws_lb_target_group.web', $phaseA3);
        $this->assertStringContainsString('alb_health_check_path', $phaseA3);
        $this->assertStringContainsString('alb_health_check_interval_seconds', $phaseA3);
        $this->assertStringContainsString('alb_health_check_matcher', $phaseA3);
        $this->assertStringContainsString('BLOCKED', $phaseA3);
    }

    public function test_section_9_5_covers_path_interval_and_matcher_not_only_path(): void
    {
        $doc = $this->stateAdoptionPlan();

        preg_match('/### 9\.5.*?(?=### 9\.6)/s', $doc, $matches);
        $this->assertNotEmpty($matches, 'Could not locate §9.5.');
        $section = $matches[0];

        $this->assertStringContainsString('/up', $section);
        $this->assertStringContainsString('/readyz', $section);
        $this->assertMatchesRegularExpression('/\b30\b/', $section);
        $this->assertMatchesRegularExpression('/\b15\b/', $section);
        $this->assertStringContainsString('200-399', $section);
    }

    public function test_section_9_5_does_not_claim_provider_schema_verification(): void
    {
        $doc = $this->stateAdoptionPlan();

        preg_match('/### 9\.5.*?(?=### 9\.6)/s', $doc, $matches);
        $this->assertNotEmpty($matches);
        $section = $matches[0];

        $this->assertStringContainsString(
            'not re-verified live',
            $section,
            'The doc must explicitly disclaim live provider-schema verification, not silently omit the caveat.'
        );
        $this->assertStringNotContainsString(
            'confirmed via `terraform providers schema`',
            $section,
            'The doc must never claim replacement behavior was confirmed via a live terraform providers schema read — that command was never authorized or run against the real backend in this pass.'
        );
    }

    private function extractVariableBlock(string $content, string $name): string
    {
        preg_match('/variable "'.preg_quote($name, '/').'" \{.*?\n}/s', $content, $matches);
        $this->assertNotEmpty($matches, "Could not locate variable \"{$name}\".");

        return $matches[0];
    }
}
