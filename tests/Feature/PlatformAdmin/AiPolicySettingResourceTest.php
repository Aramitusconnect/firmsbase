<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformAdmin;

use App\Enums\PlatformRoleCode;
use App\Filament\Actions\Platform\EditAiPolicySettingValueAction;
use App\Filament\Resources\AiPolicySettingResource;
use App\Filament\Resources\AiPolicySettingResource\Pages\ListAiPolicySettings;
use App\Filament\Resources\AiPolicySettingResource\Pages\ViewAiPolicySetting;
use App\Models\AiPolicySetting;
use App\Models\PlatformAdmin;
use App\Services\PlatformRoleService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * AiPolicySettingResourceTest ("AI Policy Settings", the honest,
 * narrowly-scoped relabeling of "Platform Settings") — Phase 4
 * (FirmsVault Platform Admin Control Center, "Configuration" category).
 * Navigation visibility, route-level authorization, no-N+1, and the
 * Edit Value action's full lifecycle. This resource is a real
 * Eloquent-backed table (AiPolicySetting is Global/no-RLS), so no
 * per-firm/cross-firm read machinery is exercised here.
 */
final class AiPolicySettingResourceTest extends TestCase
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

    // --- Navigation visibility ---

    public function test_navigation_is_hidden_when_no_admin_is_authenticated(): void
    {
        $this->assertFalse(AiPolicySettingResource::canAccess());
    }

    public function test_navigation_is_visible_for_an_eligible_platform_admin(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $this->assertTrue(AiPolicySettingResource::canAccess());
    }

    public function test_navigation_is_hidden_for_a_billing_admin(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::BillingAdmin);
        $this->actingAs($admin, 'platform_admin');

        $this->assertFalse(AiPolicySettingResource::canAccess());
    }

    public function test_a_security_auditor_can_view_but_the_gate_is_read_only(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SecurityAuditor);
        $this->actingAs($admin, 'platform_admin');

        $this->assertTrue(AiPolicySettingResource::canAccess());
    }

    // --- Route-level authorization ---

    public function test_guest_is_redirected_from_the_ai_policy_settings_list(): void
    {
        $this->get(AiPolicySettingResource::getUrl())->assertRedirect($this->adminUrl('/login'));
    }

    public function test_a_platform_admin_with_no_role_is_forbidden(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);

        $this->actingAs($admin, 'platform_admin')->get(AiPolicySettingResource::getUrl())->assertForbidden();
    }

    public function test_a_super_admin_can_reach_the_list_and_view_pages(): void
    {
        $setting = AiPolicySetting::factory()->create(['key' => 'firm_owned_ai_mode_globally_permitted']);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $listResponse = $this->get(AiPolicySettingResource::getUrl());
        $listResponse->assertOk();
        $listResponse->assertSee('firm_owned_ai_mode_globally_permitted');

        $viewResponse = $this->get(ViewAiPolicySetting::getUrl(['record' => $setting->getKey()]));
        $viewResponse->assertOk();
    }

    // --- Empty state ---

    public function test_empty_state_is_shown_when_no_settings_exist(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $response = $this->get(AiPolicySettingResource::getUrl());
        $response->assertOk();
        $response->assertSee('No AI policy settings configured yet');
    }

    // --- Sort ---

    public function test_the_list_is_sorted_alphabetically_by_key(): void
    {
        AiPolicySetting::factory()->create(['key' => 'zzz_last_key']);
        AiPolicySetting::factory()->create(['key' => 'aaa_first_key']);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $response = $this->get(AiPolicySettingResource::getUrl());
        $response->assertOk();
        $response->assertSeeInOrder(['aaa_first_key', 'zzz_last_key']);
    }

    // --- Bounded pagination ---

    public function test_the_list_page_is_paginated_not_a_single_unbounded_page(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(ListAiPolicySettings::class);
        $test->assertOk();
        $test->assertSet('tableRecordsPerPage', 25);
    }

    // --- Secret-safety: value is escaped, never raw HTML ---

    public function test_the_resource_never_calls_html_on_the_value_column(): void
    {
        $source = file_get_contents(app_path('Filament/Resources/AiPolicySettingResource.php'));
        $viewSource = file_get_contents(app_path('Filament/Resources/AiPolicySettingResource/Pages/ViewAiPolicySetting.php'));
        $this->assertStringNotContainsString('->html(', $source, 'value_json must always be rendered as escaped text, never raw/unescaped HTML.');
        $this->assertStringNotContainsString('->html(', $viewSource, 'value_json must always be rendered as escaped text, never raw/unescaped HTML.');
    }

    // --- No-N+1 proof ---

    public function test_listing_many_settings_does_not_n_plus_one(): void
    {
        AiPolicySetting::factory()->create();

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $onePass = [];
        DB::listen(function ($query) use (&$onePass): void {
            $onePass[] = $query->sql;
        });
        $this->get(AiPolicySettingResource::getUrl())->assertOk();
        $oneCount = count($onePass);

        AiPolicySetting::factory()->count(9)->create();

        $tenPass = [];
        DB::listen(function ($query) use (&$tenPass): void {
            $tenPass[] = $query->sql;
        });
        $this->get(AiPolicySettingResource::getUrl())->assertOk();
        $tenCount = count($tenPass);

        $this->assertLessThan(
            $oneCount + 9,
            $tenCount,
            'Adding 9 more rows must not add ~9 extra queries — that would prove an N+1 pattern.'
        );
    }

    // --- Edit Value action lifecycle ---

    public function test_edit_value_action_updates_the_value_and_writes_a_platform_level_audit_event(): void
    {
        $setting = AiPolicySetting::factory()->create(['key' => 'editable_key', 'value_json' => ['enabled' => true]]);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(ListAiPolicySettings::class);
        $test->assertOk();
        $test->mountTableAction(EditAiPolicySettingValueAction::getDefaultName(), $setting->getKey());
        $test->setTableActionData(['value_json' => json_encode(['enabled' => false])]);
        $test->callMountedTableAction();
        $test->assertHasNoTableActionErrors();

        $fresh = $setting->fresh();
        $this->assertSame(['enabled' => false], $fresh->value_json);

        $audit = DB::table('security_events')
            ->where('event_type', 'ai_policy_setting_updated')
            ->first();
        $this->assertNotNull($audit);
        $this->assertNull($audit->firm_id);
        $this->assertSame($admin->id, $audit->actor_id);
    }

    public function test_edit_value_action_rejects_invalid_json(): void
    {
        $setting = AiPolicySetting::factory()->create(['key' => 'editable_key', 'value_json' => ['enabled' => true]]);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(ListAiPolicySettings::class);
        $test->assertOk();
        $test->mountTableAction(EditAiPolicySettingValueAction::getDefaultName(), $setting->getKey());
        $test->setTableActionData(['value_json' => '{not valid json']);
        $test->callMountedTableAction();

        $fresh = $setting->fresh();
        $this->assertSame(['enabled' => true], $fresh->value_json, 'Invalid JSON must be rejected — the row must remain unchanged.');
    }

    public function test_a_security_auditor_cannot_edit_a_value(): void
    {
        $setting = AiPolicySetting::factory()->create(['key' => 'editable_key', 'value_json' => ['enabled' => true]]);

        $admin = $this->adminWithRole(PlatformRoleCode::SecurityAuditor);
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(ListAiPolicySettings::class);
        $test->assertOk();
        $test->mountTableAction(EditAiPolicySettingValueAction::getDefaultName(), $setting->getKey());
        $test->setTableActionData(['value_json' => json_encode(['enabled' => false])]);
        $test->callMountedTableAction();

        $fresh = $setting->fresh();
        $this->assertSame(['enabled' => true], $fresh->value_json, 'SecurityAuditor may read AI policy settings but must never mutate them.');
    }

    public function test_a_read_only_auditor_cannot_edit_even_when_also_holding_superadmin(): void
    {
        $setting = AiPolicySetting::factory()->create(['key' => 'editable_key', 'value_json' => ['enabled' => true]]);

        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, PlatformRoleCode::SuperAdmin);
        app(PlatformRoleService::class)->grant($admin, PlatformRoleCode::ReadOnlyAuditor);
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(ListAiPolicySettings::class);
        $test->assertOk();
        $test->mountTableAction(EditAiPolicySettingValueAction::getDefaultName(), $setting->getKey());
        $test->setTableActionData(['value_json' => json_encode(['enabled' => false])]);
        $test->callMountedTableAction();

        $fresh = $setting->fresh();
        $this->assertSame(['enabled' => true], $fresh->value_json, 'canMutate() must block a read_only_auditor, even with SuperAdmin also held.');
    }
}
