<?php

declare(strict_types=1);

namespace Tests\Feature\Ecs;

use Tests\TestCase;

/**
 * Proves the IAM execution-role trust-policy/description correction (see
 * docs/ecs/state-adoption-plan.md §9.17): the shared ecs-tasks assume-role
 * trust policy now renders the confused-deputy conditions live actually
 * enforces, and aws_iam_role.task_execution now takes an explicit
 * description — against the real, committed files, never against a live
 * `terraform plan`/`apply`/`import` (no AWS contact, no credentials
 * needed, fully deterministic).
 *
 * data "aws_iam_policy_document" is fully mocked (fabricated json) under
 * Terraform's `mock_provider`, the same limitation this module's own
 * test comments already document (see
 * infrastructure/ecs/modules/iam/tests/naming.tftest.hcl) — so the
 * condition-block STRUCTURE is proven here via the committed source text,
 * while task_execution_trust_policy_and_description.tftest.hcl proves the
 * description's plain-variable wiring (not data-source-derived, so it CAN
 * be trusted under mock_provider).
 */
class StagingIamExecutionRoleTrustPolicyTest extends TestCase
{
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

    private function extractDataBlock(string $content, string $type, string $name): string
    {
        preg_match('/data "'.preg_quote($type, '/').'" "'.preg_quote($name, '/').'" \{.*?\n\}\n/s', $content, $matches);
        $this->assertNotEmpty($matches, "Could not locate data \"{$type}\" \"{$name}\".");

        return $matches[0];
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

    private function extractSection(string $doc, string $startPattern, string $endPattern): string
    {
        preg_match('/'.$startPattern.'.*?(?='.$endPattern.')/s', $doc, $matches);
        $this->assertNotEmpty($matches, "Could not locate section matching /{$startPattern}/.");

        return $matches[0];
    }

    // ------------------------------------------------------------
    // Trust-policy structure — proven via source text, not
    // mock_provider (see class docblock).
    // ------------------------------------------------------------

    public function test_shared_assume_role_document_has_the_ecs_tasks_principal_and_action(): void
    {
        $doc = $this->extractDataBlock($this->iamModuleMain(), 'aws_iam_policy_document', 'ecs_tasks_assume_role');

        $this->assertStringContainsString('actions = ["sts:AssumeRole"]', $doc);
        $this->assertStringContainsString('type        = "Service"', $doc);
        $this->assertStringContainsString('identifiers = ["ecs-tasks.amazonaws.com"]', $doc);
    }

    public function test_shared_assume_role_document_has_the_exact_source_account_condition(): void
    {
        $doc = $this->extractDataBlock($this->iamModuleMain(), 'aws_iam_policy_document', 'ecs_tasks_assume_role');

        // Order-independent: this condition block may appear before or
        // after the SourceArn one below without changing the rendered
        // policy's meaning — AWS's own JSON representation does not
        // guarantee condition-key ordering either.
        $this->assertMatchesRegularExpression(
            '/condition\s*\{\s*test\s*=\s*"StringEquals"\s*variable\s*=\s*"aws:SourceAccount"\s*values\s*=\s*\[var\.aws_account_id\]\s*\}/s',
            $doc
        );
    }

    public function test_shared_assume_role_document_has_the_exact_source_arn_condition(): void
    {
        $doc = $this->extractDataBlock($this->iamModuleMain(), 'aws_iam_policy_document', 'ecs_tasks_assume_role');

        $this->assertMatchesRegularExpression(
            '/condition\s*\{\s*test\s*=\s*"ArnLike"\s*variable\s*=\s*"aws:SourceArn"\s*values\s*=\s*\["arn:aws:ecs:\$\{var\.aws_region\}:\$\{var\.aws_account_id\}:\*"\]\s*\}/s',
            $doc
        );
    }

    public function test_only_one_assume_role_document_exists_shared_by_execution_and_task_roles(): void
    {
        $main = $this->iamModuleMain();

        // Scenario A from the Phase 2 live comparison: both
        // firmsbase-staging-ecs-execution-role and
        // firmsbase-staging-ecs-task-role carry the identical
        // SourceAccount/SourceArn conditions, so this must remain one
        // shared document — never a role-specific duplicate.
        preg_match_all('/data "aws_iam_policy_document" "ecs_tasks_assume_role"/', $main, $matches);
        $this->assertCount(1, $matches[0], 'Exactly one shared ecs_tasks_assume_role document must exist — not a role-specific duplicate.');

        $executionRole = $this->extractResourceBlock($main, 'aws_iam_role', 'task_execution');
        $this->assertStringContainsString('assume_role_policy = data.aws_iam_policy_document.ecs_tasks_assume_role.json', $executionRole);

        $taskRole = $this->extractResourceBlock($main, 'aws_iam_role', 'task');
        $this->assertStringContainsString('assume_role_policy = data.aws_iam_policy_document.ecs_tasks_assume_role.json', $taskRole);
    }

    public function test_aws_account_id_and_aws_region_are_required_module_inputs(): void
    {
        $vars = $this->iamModuleVariables();

        $accountVar = $this->extractVariableBlock($vars, 'aws_account_id');
        $this->assertDoesNotMatchRegularExpression('/^\s*default\s*=/m', $accountVar, 'aws_account_id must have no default — every caller must supply it explicitly.');

        $regionVar = $this->extractVariableBlock($vars, 'aws_region');
        $this->assertDoesNotMatchRegularExpression('/^\s*default\s*=/m', $regionVar, 'aws_region must have no default — every caller must supply it explicitly.');
    }

    // ------------------------------------------------------------
    // Description
    // ------------------------------------------------------------

    public function test_task_execution_role_description_is_a_required_nonempty_validated_module_input(): void
    {
        $var = $this->extractVariableBlock($this->iamModuleVariables(), 'task_execution_role_description');

        $this->assertDoesNotMatchRegularExpression('/^\s*default\s*=/m', $var, 'task_execution_role_description must have no default.');
        $this->assertStringContainsString('type        = string', $var);
        $this->assertStringContainsString('validation', $var);
        $this->assertStringContainsString('trimspace(var.task_execution_role_description)', $var);
    }

    public function test_description_is_wired_only_to_the_execution_role(): void
    {
        $main = $this->iamModuleMain();

        $executionRole = $this->extractResourceBlock($main, 'aws_iam_role', 'task_execution');
        $this->assertStringContainsString('description        = var.task_execution_role_description', $executionRole);

        // The generic task roles must NOT receive a description from this
        // variable — out of scope for this correction (see §9.17).
        $taskRole = $this->extractResourceBlock($main, 'aws_iam_role', 'task');
        $this->assertStringNotContainsString('task_execution_role_description', $taskRole);
    }

    public function test_staging_root_wires_the_confirmed_live_values(): void
    {
        $module = $this->extractSection($this->stagingMain(), 'module "iam" \{', 'module "alb"');

        $this->assertMatchesRegularExpression('/aws_account_id\s+= var\.aws_account_id/', $module);
        $this->assertMatchesRegularExpression('/aws_region\s+= var\.aws_region/', $module);
        $this->assertMatchesRegularExpression('/task_execution_role_description\s+= var\.iam_task_execution_role_description/', $module);

        $tfvars = $this->stagingTfvarsExample();
        $this->assertStringContainsString('aws_account_id = "603013471426"', $tfvars);
        $this->assertStringContainsString('iam_task_execution_role_description = "Execution role for FirmsBase staging ECS tasks"', $tfvars);
    }

    public function test_root_aws_account_id_validates_twelve_digits(): void
    {
        $var = $this->extractVariableBlock($this->stagingVariables(), 'aws_account_id');
        $this->assertStringContainsString('validation', $var);
        $this->assertStringContainsString('^[0-9]{12}$', $var);
    }

    // ------------------------------------------------------------
    // Resource address / policy separation invariants
    // ------------------------------------------------------------

    public function test_execution_role_resource_address_is_unchanged(): void
    {
        $main = $this->iamModuleMain();
        preg_match_all('/resource "aws_iam_role" "task_execution"/', $main, $matches);
        $this->assertCount(1, $matches[0], 'module.iam.aws_iam_role.task_execution resource address must remain exactly this — one declaration, unrenamed.');
    }

    public function test_exactly_one_managed_policy_attachment_resource_exists(): void
    {
        // As of the IAM execution-policy architecture correction (§9.18),
        // a managed-policy attachment IS now modeled — deliberately,
        // superseding the earlier trust-policy/description-only pass this
        // class was originally written for. Full coverage lives in
        // StagingIamExecutionPolicyArchitectureTest.php; this test only
        // guards against a second, duplicate attachment being introduced.
        $main = $this->iamModuleMain();
        preg_match_all('/resource "aws_iam_role_policy_attachment" "task_execution_managed"/', $main, $matches);
        $this->assertCount(1, $matches[0], 'Exactly one aws_iam_role_policy_attachment.task_execution_managed must exist.');
    }

    public function test_task_execution_inline_policy_is_now_secrets_only(): void
    {
        // As of §9.18, ECR/logs statements were removed from the inline
        // policy (superseded by the managed-policy attachment above) —
        // see StagingIamExecutionPolicyArchitectureTest.php for full
        // coverage of the corrected shape. The sid assertion below was
        // updated in §9.19: the statement's sid is no longer the hardcoded
        // "ReadTaskSecrets" literal (which never matched live) — it's now
        // the required task_execution_secrets_policy_sid variable.
        $main = $this->iamModuleMain();
        $execPolicyDoc = $this->extractDataBlock($main, 'aws_iam_policy_document', 'task_execution');

        $this->assertStringContainsString('sid       = var.task_execution_secrets_policy_sid', $execPolicyDoc);
        foreach (['EcrAuth', 'EcrPull', 'WriteLogs'] as $sid) {
            $this->assertStringNotContainsString($sid, $execPolicyDoc, "Inline policy statement \"{$sid}\" must no longer exist — those permissions now come from the managed-policy attachment (§9.18).");
        }

        $policyResource = $this->extractResourceBlock($main, 'aws_iam_role_policy', 'task_execution');
        $this->assertStringContainsString('policy = data.aws_iam_policy_document.task_execution.json', $policyResource);
    }

    public function test_manifest_confirms_managed_policy_removal_is_not_authorized(): void
    {
        $entry = $this->manifestEntry('module.iam.aws_iam_role.task_execution');
        $prereq = $entry['prerequisite'];

        $this->assertStringContainsString('does', $prereq);
        $this->assertMatchesRegularExpression('/not\s+authorize\s+detaching/i', $prereq);
        $this->assertStringContainsString('AmazonECSTaskExecutionRolePolicy', $prereq);
    }

    public function test_inline_policy_resource_moved_to_group_a_after_content_alignment(): void
    {
        // As of §9.18, the inline policy's CONTENT now matches live
        // exactly (see StagingIamExecutionPolicyArchitectureTest.php), so
        // it moved from Group C to Group A — superseding this class's
        // original expectation (written before that correction landed).
        $entry = $this->manifestEntry('module.iam.aws_iam_role_policy.task_execution');
        $this->assertStringContainsString('Group A', $entry['notes']);
        $this->assertSame('import_unchanged', $entry['classification']);
    }

    public function test_execution_role_manifest_notes_now_say_group_a(): void
    {
        // Superseded 2026-08-05 (§9.19): the notes now lead with
        // "IMPORTED 2026-08-05" (the role having since been imported as
        // its own canary mission), with "Group A" following — so this
        // checks containment, not a leading anchor.
        $entry = $this->manifestEntry('module.iam.aws_iam_role.task_execution');
        $this->assertStringContainsString('Group A', $entry['notes']);
        $this->assertStringContainsString('IMPORTED 2026-08-05', $entry['notes']);
    }

    public function test_new_variables_introduce_no_secret_or_credential(): void
    {
        foreach (['aws_account_id', 'aws_region', 'task_execution_role_description'] as $name) {
            $var = $this->extractVariableBlock($this->iamModuleVariables(), $name);
            $this->assertStringNotContainsString('sensitive', $var, "{$name} must not be marked sensitive — it carries no secret value.");
            $this->assertStringNotContainsString('secretsmanager', strtolower($var));
        }
    }

    // ------------------------------------------------------------
    // Docs
    // ------------------------------------------------------------

    public function test_state_adoption_plan_documents_the_correction(): void
    {
        $section = $this->extractSection($this->stateAdoptionPlan(), '### 9\.17', '## 10\.');

        $this->assertStringContainsString('firmsbase-staging-ecs-execution-role', $section);
        $this->assertStringContainsString('firmsbase-staging-ecs-task-role', $section);
        $this->assertStringContainsString('aws:SourceAccount', $section);
        $this->assertStringContainsString('aws:SourceArn', $section);
        $this->assertStringContainsString('603013471426', $section);
        $this->assertMatchesRegularExpression('/not[\s*]+authorize[\s*]+detaching/i', $section);
        $this->assertStringContainsString('module.iam.aws_iam_role_policy.task_execution', $section);
    }

    public function test_variable_inventory_documents_the_correction(): void
    {
        $doc = $this->variableInventory();
        $this->assertStringContainsString('firmsbase-staging-ecs-task-role', $doc);
        $this->assertStringContainsString('aws_account_id', $doc);
        $this->assertStringContainsString('task_execution_role_description', $doc);
    }
}
