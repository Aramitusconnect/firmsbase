<?php

namespace Tests\Feature\Entitlements;

use App\Enums\EntitlementSource;
use App\Models\Firm;
use App\Models\ModuleCatalog;
use App\Models\User;
use App\Services\EntitlementOverrideService;
use App\Services\EntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EntitlementOverrideServiceTest extends TestCase
{
    use RefreshDatabase;

    private EntitlementOverrideService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new EntitlementOverrideService(new EntitlementService());
    }

    public function test_set_override_writes_a_firm_override_row(): void
    {
        $firm = Firm::factory()->create();
        $module = $this->module('expenses');
        $actor = User::factory()->create();

        $entitlement = $this->service->setOverride(
            $firm,
            $module->module_code,
            EntitlementSource::FirmOverride,
            true,
            'Pilot firm early access',
            $actor,
        );

        $this->assertSame(EntitlementSource::FirmOverride, $entitlement->source);
        $this->assertTrue($entitlement->enabled);
    }

    public function test_set_override_rejects_plan_source(): void
    {
        $firm = Firm::factory()->create();
        $module = ModuleCatalog::factory()->create();
        $actor = User::factory()->create();

        $this->expectException(\InvalidArgumentException::class);

        $this->service->setOverride($firm, $module->module_code, EntitlementSource::Plan, true, 'not allowed', $actor);
    }

    public function test_set_override_rejects_org_inherited_source(): void
    {
        $firm = Firm::factory()->create();
        $module = ModuleCatalog::factory()->create();
        $actor = User::factory()->create();

        $this->expectException(\InvalidArgumentException::class);

        $this->service->setOverride($firm, $module->module_code, EntitlementSource::OrgInherited, true, 'not allowed', $actor);
    }

    public function test_set_override_requires_a_non_empty_reason(): void
    {
        $firm = Firm::factory()->create();
        $module = ModuleCatalog::factory()->create();
        $actor = User::factory()->create();

        $this->expectException(\InvalidArgumentException::class);

        $this->service->setOverride($firm, $module->module_code, EntitlementSource::AdminOverride, true, '   ', $actor);
    }

    /**
     * hotfix 01: reuses a module_catalog row already seeded by the
     * Phase 6 data migration instead of creating a duplicate via
     * ModuleCatalog::factory()->create(['module_code' => ...]), which
     * now violates module_catalog's unique index.
     */
    private function module(string $code): ModuleCatalog
    {
        return ModuleCatalog::query()->where('module_code', $code)->firstOrFail();
    }
}
