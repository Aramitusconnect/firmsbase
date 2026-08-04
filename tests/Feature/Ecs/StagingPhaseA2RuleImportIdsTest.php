<?php

declare(strict_types=1);

namespace Tests\Feature\Ecs;

use Tests\TestCase;

/**
 * Proves the Phase A2 security-group-rule import-ID resolution correction
 * (import-manifest.json's four aws_security_group_rule entries moved from
 * import_id: "BLOCKED" to a resolved Terraform composite import ID, with
 * docs updated to match) against the real, committed files — never against
 * a live `terraform plan`/`apply`/`import` (no AWS contact, no credentials
 * needed, fully deterministic), mirroring this repo's
 * AlbTargetGroupAdoptionTest/SesConsumerTerraformIamTest philosophy of
 * reading real committed files directly. See
 * docs/ecs/state-adoption-plan.md §9.10.
 */
class StagingPhaseA2RuleImportIdsTest extends TestCase
{
    private const RULE_ADDRESSES = [
        'module.security_groups.aws_security_group_rule.alb_ingress_https' => [
            'import_id' => 'sg-02a26ff122a9a1d29_ingress_tcp_443_443_0.0.0.0/0',
            'sgr_id' => 'sgr-0c01cb5ed9c2ade63',
        ],
        'module.security_groups.aws_security_group_rule.ecs_tasks_ingress_from_alb' => [
            'import_id' => 'sg-0db14e50ea5c5466c_ingress_tcp_8080_8080_sg-02a26ff122a9a1d29',
            'sgr_id' => 'sgr-0d10f5fbc9e17c912',
        ],
        'module.security_groups.aws_security_group_rule.rds_ingress_from_ecs_tasks[0]' => [
            'import_id' => 'sg-0d4c5eedb2ee21743_ingress_tcp_5432_5432_sg-0db14e50ea5c5466c',
            'sgr_id' => 'sgr-00039246ff540e217',
        ],
        'module.elasticache.aws_security_group_rule.redis_ingress_from_ecs_tasks' => [
            'import_id' => 'sg-0da3ea50262a9d20d_ingress_tcp_6379_6379_sg-0db14e50ea5c5466c',
            'sgr_id' => 'sgr-0d4fcba591950afde',
        ],
    ];

    private const COMPOSITE_ID_PATTERN =
        '/^sg-[0-9a-f]+_(ingress|egress)_[a-z0-9-]+_-?\d+_-?\d+_(sg-[0-9a-f]+|\d{1,3}(\.\d{1,3}){3}\/\d{1,2})$/';

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

    private function stateAdoptionPlan(): string
    {
        return $this->readFile('docs/ecs/state-adoption-plan.md');
    }

    private function variableInventory(): string
    {
        return $this->readFile('docs/ecs/staging-variable-inventory.md');
    }

    private function readFile(string $relativePath): string
    {
        $path = base_path($relativePath);
        $contents = file_get_contents($path);
        $this->assertNotFalse($contents, "Failed to read {$relativePath}");

        return $contents;
    }

    // ------------------------------------------------------------
    // import-manifest.json: no longer BLOCKED, composite structure correct
    // ------------------------------------------------------------

    public function test_none_of_the_four_rule_addresses_remains_blocked(): void
    {
        foreach (self::RULE_ADDRESSES as $address => $expected) {
            $entry = $this->manifestEntry($address);

            $this->assertNotSame(
                'BLOCKED',
                $entry['import_id'],
                "{$address} must no longer be marked import_id: \"BLOCKED\"."
            );
        }
    }

