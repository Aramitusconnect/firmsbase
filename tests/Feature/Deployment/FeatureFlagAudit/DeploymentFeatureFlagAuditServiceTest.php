<?php

namespace Tests\Feature\Deployment\FeatureFlagAudit;

use App\Enums\DeploymentMode;
use App\Enums\EntitlementSource;
use App\Services\DeploymentFeatureFlagAuditService;
use App\Services\EntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Deployment\Concerns\SetsUpDeploymentFirm;
use Tests\TestCase;

/**
 * Approved decision #3: "feature flag" is the EXISTING firm_entitlements/
 * EntitlementSource mechanism — no second system. This test proves an
 * entitlement change for a dedicated firm is fully audited through the
 * existing FirmEntitlementEvent trail.
 */
class DeploymentFeatureFlagAuditServiceTest extends TestCase
{
    use RefreshDatabase, SetsUpDeploymentFirm;

    private DeploymentFeatureFlagAuditService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(DeploymentFeatureFlagAuditService::class);
    }

    public function test_entitlement_change_for_dedicated_firm_produces_a_retrievable_audit_trail(): void
    {
        $firm = $this->makeDeploymentFirm(DeploymentMode::Dedicated);

        app(EntitlementService::class)->setForSource(
            $firm,
            'forms',
            EntitlementSource::AdminOverride,
            true,
            [],
            null,
            'Enabling forms module for dedicated firm.',
        );

        $trail = $this->service->auditTrailFor($firm, 'forms');

        $this->assertCount(1, $trail);
        $this->assertSame('forms', $trail->first()->module_code);
    }

    public function test_isFullyAudited_is_true_after_entitlement_changes(): void
    {
        $firm = $this->makeDeploymentFirm(DeploymentMode::Dedicated);

        app(EntitlementService::class)->setForSource(
            $firm,
            'forms',
            EntitlementSource::AdminOverride,
            true,
            [],
            null,
            'Enabling forms module for dedicated firm.',
        );

        $this->assertTrue($this->service->isFullyAudited($firm));
    }

    public function test_isFullyAudited_is_true_for_a_firm_with_no_entitlement_changes(): void
    {
        $firm = $this->makeDeploymentFirm(DeploymentMode::Dedicated);

        $this->assertTrue($this->service->isFullyAudited($firm));
        $this->assertCount(0, $this->service->auditTrailFor($firm));
    }
}
