<?php

declare(strict_types=1);

namespace Tests\Feature\Ecs;

use Tests\TestCase;

/**
 * Proves the corrected secret-ARN/valueFrom design and the new ElastiCache
 * engine_version wiring against the real, committed Terraform files — never
 * against a live `terraform plan`/`apply`/`import` (no AWS contact, no
 * credentials needed, fully deterministic), mirroring this repo's existing
 * SesConsumerTerraformIamTest/AlbTargetGroupAdoptionTest philosophy of
 * reading real committed files directly. `terraform test` (run separately,
 * not by PHPUnit) proves the same logic actually evaluates correctly inside
 * Terraform's own expression engine; these tests prove the specific
 * bare-ARN/JSON-key-selector and engine_version properties this correction
 * requires. See docs/ecs/staging-variable-inventory.md.
 */
class StagingSecretArnDerivationTest extends TestCase
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

    private function elasticacheModuleOutputs(): string
    {
        return $this->readFile('infrastructure/ecs/modules/elasticache/outputs.tf');
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
    // IAM gets bare ARNs only (items 2, 3, 6)
    // ------------------------------------------------------------

    public function test_module_iam_secret_arns_are_the_bare_variables_with_no_json_key_selector(): void
    {
        preg_match('/module "iam" \{.*?\n}/s', $this->stagingMain(), $matches);
        $this->assertNotEmpty($matches, 'Could not locate module "iam" block.');
        $block = $matches[0];

        preg_match('/secret_arns\s*=\s*\[(.*?)\]/s', $block, $secretArnsMatch);
        $this->assertNotEmpty($secretArnsMatch, 'Could not locate the secret_arns list passed to module.iam.');
        $list = $secretArnsMatch[1];

        foreach ([
            'var.app_key_secret_arn',
            'var.db_password_secret_arn',
            'var.redis_auth_token_secret_arn',
            'var.platform_notifications_recipient_fingerprint_hmac_key_secret_arn',
        ] as $bareVar) {
            $this->assertStringContainsString($bareVar, $list, "module.iam's secret_arns must include {$bareVar}.");
        }

        // The whole point: these are bare variable references, never
        // string-interpolated with a JSON-key selector appended.
        $this->assertDoesNotMatchRegularExpression('/\$\{/', $list, 'module.iam.secret_arns must contain plain variable references only — no string interpolation (which would be how a JSON-key selector could sneak in).');
        $this->assertDoesNotMatchRegularExpression('/::/', $list, 'module.iam.secret_arns must never contain a "::" JSON-key selector sequence — IAM Resource entries must be bare secret ARNs.');
    }

    public function test_secret_arn_variable_descriptions_document_the_bare_arn_contract(): void
    {
        $vars = $this->stagingVariables();

        foreach (['app_key_secret_arn', 'db_password_secret_arn', 'redis_auth_token_secret_arn'] as $name) {
            $block = $this->extractVariableBlock($vars, $name);
            $this->assertStringContainsString('description', $block, "variable \"{$name}\" must document the bare-ARN contract.");
            $this->assertStringContainsString('Bare ARN', $block, "variable \"{$name}\" description must state it expects a bare ARN.");
        }
    }

    // ------------------------------------------------------------
    // ECS receives the JSON-key selector, derived automatically (items 4, 5)
    // ------------------------------------------------------------

    public function test_shared_secrets_derives_the_exact_live_json_key_for_each_secret(): void
    {
        preg_match('/shared_secrets\s*=\s*\{(.*?)\n  }/s', $this->stagingMain(), $matches);
        $this->assertNotEmpty($matches, 'Could not locate local.shared_secrets.');
        $block = $matches[0];

        $this->assertMatchesRegularExpression(
            '/APP_KEY\s*=\s*"\$\{var\.app_key_secret_arn\}:APP_KEY::"/',
            $block,
            'APP_KEY must derive its valueFrom as "${var.app_key_secret_arn}:APP_KEY::" — preserving the live JSON key.'
        );
        $this->assertMatchesRegularExpression(
            '/DB_PASSWORD\s*=\s*"\$\{var\.db_password_secret_arn\}:password::"/',
            $block,
            'DB_PASSWORD must derive its valueFrom as "${var.db_password_secret_arn}:password::" — preserving the live JSON key.'
        );
        $this->assertMatchesRegularExpression(
            '/REDIS_PASSWORD\s*=\s*"\$\{var\.redis_auth_token_secret_arn\}:REDIS_PASSWORD::"/',
            $block,
            'REDIS_PASSWORD must derive its valueFrom as "${var.redis_auth_token_secret_arn}:REDIS_PASSWORD::" — preserving the live JSON key.'
        );
    }

    public function test_ecs_service_module_passes_the_secrets_map_value_through_verbatim_as_valuefrom(): void
    {
        // Confirms the premise the derivation above relies on: the shared
        // ecs_service module does not itself append or strip anything —
        // whatever string main.tf computes is exactly what ECS receives.
        $content = $this->readFile('infrastructure/ecs/modules/ecs_service/main.tf');

        $this->assertMatchesRegularExpression(
            '/secrets\s*=\s*\[\s*for\s+k,\s*v\s+in\s+var\.secrets\s*:\s*\{\s*name\s*=\s*k,\s*valueFrom\s*=\s*v\s*\}\s*\]/s',
            $content,
            'The ecs_service module must pass each var.secrets map value through unchanged as valueFrom.'
        );
    }

    // ------------------------------------------------------------
    // ElastiCache engine_version wiring (item 1)
    // ------------------------------------------------------------

    public function test_staging_declares_elasticache_engine_version_with_a_default_preserving_module_behavior(): void
    {
        $block = $this->extractVariableBlock($this->stagingVariables(), 'elasticache_engine_version');

        $this->assertStringContainsString('type        = string', $block);
        $this->assertMatchesRegularExpression(
            '/default\s*=\s*"7\.1"/',
            $block,
            'elasticache_engine_version must default to "7.1" — the elasticache module\'s own original default — so a brand-new environment is unaffected.'
        );
    }

    public function test_staging_wires_elasticache_engine_version_into_the_elasticache_module_call(): void
    {
        preg_match('/module "elasticache" \{.*?\n}/s', $this->stagingMain(), $matches);
        $this->assertNotEmpty($matches, 'Could not locate module "elasticache" block.');

        $this->assertMatchesRegularExpression(
            '/engine_version\s*=\s*var\.elasticache_engine_version/',
            $matches[0],
            'module.elasticache must receive engine_version = var.elasticache_engine_version.'
        );
    }

    public function test_elasticache_module_exposes_an_engine_version_output(): void
    {
        $this->assertMatchesRegularExpression(
            '/output "engine_version" \{\s*value\s*=\s*aws_elasticache_replication_group\.this\.engine_version\s*\}/s',
            $this->elasticacheModuleOutputs(),
            'The elasticache module must expose an engine_version output so callers/tests can observe the resolved value.'
        );
    }

    public function test_example_tfvars_documents_the_confirmed_live_7_2_6_version_and_supplies_the_valid_7_2_value(): void
    {
        $tfvars = $this->stagingTfvarsExample();

        // AWS's aws_elasticache_replication_group rejects a major.minor.patch
        // value for Redis v6+/Valkey (confirmed via a real provider
        // validation error while adding this test coverage), so the actual
        // assigned value must be "7.2" — but the exact confirmed live
        // version (7.2.6) must still be documented in a comment for anyone
        // reading this file.
        $this->assertMatchesRegularExpression(
            '/elasticache_engine_version\s*=\s*"7\.2"/',
            $tfvars,
            'terraform.tfvars.example must set elasticache_engine_version to "7.2" — AWS rejects "7.2.6" for this resource.'
        );
        $this->assertStringContainsString(
            '7.2.6',
            $tfvars,
            'terraform.tfvars.example must still document the exact confirmed live reported version (7.2.6), even though the value actually supplied is "7.2".'
        );
    }

    // ------------------------------------------------------------
    // No credential or secret value introduced
    // ------------------------------------------------------------

    public function test_no_credential_or_secret_value_was_introduced(): void
    {
        foreach ([
            'infrastructure/ecs/environments/staging/main.tf',
            'infrastructure/ecs/environments/staging/variables.tf',
            'infrastructure/ecs/environments/staging/terraform.tfvars.example',
        ] as $path) {
            $content = $this->readFile($path);
            $this->assertDoesNotMatchRegularExpression('/AKIA[0-9A-Z]{16}/', $content, "{$path} must not contain an AWS access key ID.");
            $this->assertStringNotContainsString('-----BEGIN', $content, "{$path} must not contain PEM-encoded credential material.");
        }
    }
}
