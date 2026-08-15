<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformAdmin;

use App\Enums\EntitlementSource;
use App\Enums\PlatformRoleCode;
use App\Filament\Actions\Platform\SetEntitlementOverrideAction;
use App\Filament\Resources\EntitlementOverrideResource;
use App\Filament\Resources\EntitlementOverrideResource\Pages\ListEntitlementOverrides;
use App\Filament\Resources\EntitlementOverrideResource\Pages\ViewEntitlementOverride;
use App\Models\Firm;
use App\Models\FirmEntitlement;
use App\Models\ModuleCatalog;
use App\Models\PlatformAdmin;
use App\Models\SecurityEvent;
use App\Services\PlatformEntitlementOverrideDirectoryService;
use App\Services\PlatformRoleService;
use App\Services\Security\StepUpAuthenticationService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * EntitlementOverrideResourceTest ("Entitlement Overrides", the honest
 * relabeling of "Feature Flags") — Phase 4 (FirmsVault Platform Admin
 * Control Center, "Configuration" category). Navigation visibility,
 * route-level authorization, filters, deterministic ordering, no-N+1,
 * and the Set Override action's full lifecycle.
 */
final class EntitlementOverrideResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function adminWithRole(PlatformRoleCode $role): PlatformAdmin
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, $role);

        return $admin;
    }

    private function entitlement(Firm $firm, array $attributes = []): FirmEntitlement
    {
        return FirmEntitlement::factory()->forFirm($firm)->create($attributes);
    }

    // --- Navigation visibility ---

    public function test_navigation_is_hidden_when_no_admin_is_authenticated(): void
    {
        $this->assertFalse(EntitlementOverrideResource::canAccess());
    }

    public function test_navigation_is_visible_for_an_eligible_platform_admin(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $this->assertTrue(EntitlementOverrideResource::canAccess());
    }

    public function test_navigation_is_hidden_for_a_support_agent(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SupportAgent);
        $this->actingAs($admin, 'platform_admin');

        $this->assertFalse(EntitlementOverrideResource::canAccess(), 'Entitlement configuration is a distinct Configuration-domain concern — SupportAgent is deliberately excluded.');
    }

    // --- Route-level authorization ---

    public function test_guest_is_redirected_from_the_entitlement_overrides_list(): void
    {
        $this->get(EntitlementOverrideResource::getUrl())->assertRedirect($this->adminUrl('/login'));
    }

    public function test_a_platform_admin_with_no_role_is_forbidden(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);

        $this->actingAs($admin, 'platform_admin')->get(EntitlementOverrideResource::getUrl())->assertForbidden();
    }

    public function test_a_billing_admin_can_reach_the_list_and_view_pages(): void
    {
        $firm = Firm::factory()->activated()->create(['name' => 'Entitlement Firm']);
        $entitlement = $this->entitlement($firm);

        $admin = $this->adminWithRole(PlatformRoleCode::BillingAdmin);
        $this->actingAs($admin, 'platform_admin');

        $listResponse = $this->get(EntitlementOverrideResource::getUrl());
        $listResponse->assertOk();
        $listResponse->assertSee('Entitlement Firm');

        $viewResponse = $this->get(ViewEntitlementOverride::getUrl(['firmUuid' => $firm->uuid, 'id' => $entitlement->id]));
        $viewResponse->assertOk();
    }

    public function test_viewing_an_entitlement_under_the_wrong_firm_404s(): void
    {
        $firmA = Firm::factory()->activated()->create();
        $firmB = Firm::factory()->activated()->create();
        $entitlement = $this->entitlement($firmA);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $this->actingAs($admin, 'platform_admin')
            ->get(ViewEntitlementOverride::getUrl(['firmUuid' => $firmB->uuid, 'id' => $entitlement->id]))
            ->assertNotFound();
    }

    // --- Empty state ---

    public function test_empty_state_is_shown_when_no_entitlements_exist(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $response = $this->get(EntitlementOverrideResource::getUrl());
        $response->assertOk();
        $response->assertSee('No entitlement records found');
    }

    // --- Filters ---

    public function test_source_filter_narrows_the_list(): void
    {
        $firm = Firm::factory()->activated()->create();
        $this->entitlement($firm, ['source' => EntitlementSource::AdminOverride]);
        $this->entitlement($firm->fresh(), ['source' => EntitlementSource::Plan]);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $rows = app(PlatformEntitlementOverrideDirectoryService::class)->listEntitlements($admin, ['source' => EntitlementSource::AdminOverride->value]);

        $this->assertCount(1, $rows);
        $this->assertSame(EntitlementSource::AdminOverride->value, $rows->first()['source']);
        $this->assertSame(4, $rows->first()['precedence']);
    }

    // --- Bounded pagination ---

    public function test_the_list_page_is_paginated_not_a_single_unbounded_page(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(ListEntitlementOverrides::class);
        $test->assertOk();
        $test->assertSet('tableRecordsPerPage', 25);
    }

    // --- Deterministic ordering ---

    public function test_ordering_is_deterministic_for_equal_updated_at_timestamps(): void
    {
        $firm = Firm::factory()->activated()->create();
        $now = now();

        $first = $this->entitlement($firm, ['updated_at' => $now]);
        $second = $this->entitlement($firm->fresh(), ['updated_at' => $now]);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $rowsA = app(PlatformEntitlementOverrideDirectoryService::class)->listEntitlements($admin)->pluck('id')->all();
        $rowsB = app(PlatformEntitlementOverrideDirectoryService::class)->listEntitlements($admin)->pluck('id')->all();

        $this->assertSame($rowsA, $rowsB);
        $this->assertSame([$second->id, $first->id], $rowsA);
    }

    // --- No-N+1 proof ---

    public function test_listing_many_entitlements_for_one_firm_does_not_n_plus_one(): void
    {
        $firm = Firm::factory()->activated()->create();
        $this->entitlement($firm);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $onePass = [];
        DB::listen(function ($query) use (&$onePass): void {
            $onePass[] = $query->sql;
        });
        $this->get(EntitlementOverrideResource::getUrl())->assertOk();
        $oneCount = count($onePass);

        for ($i = 0; $i < 9; $i++) {
            $this->entitlement($firm->fresh());
        }

        $tenPass = [];
        DB::listen(function ($query) use (&$tenPass): void {
            $tenPass[] = $query->sql;
        });
        $this->get(EntitlementOverrideResource::getUrl())->assertOk();
        $tenCount = count($tenPass);

        $this->assertLessThan(
            $oneCount + 9,
            $tenCount,
            'Adding 9 more rows to the same firm must not add ~9 extra queries — that would prove an N+1 pattern.'
        );
    }

    // --- Set Override action lifecycle ---

    public function test_set_override_action_creates_a_new_entitlement_and_writes_a_firm_scoped_audit_event(): void
    {
        $firm = Firm::factory()->activated()->create();
        $module = ModuleCatalog::factory()->create();

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');
        // Setting an override is now step-up protected (mission section
        // 80) — a platform admin override is the highest-precedence
        // entitlement source there is. Pre-verify, matching the
        // established convention in PlatformAiOversightPageTest.
        app(StepUpAuthenticationService::class)->markVerified('platform_admin');

        $test = Livewire::test(ListEntitlementOverrides::class);
        $test->assertOk();
        $test->mountTableAction(SetEntitlementOverrideAction::getDefaultName());
        $test->setTableActionData([
            'firm_uuid' => $firm->uuid,
            'module_code' => $module->module_code,
            'source' => EntitlementSource::AdminOverride->value,
            'enabled' => true,
            'reason' => 'Pilot access',
            // Duration is now an explicit choice — a blank end date can
            // no longer silently produce a permanent override (mission
            // section 45).
            'override_duration' => SetEntitlementOverrideAction::DURATION_PERMANENT,
            'permanent_acknowledged' => true,
        ]);
        $test->callMountedTableAction();
        $test->assertHasNoTableActionErrors();

        $entitlement = $this->runWithFirmContext($firm, fn () => FirmEntitlement::query()
            ->where('firm_id', $firm->id)
            ->where('module_code', $module->module_code)
            ->first());

        $this->assertNotNull($entitlement);
        $this->assertTrue($entitlement->enabled);
        $this->assertSame(EntitlementSource::AdminOverride, $entitlement->source);

        $audit = $this->runWithFirmContext($firm, fn () => SecurityEvent::query()
            ->where('firm_id', $firm->id)
            ->where('event_type', 'entitlement_override_set')
            ->first());
        $this->assertNotNull($audit);
        $this->assertSame($admin->id, $audit->actor_id);
    }

    public function test_a_read_only_auditor_cannot_set_an_override_even_when_also_holding_superadmin(): void
    {
        $firm = Firm::factory()->activated()->create();
        $module = ModuleCatalog::factory()->create();

        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, PlatformRoleCode::SuperAdmin);
        app(PlatformRoleService::class)->grant($admin, PlatformRoleCode::ReadOnlyAuditor);
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(ListEntitlementOverrides::class);
        $test->assertOk();
        $test->mountTableAction(SetEntitlementOverrideAction::getDefaultName());
        $test->setTableActionData([
            'firm_uuid' => $firm->uuid,
            'module_code' => $module->module_code,
            'source' => EntitlementSource::AdminOverride->value,
            'enabled' => true,
            'reason' => 'Should be blocked',
        ]);
        $test->callMountedTableAction();

        $count = $this->runWithFirmContext($firm, fn () => FirmEntitlement::query()
            ->where('firm_id', $firm->id)
            ->where('module_code', $module->module_code)
            ->count());
        $this->assertSame(0, $count, 'canMutate() must block a read_only_auditor from setting an override, even with SuperAdmin also held.');
    }

    public function test_an_implementation_specialist_cannot_set_an_override_management_is_narrower_than_read(): void
    {
        $firm = Firm::factory()->activated()->create();
        $module = ModuleCatalog::factory()->create();

        $admin = $this->adminWithRole(PlatformRoleCode::ImplementationSpecialist);
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(ListEntitlementOverrides::class);
        $test->assertOk();
        $test->mountTableAction(SetEntitlementOverrideAction::getDefaultName());
        $test->setTableActionData([
            'firm_uuid' => $firm->uuid,
            'module_code' => $module->module_code,
            'source' => EntitlementSource::AdminOverride->value,
            'enabled' => true,
            'reason' => 'Should be blocked',
        ]);
        $test->callMountedTableAction();

        $count = $this->runWithFirmContext($firm, fn () => FirmEntitlement::query()
            ->where('firm_id', $firm->id)
            ->where('module_code', $module->module_code)
            ->count());
        $this->assertSame(0, $count, 'canManageEntitlementOverrides() is narrower than canAccessEntitlementOverrides() — ImplementationSpecialist may read but not set overrides.');
    }
}
