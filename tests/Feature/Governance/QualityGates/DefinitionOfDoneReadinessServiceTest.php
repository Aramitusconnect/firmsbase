<?php

namespace Tests\Feature\Governance\QualityGates;

use App\Services\DefinitionOfDoneReadinessService;
use Tests\TestCase;

class DefinitionOfDoneReadinessServiceTest extends TestCase
{
    private const REQUIRED_KEYS = [
        'entitlement_license_controls',
        'tenant_isolation_enforced_and_tested',
        'data_model_state_transitions_documented',
        'audit_logs_sensitive_actions',
        'error_states_edge_cases_handled',
        'external_dependency_degradation_declared',
        'client_facing_accessibility_mobile_checks',
        'admin_support_tooling_where_required',
        'tests_pass_release_checklist_complete',
    ];

    private DefinitionOfDoneReadinessService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DefinitionOfDoneReadinessService();
    }

    public function test_all_nine_dod_keys_are_declared_explicitly(): void
    {
        $checklist = $this->service->checklist();

        $this->assertCount(9, $checklist);

        foreach (self::REQUIRED_KEYS as $key) {
            $this->assertArrayHasKey($key, $checklist, "Missing required DoD key: {$key}");
        }
    }

    public function test_is_complete_is_false_when_nothing_is_confirmed(): void
    {
        $this->assertFalse($this->service->isComplete([]));
    }

    public function test_is_complete_is_false_when_only_some_items_are_confirmed(): void
    {
        $someKeys = array_slice(self::REQUIRED_KEYS, 0, 4);

        $this->assertFalse($this->service->isComplete($someKeys));
    }

    public function test_is_complete_is_true_only_when_every_key_is_confirmed(): void
    {
        $this->assertTrue($this->service->isComplete(self::REQUIRED_KEYS));
    }

    public function test_missing_returns_the_expected_unconfirmed_keys(): void
    {
        $confirmed = array_slice(self::REQUIRED_KEYS, 0, 2);

        $missing = $this->service->missing($confirmed);

        $this->assertSame(array_slice(self::REQUIRED_KEYS, 2), $missing);
    }

    public function test_missing_is_empty_when_everything_is_confirmed(): void
    {
        $this->assertEmpty($this->service->missing(self::REQUIRED_KEYS));
    }

    public function test_the_service_never_auto_confirms_feature_completion(): void
    {
        $this->assertFalse($this->service->isComplete([]));
        $this->assertCount(9, $this->service->missing([]));
    }
}
