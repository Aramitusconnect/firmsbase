<?php

namespace Tests\Feature\Entitlements;

use App\Enums\EntitlementSource;
use App\Models\Firm;
use App\Models\FirmEntitlement;
use App\Models\ModuleCatalog;
use App\Models\User;
use App\Services\EntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EntitlementServiceTest extends TestCase
{
    use RefreshDatabase;

    private EntitlementService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new EntitlementService();
    }

    public function test_resolve_returns_not_entitled_when_nothing_exists(): void
    {
        $firm = Firm::factory()->create();
        $module = ModuleCatalog::factory()->create();

        $resolution = $this->service->resolve($firm->id, $module->module_code);

        $this->assertFalse($resolution->enabled);
        $this->assertNull($resolution->source);
    }

    public function test_admin_override_wins_over_all_other_sources(): void
    {
        $firm = Firm::factory()->create();
        $module = ModuleCatalog::factory()->create();

        FirmEntitlement::factory()->forFirm($firm)->forModule($module)->source(EntitlementSource::Plan)->create(['enabled' => true]);
        FirmEntitlement::factory()->forFirm($firm)->forModule($module)->source(EntitlementSource::OrgInherited)->create(['enabled' => true]);
        FirmEntitlement::factory()->forFirm($firm)->forModule($module)->source(EntitlementSource::FirmOverride)->create(['enabled' => true]);
        FirmEntitlement::factory()->forFirm($firm)->forModule($module)->source(EntitlementSource::AdminOverride)->create(['enabled' => false]);

        $resolution = $this->service->resolve($firm->id, $module->module_code);

        $this->assertFalse($resolution->enabled);
        $this->assertSame(EntitlementSource::AdminOverride, $resolution->source);
    }

    public function test_firm_override_wins_when_no_admin_override_exists(): void
    {
        $firm = Firm::factory()->create();
        $module = ModuleCatalog::factory()->create();

        FirmEntitlement::factory()->forFirm($firm)->forModule($module)->source(EntitlementSource::Plan)->create(['enabled' => false]);
        FirmEntitlement::factory()->forFirm($firm)->forModule($module)->source(EntitlementSource::OrgInherited)->create(['enabled' => false]);
        FirmEntitlement::factory()->forFirm($firm)->forModule($module)->source(EntitlementSource::FirmOverride)->create(['enabled' => true]);

        $resolution = $this->service->resolve($firm->id, $module->module_code);

        $this->assertTrue($resolution->enabled);
        $this->assertSame(EntitlementSource::FirmOverride, $resolution->source);
    }

    public function test_org_inherited_wins_over_plan_when_no_overrides_exist(): void
    {
        $firm = Firm::factory()->create();
        $module = ModuleCatalog::factory()->create();

        FirmEntitlement::factory()->forFirm($firm)->forModule($module)->source(EntitlementSource::Plan)->create(['enabled' => false]);
        FirmEntitlement::factory()->forFirm($firm)->forModule($module)->source(EntitlementSource::OrgInherited)->create(['enabled' => true]);

        $resolution = $this->service->resolve($firm->id, $module->module_code);

        $this->assertTrue($resolution->enabled);
        $this->assertSame(EntitlementSource::OrgInherited, $resolution->source);
    }

    public function test_expired_records_are_excluded_from_resolution(): void
    {
        $firm = Firm::factory()->create();
        $module = ModuleCatalog::factory()->create();

        FirmEntitlement::factory()->forFirm($firm)->forModule($module)
            ->source(EntitlementSource::AdminOverride)
            ->create(['enabled' => true, 'ends_at' => now()->subDay()]);

        $resolution = $this->service->resolve($firm->id, $module->module_code);

        $this->assertFalse($resolution->enabled, 'An expired admin_override must not win resolution.');
        $this->assertNull($resolution->source);
    }

    public function test_is_enabled_convenience_method(): void
    {
        $firm = Firm::factory()->create();
        $module = ModuleCatalog::factory()->create();

        FirmEntitlement::factory()->forFirm($firm)->forModule($module)
            ->source(EntitlementSource::AdminOverride)->create(['enabled' => true]);

        $this->assertTrue($this->service->isEnabled($firm->id, $module->module_code));
    }

    public function test_set_for_source_creates_entitlement_and_audit_event(): void
    {
        $firm = Firm::factory()->create();
        $module = ModuleCatalog::factory()->create();
        $actor = User::factory()->create();

        $entitlement = $this->service->setForSource(
            firm: $firm,
            moduleCode: $module->module_code,
            source: EntitlementSource::AdminOverride,
            enabled: true,
            actor: $actor,
            reason: 'manual grant for pilot firm',
        );

        // Section 39A-3L, Checkpoint 4 — firm_entitlements now has FORCE
        // ROW LEVEL SECURITY active. assertDatabaseHas() queries with no
        // tenant context of its own, so it would now see zero rows.
        // setForSource() itself already cleared context by the time
        // control returns here, so this is a genuinely fresh,
        // explicitly context-wrapped read against this now-force-
        // protected table — not a weakening of the original assertion.
        $this->runWithFirmContext($firm, function () use ($entitlement, $firm, $module) {
            $this->assertDatabaseHas('firm_entitlements', [
                'id' => $entitlement->id,
                'firm_id' => $firm->id,
                'module_code' => $module->module_code,
                'enabled' => true,
            ]);
        });

        // Section 39A-3L, Checkpoint 5 — firm_entitlement_events now has
        // FORCE ROW LEVEL SECURITY active. assertDatabaseHas() queries
        // with no tenant context of its own, so it would now see zero
        // rows. setForSource() itself already cleared context by the
        // time control returns here, so this is a genuinely fresh,
        // explicitly context-wrapped read against this now-force-
        // protected table — not a weakening of the original assertion.
        $this->runWithFirmContext($firm, function () use ($entitlement, $actor) {
            $this->assertDatabaseHas('firm_entitlement_events', [
                'firm_entitlement_id' => $entitlement->id,
                'action' => 'granted',
                'actor_id' => $actor->id,
                'reason' => 'manual grant for pilot firm',
            ]);
        });
    }

    public function test_set_for_source_upserts_rather_than_duplicating(): void
    {
        $firm = Firm::factory()->create();
        $module = ModuleCatalog::factory()->create();

        $first = $this->service->setForSource($firm, $module->module_code, EntitlementSource::AdminOverride, true);
        $second = $this->service->setForSource($firm, $module->module_code, EntitlementSource::AdminOverride, false);

        $this->assertSame($first->id, $second->id);

        // Section 39A-3L, Checkpoint 4 — firm_entitlements now has FORCE
        // ROW LEVEL SECURITY active. Both setForSource() calls above
        // already cleared context by the time control returns here, so
        // this count query is a genuinely fresh, explicitly
        // context-wrapped read.
        $this->assertSame(
            1,
            $this->runWithFirmContext($firm, fn () => FirmEntitlement::where('firm_id', $firm->id)
                ->where('module_code', $module->module_code)
                ->where('source', 'admin_override')
                ->count())
        );
        $this->assertFalse($this->runWithFirmContext($firm, fn () => $second->fresh())->enabled);

        // Section 39A-3L, Checkpoint 5 — firm_entitlement_events now has
        // FORCE ROW LEVEL SECURITY active. Both setForSource() calls
        // above already cleared context by the time control returns
        // here, so this is a genuinely fresh, explicitly
        // context-wrapped read against this now-force-protected table.
        $this->runWithFirmContext($firm, function () use ($first) {
            $this->assertDatabaseHas('firm_entitlement_events', [
                'firm_entitlement_id' => $first->id,
                'action' => 'updated',
            ]);
        });
    }
}
