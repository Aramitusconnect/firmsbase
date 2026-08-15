<?php

declare(strict_types=1);

namespace Tests\Feature\Configuration;

use App\Enums\ConsentChannel;
use App\Enums\EntitlementSource;
use App\Enums\NotificationTemplateStatus;
use App\Enums\PlatformRoleCode;
use App\Filament\Actions\Platform\ProposePracticeAreaMergeAction;
use App\Filament\Actions\Platform\RevertFirmNotificationTemplateOverrideAction;
use App\Filament\Actions\Platform\SetEntitlementOverrideAction;
use App\Filament\Resources\EntitlementOverrideResource\Pages\ListEntitlementOverrides;
use App\Filament\Resources\EntitlementOverrideResource\Pages\ViewEntitlementOverride;
use App\Filament\Resources\NotificationTemplateResource;
use App\Filament\Resources\NotificationTemplateResource\Pages\ListNotificationTemplates;
use App\Filament\Resources\PracticeAreaResource\Pages\ViewPracticeArea;
use App\Models\Firm;
use App\Models\FirmEntitlement;
use App\Models\MatterType;
use App\Models\ModuleCatalog;
use App\Models\NotificationEvent;
use App\Models\NotificationTemplate;
use App\Models\PlatformAdmin;
use App\Models\PracticeArea;
use App\Services\Configuration\PracticeAreaMergeProposalService;
use App\Services\EntitlementOverrideService;
use App\Services\EntitlementService;
use App\Services\NotificationTemplateService;
use App\Services\PlatformRoleService;
use App\Services\Security\StepUpAuthenticationService;
use App\Services\TenantContextService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Covers the operator-facing surfaces added in the completion pass:
 * the Practice Area detail page and merge proposal, the entitlement
 * resolution-trace/history page, notification template preview and
 * revert, and the step-up gates on the two platform-wide mutations.
 */
