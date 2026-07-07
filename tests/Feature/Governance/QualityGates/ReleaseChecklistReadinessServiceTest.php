<?php

namespace Tests\Feature\Governance\QualityGates;

use App\Services\ReleaseChecklistReadinessService;
use Tests\TestCase;

class ReleaseChecklistReadinessServiceTest extends TestCase
{
    private const REQUIRED_KEYS = [
        'migrations_reviewed_expand_contract_reversible',
        'policies_rls_middleware_reviewed',
        'seed_data_reviewed_no_test_secrets',
        'critical_workflows_automated_tests',
        'no_public_document_urls',
        'no_cross_firm_data_exposure',
        'queues_scheduler_running_where_required',
        'monitoring_alerting_configured_for_production',
        'rollback_plan_documented',
        'security_sensitive_changes_reviewed',
    ];

    private ReleaseChecklistReadinessService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ReleaseChecklistReadinessService();
    }

    public function test_all_ten_checklist_keys_are_declared_explicitly(): void
    {
        $checklist = $this->service->checklist();

        $this->assertCount(10, $checklist);

        foreach (self::REQUIRED_KEYS as $key) {
            $this->assertArrayHasKey($key, $checklist, "Missing required checklist key: {$key}");
        }
    }

    public function test_is_complete_is_false_when_nothing_is_confirmed(): void
    {
        $this->assertFalse($this->service->isComplete([]));
    }

    public function test_is_complete_is_false_when_only_some_items_are_confirmed(): void
    {
        $someKeys = array_slice(self::REQUIRED_KEYS, 0, 5);

        $this->assertFalse($this->service->isComplete($someKeys));
    }

    public function test_is_complete_is_true_only_when_every_key_is_confirmed(): void
    {
        $this->assertTrue($this->service->isComplete(self::REQUIRED_KEYS));
    }

    public function test_missing_returns_the_expected_unconfirmed_keys(): void
    {
        $confirmed = array_slice(self::REQUIRED_KEYS, 0, 3);

        $missing = $this->service->missing($confirmed);

        $this->assertSame(array_slice(self::REQUIRED_KEYS, 3), $missing);
    }

    public function test_missing_is_empty_when_everything_is_confirmed(): void
    {
        $this->assertEmpty($this->service->missing(self::REQUIRED_KEYS));
    }

    public function test_the_service_never_auto_confirms_production_readiness(): void
    {
        // Calling checklist()/isComplete()/missing() with no arguments or
        // an empty confirmation set must never report the release as
        // ready — there is no internal state that silently marks items
        // done.
        $this->assertFalse($this->service->isComplete([]));
        $this->assertCount(10, $this->service->missing([]));
    }
}