    public function test_each_import_id_has_the_expected_composite_structure(): void
    {
        foreach (self::RULE_ADDRESSES as $address => $expected) {
            $entry = $this->manifestEntry($address);

            $this->assertSame($expected['import_id'], $entry['import_id']);
            $this->assertMatchesRegularExpression(
                self::COMPOSITE_ID_PATTERN,
                $entry['import_id'],
                "{$address}'s import_id must follow the <sg-id>_<type>_<protocol>_<from>_<to>_<source> composite format."
            );

            // The AWS-internal SecurityGroupRuleId must never be substituted
            // in as the import_id itself — it is a distinct identifier.
            $this->assertDoesNotMatchRegularExpression(
                '/^sgr-/',
                $entry['import_id'],
                "{$address}'s import_id must be the Terraform composite ID, not the raw AWS sgr-* identifier."
            );
        }
    }

    public function test_each_manifest_note_records_an_sgr_identifier_in_live_reference(): void
    {
        foreach (self::RULE_ADDRESSES as $address => $expected) {
            $entry = $this->manifestEntry($address);

            $this->assertSame(
                $expected['sgr_id'],
                $entry['live_reference'],
                "{$address}'s live_reference must record its exact AWS SecurityGroupRuleId."
            );
            $this->assertStringContainsString(
                $expected['sgr_id'],
                $entry['notes'],
                "{$address}'s notes must mention its AWS sgr-* ID for audit traceability."
            );
            $this->assertStringContainsString(
                'exactly one',
                strtolower($entry['notes']),
                "{$address}'s notes must confirm exactly one live rule matched."
            );
        }
    }

    public function test_classifications_are_unchanged_import_unchanged(): void
    {
        foreach (self::RULE_ADDRESSES as $address => $expected) {
            $entry = $this->manifestEntry($address);

            $this->assertSame(
                'import_unchanged',
                $entry['classification'],
                "{$address}'s classification must be preserved as import_unchanged."
            );
        }
    }

    public function test_manifest_summary_totals_are_exactly_66_10_12_6_94(): void
    {
        $manifest = $this->importManifest();
        $summary = $manifest['summary'];

        $this->assertSame(66, $summary['new']);
        $this->assertSame(10, $summary['import_unchanged']);
        $this->assertSame(12, $summary['import_then_migrate']);
        $this->assertSame(6, $summary['do_not_import']);
        $this->assertSame(94, $summary['total']);
    }

    public function test_manifest_no_credential_or_secret_value_is_present(): void
    {
        $raw = file_get_contents(base_path('infrastructure/ecs/environments/staging/import-manifest.json'));
        $this->assertNotFalse($raw);

        $this->assertDoesNotMatchRegularExpression('/AKIA[0-9A-Z]{16}/', $raw, 'No AWS access key ID may appear in the manifest.');
        $this->assertStringNotContainsString('-----BEGIN', $raw, 'No PEM-encoded credential material may appear in the manifest.');
        $this->assertStringNotContainsString('REDIS_PASSWORD', $raw, 'The manifest must never reference the Redis secret value.');
    }

    // ------------------------------------------------------------
    // Documentation: distinguishes sgr-* from the composite ID, records
    // current execution status accurately
    // ------------------------------------------------------------

    public function test_documentation_distinguishes_aws_rule_ids_from_terraform_import_ids(): void
    {
        $doc = $this->stateAdoptionPlan();

        preg_match('/### Phase A2.*?(?=### Phase A3)/s', $doc, $matches);
        $this->assertNotEmpty($matches, 'Could not locate the Phase A2 section.');
        $phaseA2 = $matches[0];

        $this->assertStringContainsString('SecurityGroupRuleId', $phaseA2);
        $this->assertStringContainsString(
            'composite',
            strtolower($phaseA2),
            'Phase A2 must explain that aws_security_group_rule uses a composite import identifier.'
        );

        foreach (self::RULE_ADDRESSES as $address => $expected) {
            $this->assertStringContainsString($expected['sgr_id'], $phaseA2, "Phase A2 must record {$expected['sgr_id']} separately from its composite import ID.");
            $this->assertStringContainsString($expected['import_id'], $phaseA2, "Phase A2 must record the resolved composite import ID for {$address}.");
        }
    }

