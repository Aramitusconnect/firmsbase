<?php

declare(strict_types=1);

namespace Tests\Feature\Ecs;

use Tests\TestCase;

/**
 * Proves the Phase A2 security-group-rule classification correction
 * (import-manifest.json's four aws_security_group_rule entries reclassified
 * from import_unchanged to import_then_migrate because of description
 * drift, with the earlier pass's unsupported ForceNew/update-in-place/
 * replacement-free claims removed) against the real, committed files —
 * never against a live `terraform plan`/`apply`/`import` (no AWS contact,
 * no credentials needed, fully deterministic), mirroring this repo's
 * AlbTargetGroupAdoptionTest/SesConsumerTerraformIamTest philosophy of
 * reading real committed files directly. See
 * docs/ecs/state-adoption-plan.md §9.11.
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

    private const UNSUPPORTED_CLAIM_PATTERNS = [
        '/description is not a ForceNew attribute/i',
        '/non-disruptive update-in-place diff only, not a replacement/i',
        '/this is a benign\s+update-in-place, not a replacement/i',
    ];

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

    private function extractSection(string $doc, string $startPattern, string $endPattern): string
    {
        preg_match('/'.$startPattern.'.*?(?='.$endPattern.')/s', $doc, $matches);
        $this->assertNotEmpty($matches, "Could not locate section matching /{$startPattern}/.");

        return $matches[0];
    }

    // ------------------------------------------------------------
    // import-manifest.json: composite IDs and sgr-* refs unchanged,
    // classification corrected, totals corrected
    // ------------------------------------------------------------

    public function test_none_of_the_four_rule_addresses_remains_blocked(): void
    {
        foreach (self::RULE_ADDRESSES as $address => $expected) {
            $entry = $this->manifestEntry($address);

            $this->assertNotSame(
                'BLOCKED',
                $entry['import_id'],
                "{$address} must not be marked import_id: \"BLOCKED\"."
            );
        }
    }

    public function test_each_import_id_has_the_expected_composite_structure_and_is_unchanged(): void
    {
        foreach (self::RULE_ADDRESSES as $address => $expected) {
            $entry = $this->manifestEntry($address);

            $this->assertSame(
                $expected['import_id'],
                $entry['import_id'],
                "{$address}'s composite import ID must remain exactly what was resolved previously — reclassification must not change it."
            );
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

    public function test_each_manifest_note_records_an_sgr_identifier_unchanged(): void
    {
        foreach (self::RULE_ADDRESSES as $address => $expected) {
            $entry = $this->manifestEntry($address);

            $this->assertSame(
                $expected['sgr_id'],
                $entry['live_reference'],
                "{$address}'s live_reference must remain exactly its AWS SecurityGroupRuleId — reclassification must not change it."
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

    public function test_classifications_are_corrected_to_import_then_migrate(): void
    {
        foreach (self::RULE_ADDRESSES as $address => $expected) {
            $entry = $this->manifestEntry($address);

            $this->assertSame(
                'import_then_migrate',
                $entry['classification'],
                "{$address} must be classified import_then_migrate, not import_unchanged, because of description drift."
            );
        }
    }

    public function test_manifest_notes_describe_description_drift_and_confirm_identity_match(): void
    {
        foreach (self::RULE_ADDRESSES as $address => $expected) {
            $entry = $this->manifestEntry($address);
            $notes = strtolower($entry['notes']);

            $this->assertStringContainsString('null', $notes, "{$address}'s notes must record the live description is null.");
            $this->assertStringContainsString('drift', $notes, "{$address}'s notes must name the description drift explicitly.");
            $this->assertStringContainsString(
                'matches uniquely and exactly',
                $notes,
                "{$address}'s notes must confirm the identity-defining fields match uniquely and exactly."
            );
        }
    }

    public function test_manifest_no_longer_makes_unsupported_replacement_claims(): void
    {
        foreach (self::RULE_ADDRESSES as $address => $expected) {
            $entry = $this->manifestEntry($address);
            $combined = $entry['notes'].' '.$entry['prerequisite'];

            foreach (self::UNSUPPORTED_CLAIM_PATTERNS as $pattern) {
                $this->assertDoesNotMatchRegularExpression(
                    $pattern,
                    $combined,
                    "{$address}'s manifest entry must not claim the description drift is proven non-ForceNew/update-in-place/replacement-free without a live plan verifying it."
                );
            }
        }
    }

    public function test_manifest_prerequisite_states_import_does_not_authorize_apply(): void
    {
        foreach (self::RULE_ADDRESSES as $address => $expected) {
            $entry = $this->manifestEntry($address);

            $this->assertStringContainsString(
                'does NOT approve',
                $entry['prerequisite'],
                "{$address}'s prerequisite must state that importing does not authorize a description change or other apply."
            );
            $this->assertStringContainsString(
                'stop condition',
                strtolower($entry['prerequisite']),
                "{$address}'s prerequisite must state that a proposed replacement is a stop condition requiring human review."
            );
        }
    }

    public function test_manifest_summary_totals_are_exactly_66_6_16_6_94(): void
    {
        $manifest = $this->importManifest();
        $summary = $manifest['summary'];

        $this->assertSame(66, $summary['new']);
        $this->assertSame(6, $summary['import_unchanged']);
        $this->assertSame(16, $summary['import_then_migrate']);
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
    // Documentation: Phase A2 shrinks to 6, Phase A3 grows to 16, no
    // unsupported replacement claims survive, status statements accurate
    // ------------------------------------------------------------

    public function test_phase_a2_heading_declares_six_addresses_and_all_imported(): void
    {
        $phaseA2 = $this->extractSection($this->stateAdoptionPlan(), '### Phase A2', '### Phase A3');

        $this->assertStringContainsString('6 addresses', $phaseA2, 'Phase A2 heading must declare 6 addresses now, not 10.');
        $this->assertStringContainsString('already been imported', $phaseA2);
        $this->assertStringNotContainsString('BLOCKED', $phaseA2, 'Phase A2 is fully imported; nothing in it should be labeled BLOCKED.');

        foreach (self::RULE_ADDRESSES as $address => $expected) {
            $this->assertStringNotContainsString(
                $address,
                $phaseA2,
                "{$address} must no longer be listed as one of Phase A2's import_unchanged addresses (moved to Phase A3)."
            );
        }
    }

    public function test_phase_a3_heading_declares_sixteen_addresses_and_carves_out_the_four_rules(): void
    {
        $phaseA3 = $this->extractSection($this->stateAdoptionPlan(), '### Phase A3', '### Phase B');

        $this->assertStringContainsString('16 addresses', $phaseA3, 'Phase A3 heading must declare 16 addresses now, not 12.');
        $this->assertMatchesRegularExpression(
            '/EXCEPT the four SG-rule imports/',
            $phaseA3,
            'Phase A3 must explicitly carve out the four rule imports from its blanket BLOCKED statement.'
        );

        foreach (self::RULE_ADDRESSES as $address => $expected) {
            $this->assertStringContainsString($address, $phaseA3, "{$address} must now be listed in Phase A3.");
            $this->assertStringContainsString($expected['sgr_id'], $phaseA3, "Phase A3 must record {$expected['sgr_id']} separately from its composite import ID.");
            $this->assertStringContainsString($expected['import_id'], $phaseA3, "Phase A3 must record the resolved composite import ID for {$address}.");

            // Confirm each address's own mention is not itself tagged BLOCKED.
            $pos = strpos($phaseA3, $address);
            $this->assertNotFalse($pos);
            $nearby = substr($phaseA3, max(0, $pos - 200), 260);
            $this->assertStringNotContainsString(
                'BLOCKED',
                $nearby,
                "{$address} must not be labeled BLOCKED near its own mention — it is importable now."
            );
        }
    }

    public function test_documentation_no_longer_makes_unsupported_replacement_claims(): void
    {
        $doc = $this->stateAdoptionPlan();

        foreach (self::UNSUPPORTED_CLAIM_PATTERNS as $pattern) {
            $this->assertDoesNotMatchRegularExpression(
                $pattern,
                $doc,
                'The document must not claim, as an active statement, that the description drift is proven non-ForceNew/update-in-place/replacement-free.'
            );
        }
    }

    public function test_documentation_explains_the_removed_claim_was_unsupported(): void
    {
        $section = $this->extractSection($this->stateAdoptionPlan(), '### 9\.11', '## 10\.');

        $this->assertStringContainsString('unsupported', strtolower($section));
        // Markdown source soft-wraps at ~79 columns, so multi-word phrases
        // may contain a newline where a space would render — match with
        // \s+ rather than a literal space.
        $this->assertMatchesRegularExpression('/has\s+been\s+removed/', $section);
        $this->assertMatchesRegularExpression('/has\s+not\s+been\s+verified/', $section);
    }

    public function test_documentation_states_import_does_not_authorize_apply(): void
    {
        $section = $this->extractSection($this->stateAdoptionPlan(), '### 9\.11', '## 10\.');

        $this->assertMatchesRegularExpression('/does\s+\*\*not\*\*/', $section);
        $this->assertMatchesRegularExpression('/no\s+apply\s+is\s+authorized\s+during\s+state\s+adoption/i', $section);
    }

    public function test_documentation_states_replacement_is_a_stop_condition(): void
    {
        $section = $this->extractSection($this->stateAdoptionPlan(), '### 9\.11', '## 10\.');

        $this->assertStringContainsString('stop condition', strtolower($section));
        $this->assertStringContainsString('explicit human review', $section);
    }

    public function test_documentation_does_not_migrate_resource_type_in_this_correction(): void
    {
        $section = $this->extractSection($this->stateAdoptionPlan(), '### 9\.11', '## 10\.');

        $this->assertStringContainsString('aws_vpc_security_group_ingress_rule', $section);
        $this->assertStringContainsString('out of scope', strtolower($section));
    }

    public function test_documentation_states_six_managed_resources_are_already_imported(): void
    {
        $doc = $this->stateAdoptionPlan();

        $this->assertMatchesRegularExpression(
            '/six.{0,40}already.{0,20}imported/is',
            $doc,
            'Documentation must state that six Phase A2 resources are already imported.'
        );
    }

    public function test_documentation_states_four_rule_imports_remain_pending(): void
    {
        $doc = $this->stateAdoptionPlan();

        $this->assertMatchesRegularExpression(
            '/pending\s+repository\s+review\s+and\s+merge/',
            $doc,
            'Documentation must state the four rule imports are pending repository review and merge, not already done.'
        );
        $this->assertStringNotContainsString('four rule imports have been imported', $doc);
    }

    public function test_documentation_never_claims_the_four_rules_are_already_imported(): void
    {
        $section = $this->extractSection($this->stateAdoptionPlan(), '### 9\.10', '### 9\.11');

        $this->assertStringContainsString('have not been imported', $section);
        $this->assertStringContainsString('6 managed resources plus 9 data-source', $section);
    }

    public function test_documentation_does_not_alter_claims_about_tflock_version_history(): void
    {
        $section = $this->extractSection($this->stateAdoptionPlan(), '### 9\.10', '### 9\.11');

        $this->assertStringContainsString(
            'has not been altered or deleted',
            $section,
            '§9.10 must confirm this correction does not alter/delete tflock version history.'
        );
    }

    public function test_variable_inventory_documents_both_permissions_as_granted(): void
    {
        $doc = $this->variableInventory();

        $this->assertMatchesRegularExpression('/DescribeVpcAttribute.{0,40}granted/s', $doc);
        $this->assertMatchesRegularExpression('/DescribeSecurityGroupRules.{0,40}granted/s', $doc);
    }

    public function test_variable_inventory_records_the_classification_correction(): void
    {
        $doc = $this->variableInventory();

        // As of the Phase A3 adoption-alignment correction, Phase A2 is
        // complete — the four rules this test originally covered are now
        // imported, not pending. This test now proves the doc records that
        // completion accurately (not the stale "not yet imported" claim)
        // while still preserving the classification/totals proof.
        $this->assertStringContainsString('import_then_migrate', $doc);
        $this->assertMatchesRegularExpression('/Phase A2 is complete/i', $doc);
        $this->assertMatchesRegularExpression('/new: 66, import_unchanged: 6, import_then_migrate: 16, do_not_import: 6/', $doc);

        foreach (self::UNSUPPORTED_CLAIM_PATTERNS as $pattern) {
            $this->assertDoesNotMatchRegularExpression($pattern, $doc);
        }
    }
}
