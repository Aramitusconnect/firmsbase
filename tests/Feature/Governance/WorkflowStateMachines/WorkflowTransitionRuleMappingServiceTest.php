<?php

namespace Tests\Feature\Governance\WorkflowStateMachines;

use App\Enums\GovernanceMappingStatus;
use App\Services\WorkflowTransitionRuleMappingService;
use App\ValueObjects\GovernanceMappingResult;
use Tests\TestCase;

class WorkflowTransitionRuleMappingServiceTest extends TestCase
{
    private const REQUIRED_KEYS = [
        'firm_license_suspension_preserves_legal_data',
        'firm_license_export_only_retention_offboarding',
        'lead_conversion_creates_client_and_starts_intake',
        'lost_leads_follow_retention_policy',
        'matter_opening_requires_conflict_gate',
        'matter_creation_pins_practice_area_template_version',
        'document_reminders_stop_on_terminal_or_paused_states',
        'task_blocked_derives_from_unmet_dependencies',
        'task_overdue_derives_from_due_at_and_status',
        'invoice_payment_requires_classification_and_permission',
        'payment_plan_activation_locks_schedule',
        'payment_plan_renegotiation_supersedes_and_pauses_dunning',
        'installment_paid_by_canonical_payment_only',
        'missed_installment_triggers_consent_respecting_dunning',
        'payment_classification_before_save_or_provider_intent',
        'trust_posting_requires_balance_approval_lock_ledger_audit',
        'high_risk_client_facing_ai_requires_human_approval',
        'import_batch_no_production_write_before_preview_validation_confirmation',
        'signature_completion_requires_evidence_hash_event_certificate',
        'fleet_migration_halt_on_failure_stops_propagation',
        'fleet_migration_rollback_restores_prior_version',
    ];

    private WorkflowTransitionRuleMappingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new WorkflowTransitionRuleMappingService();
    }

    public function test_all_twenty_one_transition_rule_keys_are_explicitly_declared(): void
    {
        $declaredKeys = array_keys($this->service->all());

        $this->assertCount(21, $declaredKeys);

        foreach (self::REQUIRED_KEYS as $key) {
            $this->assertContains($key, $declaredKeys, "Missing required transition rule key: {$key}");
        }
    }

    public function test_no_duplicate_keys_exist(): void
    {
        $keys = array_keys($this->service->all());

        $this->assertCount(count($keys), array_unique($keys), 'Duplicate transition rule key(s) found.');
    }

    public function test_all_entries_return_governance_mapping_result(): void
    {
        foreach ($this->service->all() as $key => $item) {
            $this->assertInstanceOf(GovernanceMappingResult::class, $item, "Entry {$key} must be a GovernanceMappingResult.");
        }
    }

    public function test_every_entry_has_notes_or_evidence(): void
    {
        foreach ($this->service->all() as $key => $item) {
            $this->assertNotEmpty($item->notes, "Item {$key} should have explanatory notes.");
        }
    }

    public function test_service_enforced_contains_rules_with_dedicated_services(): void
    {
        $serviceEnforced = array_keys($this->service->serviceEnforced());

        $this->assertContains('matter_opening_requires_conflict_gate', $serviceEnforced);
        $this->assertContains('task_blocked_derives_from_unmet_dependencies', $serviceEnforced);
        $this->assertContains('trust_posting_requires_balance_approval_lock_ledger_audit', $serviceEnforced);
        $this->assertContains('signature_completion_requires_evidence_hash_event_certificate', $serviceEnforced);
        $this->assertContains('fleet_migration_halt_on_failure_stops_propagation', $serviceEnforced);
        $this->assertNotEmpty($serviceEnforced);
    }

    public function test_informal_or_ui_only_is_empty_because_aws_confirms_no_informal_transitions(): void
    {
        $this->assertEmpty($this->service->informalOrUiOnly());
    }

    public function test_matter_template_version_pinning_is_partially_implemented_because_creation_time_timing_is_not_confirmed(): void
    {
        $item = $this->service->byKey('matter_creation_pins_practice_area_template_version');

        $this->assertNotNull($item);
        $this->assertSame(GovernanceMappingStatus::PartiallyImplemented, $item->status);
        $this->assertStringContainsString('pinned_template_pack_version_id', $item->notes);
    }

    public function test_trust_posting_rule_names_balance_approval_lock_ledger_and_audit_in_evidence(): void
    {
        $item = $this->service->byKey('trust_posting_requires_balance_approval_lock_ledger_audit');

        $this->assertNotNull($item);
        $this->assertStringContainsStringIgnoringCase('balance', $item->notes);
        $this->assertStringContainsStringIgnoringCase('approval', $item->notes);
        $this->assertStringContainsStringIgnoringCase('lock', $item->notes);
        $this->assertStringContainsStringIgnoringCase('ledger', $item->notes);
        $this->assertStringContainsStringIgnoringCase('audit', $item->notes);
    }

    public function test_signature_completion_rule_names_evidence_hash_event_trail_and_certificate(): void
    {
        $item = $this->service->byKey('signature_completion_requires_evidence_hash_event_certificate');

        $this->assertNotNull($item);
        $this->assertStringContainsStringIgnoringCase('evidence', $item->notes);
        $this->assertStringContainsStringIgnoringCase('hash', $item->notes);
        $this->assertStringContainsStringIgnoringCase('event trail', $item->notes);
        $this->assertStringContainsStringIgnoringCase('certificate', $item->notes);
    }

    public function test_byKey_returns_null_for_an_unknown_key(): void
    {
        $this->assertNull($this->service->byKey('does_not_exist'));
    }
}