    public function test_documentation_no_longer_calls_the_four_rules_blocked_in_phase_a2(): void
    {
        $doc = $this->stateAdoptionPlan();

        preg_match('/### Phase A2.*?(?=### Phase A3)/s', $doc, $matches);
        $this->assertNotEmpty($matches);
        $phaseA2 = $matches[0];

        // The word "BLOCKED" may still appear in an explanatory sentence
        // confirming the correction (e.g. "none remain \"BLOCKED\""), but
        // the four rule addresses must not be labeled/classified BLOCKED,
        // and the old stale claim that 4/10 are marked import_id: "BLOCKED"
        // must be gone.
        $this->assertStringNotContainsString(
            'are marked `import_id: "BLOCKED"`',
            $phaseA2,
            'The stale claim that 4 of the 10 addresses are marked import_id: "BLOCKED" must be removed now that they are resolved.'
        );
        $this->assertStringContainsString(
            'none remain',
            strtolower($phaseA2),
            'Phase A2 must explicitly confirm none of the 10 addresses remain BLOCKED.'
        );

        foreach (self::RULE_ADDRESSES as $address => $expected) {
            // Locate the address's own mention and confirm it is not
            // immediately tagged BLOCKED there.
            $pos = strpos($phaseA2, $address);
            $this->assertNotFalse($pos, "{$address} must still be mentioned in Phase A2.");
            $nearby = substr($phaseA2, max(0, $pos - 80), 160);
            $this->assertStringNotContainsString(
                'BLOCKED',
                $nearby,
                "{$address} must not be labeled BLOCKED near its own mention now that its import ID is resolved."
            );
        }
    }

    public function test_documentation_states_six_managed_resources_are_already_imported(): void
    {
        $doc = $this->stateAdoptionPlan();

        $this->assertStringContainsString('already imported', $doc);
        $this->assertMatchesRegularExpression(
            '/[Ss]ix (already[- ]imported|of Phase A2)/',
            $doc,
            'Documentation must explicitly state that six Phase A2 resources are already imported.'
        );
    }

    public function test_documentation_states_four_rule_imports_remain_pending(): void
    {
        $doc = $this->stateAdoptionPlan();

        $this->assertMatchesRegularExpression(
            '/pending repository review and merge/',
            $doc,
            'Documentation must state the four rule imports are pending repository review and merge, not already done.'
        );
        $this->assertStringNotContainsString(
            'four rule imports have been imported',
            $doc
        );
    }

    public function test_documentation_never_claims_the_four_rules_are_already_imported(): void
    {
        $doc = $this->stateAdoptionPlan();

        preg_match('/### 9\.10.*?(?=## 10\.)/s', $doc, $matches);
        $this->assertNotEmpty($matches, 'Could not locate §9.10 (Phase A2 execution status).');
        $section = $matches[0];

        $this->assertStringContainsString('have not been imported', $section);
        $this->assertStringContainsString('6 managed resources plus 9 data-source', $section);
    }

    public function test_documentation_does_not_alter_claims_about_tflock_version_history(): void
    {
        $doc = $this->stateAdoptionPlan();

        preg_match('/### 9\.10.*?(?=## 10\.)/s', $doc, $matches);
        $this->assertNotEmpty($matches);
        $section = $matches[0];

        $this->assertStringContainsString(
            'has not been altered or deleted',
            $section,
            'Section 9.10 must confirm this correction does not alter/delete tflock version history.'
        );
    }

    public function test_variable_inventory_documents_both_permissions_as_granted(): void
    {
        $doc = $this->variableInventory();

        $this->assertMatchesRegularExpression('/DescribeVpcAttribute.{0,40}granted/s', $doc);
        $this->assertMatchesRegularExpression('/DescribeSecurityGroupRules.{0,40}granted/s', $doc);
    }

    public function test_variable_inventory_records_six_imported_and_four_pending(): void
    {
        $doc = $this->variableInventory();

        $this->assertStringContainsString('Six of Phase A2', $doc);
        $this->assertStringContainsString('have not been imported yet', $doc);
    }
}
