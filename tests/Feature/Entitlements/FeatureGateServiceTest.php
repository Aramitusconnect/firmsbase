<?php

namespace Tests\Feature\Entitlements;

use App\Enums\EntitlementSource;
use App\Models\Firm;
use App\Models\FirmEntitlement;
use App\Models\ModuleCatalog;
use App\Services\EntitlementService;
use App\Services\FeatureGateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeatureGateServiceTest extends TestCase
{
    use RefreshDatabase;

    private FeatureGateService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new FeatureGateService(new EntitlementService());
    }

    public function test_is_allowed_false_when_entitlement_disabled(): void
    {
        $firm = Firm::factory()->create();
        $module = ModuleCatalog::factory()->create();

        FirmEntitlement::factory()->forFirm($firm)->forModule($module)
            ->source(EntitlementSource::AdminOverride)->disabled()->create();

        $this->assertFalse($this->service->isAllowed($firm->id, $module->module_code));
    }

    public function test_is_allowed_true_when_entitlement_enabled_and_no_flags_exist(): void
    {
        $firm = Firm::factory()->create();
        $module = ModuleCatalog::factory()->create();

        FirmEntitlement::factory()->forFirm($firm)->forModule($module)
            ->source(EntitlementSource::AdminOverride)->create(['enabled' => true]);

        $this->assertTrue($this->service->isAllowed($firm->id, $module->module_code));
    }

    public function test_is_allowed_false_when_no_entitlement_exists_at_all(): void
    {
        $firm = Firm::factory()->create();
        $module = ModuleCatalog::factory()->create();

        $this->assertFalse($this->service->isAllowed($firm->id, $module->module_code));
    }
}
