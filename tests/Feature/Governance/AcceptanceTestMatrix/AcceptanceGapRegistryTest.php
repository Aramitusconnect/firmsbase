<?php

namespace Tests\Feature\Governance\AcceptanceTestMatrix;

use App\Services\ComplianceGapRegistryService;
use Tests\TestCase;

/**
 * AcceptanceGapRegistryTest — proves Section 36 added NO new gap to
 * ComplianceGapRegistryService: AWS inspection found no true
 * production-blocking acceptance-test requirement lacking both an
 * existing gap and existing behavior/readiness coverage. The gap
 * count remains 21.
 */
class AcceptanceGapRegistryTest extends TestCase
{
    private ComplianceGapRegistryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ComplianceGapRegistryService();
    }

    public function test_starting_and_final_gap_count_remains_twenty_one(): void
    {
        // 21 pre-existing (Section 25-35). Section 36 confirmed no new
        // production-blocking gap: entitlements.job_blocked and
        // entitlements.report_blocked are NotFound only because those
        // surfaces (queued jobs, reporting) do not exist at all —
        // absent features, not missing safety nets around an existing
        // one — so no gap was warranted.
        $this->assertCount(21, $this->service->all());
    }

    public function test_no_duplicate_gap_keys_exist(): void
    {
        $keys = array_map(fn ($item) => $item->key, $this->service->all());

        $this->assertCount(count($keys), array_unique($keys), 'Duplicate gap key(s) found.');
    }

    public function test_existing_referenced_gap_keys_exist(): void
    {
        foreach ([
            'rls_prepared_not_enforced',
            'signed_document_url_service_missing',
            'real_malware_scanning_engine_stubbed',
            'emergency_support_access_high_risk_approval_not_wired',
            'ai_jobs_not_cancelled_when_entitlement_removed',
            'stripe_disconnect_payment_collection_block_not_enforced',
            'template_language_fallback_staff_notification_missing',
            'form_edition_watch_sla_controls_missing',
        ] as $key) {
            $this->assertTrue($this->service->isTracked($key), "Expected existing gap '{$key}' to be tracked.");
        }
    }

    public function test_no_gap_was_added_solely_for_missing_ui_mobile_or_browser_tests(): void
    {
        $forbiddenGapKeys = [
            'accessibility_mobile_ui_missing',
            'client_portal_ui_missing',
            'mobile_ui_missing',
            'browser_test_harness_missing',
            'two_factor_authentication_ui_missing',
        ];

        foreach ($forbiddenGapKeys as $key) {
            $this->assertFalse($this->service->isTracked($key), "Gap '{$key}' must not exist — UI/mobile/browser absence alone is not a gap.");
        }
    }

    public function test_no_duplicate_rls_gap_exists(): void
    {
        $rlsRelatedKeys = array_filter(
            array_map(fn ($item) => $item->key, $this->service->all()),
            fn (string $key) => str_contains($key, 'rls'),
        );

        $this->assertCount(1, $rlsRelatedKeys);
    }
}