final class ConfigurationSurfaceActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function superAdmin(): PlatformAdmin
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, PlatformRoleCode::SuperAdmin);

        return $admin;
    }

    // ---------------- Practice Area detail page ----------------

    public function test_the_practice_area_page_shows_canonical_identity_and_dependency_truth(): void
    {
        $practiceArea = PracticeArea::factory()->create([
            'code' => 'zzz_surface_area',
            'name' => 'Zzz Surface Area',
            'synonyms' => ['Zzz Surface Alias'],
        ]);
        MatterType::factory()->forPracticeArea($practiceArea)->create();

        $response = $this->actingAs($this->superAdmin(), 'platform_admin')
            ->get(ViewPracticeArea::getUrl(['record' => $practiceArea->code]));

        $response->assertOk();
        $response->assertSee('Canonical identity');
        $response->assertSee('zzz_surface_area');
        $response->assertSee('Zzz Surface Alias');
    }

    public function test_the_practice_area_page_states_alias_resolution_and_hierarchy_are_not_implemented(): void
    {
        $practiceArea = PracticeArea::factory()->create(['code' => 'zzz_gap_area', 'name' => 'Zzz Gap Area']);

        $response = $this->actingAs($this->superAdmin(), 'platform_admin')
            ->get(ViewPracticeArea::getUrl(['record' => $practiceArea->code]));

        $response->assertOk();
        // Mission section 100 — absent capabilities are stated, not
        // left to be inferred from a missing panel.
        $response->assertSee('ALIAS RESOLUTION IS NOT IMPLEMENTED');
        $response->assertSee('Not implemented');
    }

    public function test_the_practice_area_page_names_tenant_dependencies_without_counting_them(): void
    {
        $practiceArea = PracticeArea::factory()->create(['code' => 'zzz_tenant_dep', 'name' => 'Zzz Tenant Dep']);

        $response = $this->actingAs($this->superAdmin(), 'platform_admin')
            ->get(ViewPracticeArea::getUrl(['record' => $practiceArea->code]));

        $response->assertOk();
        $response->assertSee('Not counted here');
        $response->assertSee('Matters (primary practice area)');
    }

    // ---------------- Merge proposal ----------------

    public function test_the_merge_proposal_action_builds_a_proposal_without_mutating_anything(): void
    {
        $source = PracticeArea::factory()->create(['code' => 'zzz_merge_a', 'name' => 'Zzz Merge Case']);
        $target = PracticeArea::factory()->create(['code' => 'zzz-merge-case', 'name' => 'Zzz merge case']);

        $this->actingAs($this->superAdmin(), 'platform_admin');

        $before = PracticeArea::query()->orderBy('id')->get()->toJson();

        $test = Livewire::test(ViewPracticeArea::class, ['record' => $source->code]);
        $test->mountAction(ProposePracticeAreaMergeAction::getDefaultName());
        $test->setActionData(['target_id' => $target->id, 'scan_tenant_scoped' => false]);
        $test->callMountedAction();
        $test->assertHasNoActionErrors();

        // Analysis only — nothing reassigned, deactivated or deleted.
        $this->assertSame($before, PracticeArea::query()->orderBy('id')->get()->toJson());
        $this->assertTrue($source->fresh()->is_active, 'the source must not be deactivated by building a proposal');
    }

    public function test_no_merge_execution_capability_is_exposed_anywhere(): void
    {
        $source = file_get_contents(app_path('Filament/Actions/Platform/ProposePracticeAreaMergeAction.php'));

        // Mission sections 36/96 — there must be no path from the
        // console to a real existing-data merge.
        $this->assertStringNotContainsString('executeMerge', $source);
        $this->assertStringNotContainsString('->merge(', $source);
        $this->assertFalse(method_exists(PracticeAreaMergeProposalService::class, 'execute'));
        $this->assertFalse(method_exists(PracticeAreaMergeProposalService::class, 'merge'));
    }

    // ---------------- Entitlement resolution trace page ----------------

    public function test_the_entitlement_page_shows_the_resolution_trace_and_winning_source(): void
    {
        $firm = Firm::factory()->activated()->create();
        $module = ModuleCatalog::factory()->create();
        $actor = PlatformAdmin::factory()->create();

        app(EntitlementService::class)->setForSource($firm, $module->module_code, EntitlementSource::Plan, true);
        $override = app(EntitlementOverrideService::class)->setOverrideAsPlatformAdmin(
            $firm, $module->module_code, EntitlementSource::AdminOverride, false, 'Disabled pending review', $actor,
            permanentAcknowledged: true,
        );

        $response = $this->actingAs($this->superAdmin(), 'platform_admin')
            ->get(ViewEntitlementOverride::getUrl(['firmUuid' => $firm->uuid, 'id' => $override->id]));

        $response->assertOk();
        $response->assertSee('Resolution trace');
        $response->assertSee('WINNING SOURCE');
        // The plan's own configured state is still reported honestly,
        // alongside why it lost.
        $response->assertSee('Outranked by');
    }

    public function test_the_entitlement_page_labels_a_permanent_override_unmistakably(): void
    {
        $firm = Firm::factory()->activated()->create();
        $module = ModuleCatalog::factory()->create();
        $actor = PlatformAdmin::factory()->create();

        $override = app(EntitlementOverrideService::class)->setOverrideAsPlatformAdmin(
            $firm, $module->module_code, EntitlementSource::FirmOverride, true, 'Permanent grant', $actor,
            permanentAcknowledged: true,
        );

        $this->actingAs($this->superAdmin(), 'platform_admin')
            ->get(ViewEntitlementOverride::getUrl(['firmUuid' => $firm->uuid, 'id' => $override->id]))
            ->assertOk()
            ->assertSee('Permanent — until revoked');
    }

    public function test_the_entitlement_page_shows_module_history(): void
    {
        $firm = Firm::factory()->activated()->create();
        $module = ModuleCatalog::factory()->create();
        $actor = PlatformAdmin::factory()->create();

        $override = app(EntitlementOverrideService::class)->setOverrideAsPlatformAdmin(
            $firm, $module->module_code, EntitlementSource::FirmOverride, true, 'Pilot access granted', $actor,
            permanentAcknowledged: true,
        );

        $this->actingAs($this->superAdmin(), 'platform_admin')
            ->get(ViewEntitlementOverride::getUrl(['firmUuid' => $firm->uuid, 'id' => $override->id]))
            ->assertOk()
            ->assertSee('History')
            ->assertSee('Pilot access granted');
    }

    // ---------------- Step-up gates (mission section 80) ----------------

    public function test_setting_an_override_without_a_fresh_step_up_verification_is_refused(): void
    {
        $firm = Firm::factory()->activated()->create();
        $module = ModuleCatalog::factory()->create();

        $this->actingAs($this->superAdmin(), 'platform_admin');

        $test = Livewire::test(ListEntitlementOverrides::class);
        $test->mountTableAction(SetEntitlementOverrideAction::getDefaultName());
        $test->setTableActionData([
            'firm_uuid' => $firm->uuid,
            'module_code' => $module->module_code,
            'source' => EntitlementSource::AdminOverride->value,
            'enabled' => true,
            'reason' => 'No step-up performed',
            'override_duration' => SetEntitlementOverrideAction::DURATION_PERMANENT,
            'permanent_acknowledged' => true,
        ]);
        $test->callMountedTableAction();
        $test->assertHasTableActionErrors(['stepUpCurrentPassword']);

        $this->assertSame(0, $this->entitlementCountFor($firm, $module->module_code));
    }

    public function test_setting_an_override_succeeds_after_a_fresh_step_up_verification(): void
    {
        $firm = Firm::factory()->activated()->create();
        $module = ModuleCatalog::factory()->create();

        $this->actingAs($this->superAdmin(), 'platform_admin');
        app(StepUpAuthenticationService::class)->markVerified('platform_admin');

        $test = Livewire::test(ListEntitlementOverrides::class);
        $test->mountTableAction(SetEntitlementOverrideAction::getDefaultName());
        $test->setTableActionData([
            'firm_uuid' => $firm->uuid,
            'module_code' => $module->module_code,
            'source' => EntitlementSource::AdminOverride->value,
            'enabled' => true,
            'reason' => 'Verified change',
            'override_duration' => SetEntitlementOverrideAction::DURATION_PERMANENT,
            'permanent_acknowledged' => true,
        ]);
        $test->callMountedTableAction();
        $test->assertHasNoTableActionErrors();

        $this->assertSame(1, $this->entitlementCountFor($firm, $module->module_code));
    }

    private function entitlementCountFor(Firm $firm, string $moduleCode): int
    {
        return (new TenantContextService)->runWithFirmContext(
            $firm,
            fn (): int => FirmEntitlement::query()
                ->where('firm_id', $firm->id)
                ->where('module_code', $moduleCode)
                ->count(),
        );
    }

    // ---------------- Notification template preview / revert ----------------

    public function test_the_template_preview_action_exposes_no_send_capability(): void
    {
        $source = file_get_contents(app_path('Filament/Actions/Platform/PreviewNotificationTemplateAction.php'));

        // Mission section 71: preview must never dispatch.
        //
        // Structural CALL-SYNTAX checks only, mirroring the discipline
        // NotificationTemplateResourceTest already documents for the
        // same reason: a naive whole-file search for the class names
        // would false-positive on this action's own docblock, which
        // legitimately explains in prose WHY it never calls the
        // dispatch path. What must be absent is an actual invocation.
        $this->assertStringNotContainsString('Mail::', $source);
        $this->assertStringNotContainsString('NotificationDispatchService $', $source);
        $this->assertStringNotContainsString('NotificationDispatchService::class', $source);
        $this->assertStringNotContainsString('DispatchNotificationJob::', $source);
        $this->assertStringNotContainsString('->dispatch(', $source);
        $this->assertStringNotContainsString('sendTest', $source);
    }

    public function test_previewing_a_template_writes_no_notification_event(): void
    {
        $template = NotificationTemplate::factory()->create([
            'firm_id' => null,
            'key' => 'zzz_preview_key',
            'channel' => ConsentChannel::Email,
            'status' => NotificationTemplateStatus::Active,
            'body' => 'Hello {{ client_name }}.',
        ]);

        $this->actingAs($this->superAdmin(), 'platform_admin');

        $before = NotificationEvent::query()->count();

        $this->get(NotificationTemplateResource::getUrl())->assertOk();

        $this->assertSame($before, NotificationEvent::query()->count());
        $this->assertNotNull($template->fresh());
    }

    public function test_reverting_a_firm_override_archives_it_and_restores_the_global_default(): void
    {
        $firm = Firm::factory()->activated()->create();
        $templates = app(NotificationTemplateService::class);

        $global = $templates->createGlobalDefault('zzz_revert_key', ConsentChannel::Email, 'Global body.');
        $override = $templates->createFirmOverride($firm, 'zzz_revert_key', ConsentChannel::Email, 'Firm body.');

        // resolve() does NOT self-wrap tenant context — its production
        // caller (NotificationDispatchService::dispatch) establishes it.
        // Called without context, notification_templates' RLS policy
        // (firm_id IS NULL OR firm_id = current_firm) hides the firm
        // override entirely and resolution silently falls through to the
        // global default, so this must be exercised inside the firm's
        // context to test what actually happens at dispatch time.
        $resolveForFirm = fn (): ?NotificationTemplate => (new TenantContextService)->runWithFirmContext(
            $firm,
            fn (): ?NotificationTemplate => $templates->resolve($firm, 'zzz_revert_key', ConsentChannel::Email),
        );

        // The firm override wins before reverting.
        $this->assertSame($override->id, $resolveForFirm()?->id);

        $templates->revertFirmOverride($override);

        // Now the global default wins, and the override row survives.
        $this->assertSame($global->id, $resolveForFirm()?->id);

        // Re-read inside the firm's context for the same RLS reason as
        // above: a context-less fresh() returns null whether the row was
        // archived or deleted, so it could never tell the two apart —
        // and "archived, not deleted" is the whole point of revert.
        $stored = (new TenantContextService)->runWithFirmContext(
            $firm,
            fn (): ?NotificationTemplate => NotificationTemplate::query()->find($override->id),
        );

        $this->assertNotNull($stored, 'reverting must archive, never delete');
        $this->assertSame(NotificationTemplateStatus::Archived, $stored->status);
    }

    public function test_reverting_a_global_default_is_refused(): void
    {
        $global = app(NotificationTemplateService::class)
            ->createGlobalDefault('zzz_global_only', ConsentChannel::Email, 'Global body.');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/nothing to revert to/i');

        app(NotificationTemplateService::class)->revertFirmOverride($global);
    }

    /**
     * Asserted against the action's own visibility rule rather than
     * through the table, because with no Firm filter selected the list
     * deliberately shows GLOBAL DEFAULTS ONLY (see
     * PlatformNotificationTemplateDirectoryService) — a firm override
     * row is simply not present to assert against there, which would
     * make a table-driven assertion prove nothing about the rule.
     */
    public function test_the_revert_action_is_only_offered_for_a_firm_override(): void
    {
        $this->assertTrue(RevertFirmNotificationTemplateOverrideAction::isRevertable([
            'firm_id' => 1,
            'status' => NotificationTemplateStatus::Active->value,
        ]));

        $this->assertFalse(
            RevertFirmNotificationTemplateOverrideAction::isRevertable([
                'firm_id' => null,
                'status' => NotificationTemplateStatus::Active->value,
            ]),
            'a global default has nothing to revert to and must never offer this action',
        );

        $this->assertFalse(
            RevertFirmNotificationTemplateOverrideAction::isRevertable([
                'firm_id' => 1,
                'status' => NotificationTemplateStatus::Archived->value,
            ]),
            'an already-archived override has nothing left to revert',
        );
    }

    public function test_the_list_page_still_renders_with_the_new_row_actions(): void
    {
        app(NotificationTemplateService::class)
            ->createGlobalDefault('zzz_listing_key', ConsentChannel::Email, 'Global body.');

        $this->actingAs($this->superAdmin(), 'platform_admin');

        Livewire::test(ListNotificationTemplates::class)->assertOk();
    }
}
