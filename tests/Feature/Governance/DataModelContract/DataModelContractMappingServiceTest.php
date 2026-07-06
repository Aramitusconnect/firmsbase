<?php

namespace Tests\Feature\Governance\DataModelContract;

use App\Enums\GovernanceMappingStatus;
use App\Services\DataModelContractMappingService;
use Tests\TestCase;

class DataModelContractMappingServiceTest extends TestCase
{
    private const REQUIRED_RULE_KEYS = [
        'uuidv7_public_references',
        'firm_id_on_tenant_tables',
        'global_commercial_tables',
        'rls_transaction_local_tenant_identifier',
        'avoid_hard_deletes_for_sensitive_records',
        'status_fields_and_state_machine_events',
        'append_only_and_reversal_patterns',
        'idempotency_keys_for_retry_sensitive_operations',
        'expand_contract_migration_discipline',
    ];

    private const REQUIRED_FAMILY_KEYS = [
        'commercial_hierarchy',
        'tenant_and_security',
        'plans_and_licenses',
        'practice_templates',
        'firm_growth',
        'client_and_matters',
        'documents',
        'tasks_and_deadlines',
        'billing_and_payments',
        'platform_billing',
        'trust_accounting',
        'operations',
        'ai',
        'governance',
    ];

    private const REQUIRED_UUID_CANDIDATES = [
        'Task', 'Deadline', 'CalendarEvent', 'TimeEntry', 'TrustLedgerEntry',
        'MatterTrustBalance', 'MatterType', 'PracticeArea', 'IntakeTemplate',
        'InvoiceLine', 'DocumentVersion',
    ];

    private DataModelContractMappingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DataModelContractMappingService();
    }

    public function test_declares_all_nine_global_rules_by_explicit_key(): void
    {
        $rules = $this->service->globalRules();

        $this->assertCount(9, $rules);

        $declaredKeys = array_map(fn ($rule) => $rule->item_key, $rules);

        foreach (self::REQUIRED_RULE_KEYS as $key) {
            $this->assertContains($key, $declaredKeys, "Missing required global rule: {$key}");
        }
    }

    public function test_declares_all_fourteen_table_families_by_explicit_key(): void
    {
        $families = $this->service->tableFamilies();

        $this->assertCount(14, $families);

        $declaredKeys = array_map(fn ($family) => $family->item_key, $families);

        foreach (self::REQUIRED_FAMILY_KEYS as $key) {
            $this->assertContains($key, $declaredKeys, "Missing required table family: {$key}");
        }
    }

    public function test_by_rule_and_by_family_resolve_every_declared_key(): void
    {
        foreach (self::REQUIRED_RULE_KEYS as $key) {
            $this->assertNotNull($this->service->byRule($key), "byRule() could not resolve: {$key}");
        }

        foreach (self::REQUIRED_FAMILY_KEYS as $key) {
            $this->assertNotNull($this->service->byFamily($key), "byFamily() could not resolve: {$key}");
        }

        $this->assertNull($this->service->byRule('does_not_exist'));
        $this->assertNull($this->service->byFamily('does_not_exist'));
    }

    public function test_rls_rule_is_partially_implemented(): void
    {
        $rule = $this->service->byRule('rls_transaction_local_tenant_identifier');

        $this->assertSame(GovernanceMappingStatus::PartiallyImplemented, $rule->status);
    }

    public function test_expand_contract_migration_discipline_is_implemented(): void
    {
        $rule = $this->service->byRule('expand_contract_migration_discipline');

        $this->assertSame(GovernanceMappingStatus::Implemented, $rule->status);
    }

    public function test_activity_logs_interpretation_maps_to_security_event_and_timeline_event_recorder_not_a_new_table(): void
    {
        $result = $this->service->activityLogsInterpretation();

        $this->assertSame('activity_logs', $result->item_key);
        $this->assertSame(\App\Services\TimelineEventRecorder::class, $result->owning_class);
        $this->assertStringContainsString('SecurityEvent', $result->notes);
        $this->assertStringContainsString('TimelineEventRecorder', $result->notes);
        $this->assertSame(GovernanceMappingStatus::Implemented, $result->status);

        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('activity_logs'));
    }

    public function test_public_uuid_candidates_returns_exactly_the_approved_list(): void
    {
        $candidates = $this->service->publicUuidCandidates();

        sort($candidates);
        $expected = self::REQUIRED_UUID_CANDIDATES;
        sort($expected);

        $this->assertSame($expected, $candidates);
    }

    public function test_practice_templates_family_is_an_open_question_not_a_gap_or_schema_request(): void
    {
        $family = $this->service->byFamily('practice_templates');

        $this->assertSame(GovernanceMappingStatus::NotApplicableYet, $family->status);
        $this->assertStringContainsString('OPEN QUESTION', $family->notes);
        $this->assertStringNotContainsString('should be created', $family->notes);
    }

    public function test_governance_family_notes_reference_activity_logs_representation(): void
    {
        $family = $this->service->byFamily('governance');

        $this->assertStringContainsString('SecurityEvent', $family->notes);
        $this->assertStringContainsString('TimelineEventRecorder', $family->notes);
    }
}
