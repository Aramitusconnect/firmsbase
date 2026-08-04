<?php

declare(strict_types=1);

namespace Tests\Feature\Ecs;

use Tests\TestCase;

/**
 * Proves the previously-unmodeled staging APP_URL is now a real,
 * HTTPS-validated Terraform input reaching every ECS role, against the
 * real, committed files — never against a live `terraform plan`/`apply`
 * (no AWS contact, no credentials needed, fully deterministic), mirroring
 * this repo's existing StagingSecretArnDerivationTest/AlbTargetGroupAdoptionTest
 * philosophy of reading real committed files directly. `terraform test`
 * (run separately, not by PHPUnit) proves the same logic actually
 * evaluates correctly inside Terraform's own expression engine. See
 * docs/ecs/state-adoption-plan.md §9.8 and
 * docs/ecs/staging-variable-inventory.md.
 */
class StagingAppUrlTest extends TestCase
{
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

    private function stagingVariableInventory(): string
    {
        return $this->readFile('docs/ecs/staging-variable-inventory.md');
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

    private function extractVariableBlock(string $content, string $name): string
    {
        preg_match('/variable "'.preg_quote($name, '/').'" \{.*?\n}/s', $content, $matches);
        $this->assertNotEmpty($matches, "Could not locate variable \"{$name}\".");

        return $matches[0];
    }

    // ------------------------------------------------------------
    // app_url is declared, no default, HTTPS-validated
    // ------------------------------------------------------------

    public function test_app_url_is_declared_as_a_required_string_with_no_default(): void
    {
        $block = $this->extractVariableBlock($this->stagingVariables(), 'app_url');

        $this->assertStringContainsString('type        = string', $block);
        $this->assertDoesNotMatchRegularExpression(
            '/\bdefault\s*=/',
            $block,
            'app_url must have no default — every environment must supply a real staging URL explicitly, so a forgotten call site fails validate/plan instead of silently defaulting.'
        );
    }

    public function test_app_url_has_an_https_and_no_trailing_slash_validation(): void
    {
        $block = $this->extractVariableBlock($this->stagingVariables(), 'app_url');

        $this->assertStringContainsString('validation {', $block);
        $this->assertMatchesRegularExpression('/regex\("\^https:\/\//', $block, 'app_url validation must require an https:// prefix.');
        $this->assertStringContainsString('endswith(var.app_url, "/")', $block, 'app_url validation must reject a trailing slash.');
    }

    // ------------------------------------------------------------
    // shared_environment wiring — reaches every role
    // ------------------------------------------------------------

    public function test_shared_environment_includes_app_url_from_the_variable(): void
    {
        preg_match('/shared_environment\s*=\s*\{.*?\n  }/s', $this->stagingMain(), $matches);
        $this->assertNotEmpty($matches, 'Could not locate local.shared_environment.');

        $this->assertMatchesRegularExpression(
            '/APP_URL\s*=\s*var\.app_url/',
            $matches[0],
            'local.shared_environment must include APP_URL = var.app_url.'
        );
    }

    public function test_every_role_consuming_shared_environment_receives_app_url(): void
    {
        $main = $this->stagingMain();

        foreach (['web', 'worker', 'critical_worker', 'scheduler', 'migrate', 'maintenance', 'ses_consumer'] as $role) {
            preg_match('/module "'.$role.'" \{.*?\n}/s', $main, $block);
            $this->assertNotEmpty($block, "Could not locate module \"{$role}\" block.");
            $this->assertMatchesRegularExpression(
                '/environment\s*=\s*(local\.shared_environment|merge\(local\.shared_environment,)/',
                $block[0],
                "module \"{$role}\" must consume local.shared_environment (directly or via merge()) so it receives APP_URL — no role is exempt."
            );
        }
    }

    // ------------------------------------------------------------
    // Documentation
    // ------------------------------------------------------------

    public function test_example_tfvars_records_the_exact_live_staging_url(): void
    {
        $this->assertMatchesRegularExpression(
            '/app_url\s*=\s*"https:\/\/staging\.firmsvault\.com"/',
            $this->stagingTfvarsExample(),
            'terraform.tfvars.example must record the confirmed live app_url value.'
        );
    }

    public function test_variable_inventory_no_longer_calls_app_url_unmodeled(): void
    {
        $doc = $this->stagingVariableInventory();

        $this->assertStringContainsString('app_url', $doc);
        $this->assertStringContainsString('https://staging.firmsvault.com', $doc);
        $this->assertStringContainsString('now modeled', $doc);
    }

    public function test_state_adoption_plan_documents_app_url_as_corrected(): void
    {
        $doc = $this->stateAdoptionPlan();

        preg_match('/### 9\.8.*?(?=## 10\.)/s', $doc, $matches);
        $this->assertNotEmpty($matches, 'Could not locate §9.8 (APP_URL).');
        $section = $matches[0];

        $this->assertStringContainsString('https://staging.firmsvault.com', $section);
        $this->assertStringContainsString('var.app_url', $section);
    }
}
