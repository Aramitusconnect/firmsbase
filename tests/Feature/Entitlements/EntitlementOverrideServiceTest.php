<?php

namespace Tests\Feature\Entitlements;

use App\Enums\EntitlementSource;
use App\Models\Firm;
use App\Models\ModuleCatalog;
use App\Models\PlatformAdmin;
use App\Models\SecurityEvent;
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
        $this->service = new EntitlementOverrideService(new EntitlementService);
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

    // ------------------------------------------------------------
    // Phase 4 FirmsVault Admin Control Center ("Configuration"
    // category) additions: setOverrideAsPlatformAdmin(), the
    // PlatformAdmin-actor variant behind EntitlementOverrideResource's
    // Set Override action.
    // ------------------------------------------------------------

    public function test_set_override_as_platform_admin_writes_the_entitlement_row_with_a_null_created_by(): void
    {
        $firm = Firm::factory()->create();
        $module = ModuleCatalog::factory()->create();
        $actor = PlatformAdmin::factory()->create();

        $entitlement = $this->service->setOverrideAsPlatformAdmin(
            $firm,
            $module->module_code,
            EntitlementSource::AdminOverride,
            true,
            'Platform-admin-initiated override',
            $actor,
        );

        $this->assertSame(EntitlementSource::AdminOverride, $entitlement->source);
        $this->assertTrue($entitlement->enabled);
        $this->assertNull(
            $entitlement->created_by,
            'created_by is a real FK to `users`, not `platform_admins` — must stay null for an admin-initiated write, never a fabricated User id.'
        );
    }

    public function test_set_override_as_platform_admin_writes_a_firm_scoped_audit_event(): void
    {
        $firm = Firm::factory()->create();
        $module = ModuleCatalog::factory()->create();
        $actor = PlatformAdmin::factory()->create();

        $entitlement = $this->service->setOverrideAsPlatformAdmin(
            $firm,
            $module->module_code,
            EntitlementSource::FirmOverride,
            false,
            'Disabling pending investigation',
            $actor,
        );

        $audit = $this->runWithFirmContext($firm, fn () => SecurityEvent::query()
            ->where('firm_id', $firm->id)
            ->where('event_type', 'entitlement_override_set')
            ->first());

        $this->assertNotNull($audit, 'setOverrideAsPlatformAdmin() must write a firm-scoped security_events row for real PlatformAdmin attribution.');
        $this->assertSame($actor->id, $audit->actor_id);
        $this->assertSame(PlatformAdmin::class, $audit->actor_type);
        $this->assertSame($entitlement->id, $audit->metadata['firm_entitlement_id']);
        $this->assertSame($module->module_code, $audit->metadata['module_code']);
        $this->assertFalse($audit->metadata['enabled']);
    }

    public function test_set_override_as_platform_admin_rejects_plan_source(): void
    {
        $firm = Firm::factory()->create();
        $module = ModuleCatalog::factory()->create();
        $actor = PlatformAdmin::factory()->create();

        $this->expectException(\InvalidArgumentException::class);

        $this->service->setOverrideAsPlatformAdmin($firm, $module->module_code, EntitlementSource::Plan, true, 'not allowed', $actor);
    }

    public function test_set_override_as_platform_admin_requires_a_non_empty_reason(): void
    {
        $firm = Firm::factory()->create();
        $module = ModuleCatalog::factory()->create();
        $actor = PlatformAdmin::factory()->create();

        $this->expectException(\InvalidArgumentException::class);

        $this->service->setOverrideAsPlatformAdmin($firm, $module->module_code, EntitlementSource::AdminOverride, true, '   ', $actor);
    }
}
