<?php

namespace Tests\Feature\Deployment\TrustAcknowledgment;

use App\Enums\DeploymentMode;
use App\Models\DeploymentConfig;
use App\Models\FirmUser;
use App\Services\TrustIoltaDisableAcknowledgmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Deployment\Concerns\SetsUpDeploymentFirm;
use Tests\TestCase;

/**
 * Approved decision #2: BOTH platform-admin approval and firm-side
 * acknowledgment are required — never either alone. This service never
 * modifies Phase 13 trust accounting services.
 */
class TrustIoltaDisableAcknowledgmentServiceTest extends TestCase
{
    use RefreshDatabase, SetsUpDeploymentFirm;

    private TrustIoltaDisableAcknowledgmentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(TrustIoltaDisableAcknowledgmentService::class);
    }

    public function test_posture_invalid_with_only_admin_approval(): void
    {
        $firm = $this->makeDeploymentFirm(DeploymentMode::Dedicated);
        $config = DeploymentConfig::factory()->forFirm($firm)->create();
        $admin1 = $this->makePlatformAdmin();
        $admin2 = $this->makePlatformAdmin();

        $request = $this->service->requestApproval($firm, $admin1, 'Operating-only posture.');
        $this->service->firstApprove($request, $admin1);
        $this->service->secondApprove($request, $admin2);

        $this->assertTrue($this->service->isAdminApproved($firm));
        $this->assertFalse($config->fresh()->hasFirmAcknowledgedTrustIoltaDisabled());
        $this->assertFalse($this->service->isPostureValid($firm, $config->fresh()));
    }

    public function test_posture_invalid_with_only_firm_acknowledgment(): void
    {
        $firm = $this->makeDeploymentFirm(DeploymentMode::Dedicated);
        $config = DeploymentConfig::factory()->forFirm($firm)->create();
        $firmUser = FirmUser::factory()->create(['firm_id' => $firm->id]);

        $acknowledged = $this->service->recordFirmAcknowledgment($config, $firmUser, 'We acknowledge operating-only posture.', 'v1');

        $this->assertTrue($acknowledged->hasFirmAcknowledgedTrustIoltaDisabled());
        $this->assertFalse($this->service->isAdminApproved($firm));
        $this->assertFalse($this->service->isPostureValid($firm, $acknowledged));
    }

    public function test_posture_valid_only_when_both_present(): void
    {
        $firm = $this->makeDeploymentFirm(DeploymentMode::Dedicated);
        $config = DeploymentConfig::factory()->forFirm($firm)->create();
        $firmUser = FirmUser::factory()->create(['firm_id' => $firm->id]);
        $admin1 = $this->makePlatformAdmin();
        $admin2 = $this->makePlatformAdmin();

        $request = $this->service->requestApproval($firm, $admin1, 'Operating-only posture.');
        $this->service->firstApprove($request, $admin1);
        $this->service->secondApprove($request, $admin2);
        $config = $this->service->recordFirmAcknowledgment($config, $firmUser, 'We acknowledge operating-only posture.', 'v1');

        $this->assertTrue($this->service->isPostureValid($firm, $config));
    }
}
