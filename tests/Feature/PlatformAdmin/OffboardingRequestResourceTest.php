<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformAdmin;

use App\Enums\OffboardingExportStatus;
use App\Enums\OffboardingRequestStatus;
use App\Enums\PlatformRoleCode;
use App\Filament\Actions\Platform\CancelOffboardingRequestAction;
use App\Filament\Actions\Platform\CompleteOffboardingRequestAction;
use App\Filament\Actions\Platform\MarkOffboardingExportVerifiedAction;
use App\Filament\Resources\OffboardingRequestResource;
use App\Filament\Resources\OffboardingRequestResource\Pages\ListOffboardingRequests;
use App\Filament\Resources\OffboardingRequestResource\Pages\ViewOffboardingRequest;
use App\Models\Firm;
use App\Models\OffboardingExport;
use App\Models\OffboardingRequest;
use App\Models\PlatformAdmin;
use App\Services\PlatformRoleService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * OffboardingRequestResourceTest — Phase 4 (FirmsVault Platform Admin
 * Control Center, "Operations, Governance, Support, and
 * Configuration"), Governance category, Data Exports module.
 * Route-level authorization, cross-firm listing, the nested
 * offboarding-exports table with the Verify action, and the
 * Complete/Cancel action lifecycles.
 */
final class OffboardingRequestResourceTest extends TestCase
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

    public function test_navigation_is_hidden_when_no_admin_is_authenticated(): void
    {
        $this->assertFalse(OffboardingRequestResource::canAccess());
    }

    public function test_guest_is_redirected_from_the_offboarding_requests_list(): void
    {
        $this->get(OffboardingRequestResource::getUrl())->assertRedirect($this->adminUrl('/login'));
    }

    public function test_a_platform_admin_with_no_role_is_forbidden(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        $this->actingAs($admin, 'platform_admin')->get(OffboardingRequestResource::getUrl())->assertForbidden();
    }

    public function test_a_super_admin_can_reach_the_list_and_view_pages(): void
    {
        $firm = Firm::factory()->create(['name' => 'Offboarding Firm']);
        $request = OffboardingRequest::factory()->forFirm($firm)->create();

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $listResponse = $this->get(OffboardingRequestResource::getUrl());
        $listResponse->assertOk();
        $listResponse->assertSee('Offboarding Firm');

        $viewResponse = $this->get(ViewOffboardingRequest::getUrl(['firmUuid' => $firm->uuid, 'id' => $request->id]));
        $viewResponse->assertOk();
        $viewResponse->assertSee('No real file is ever produced');
    }

    public function test_viewing_a_request_under_the_wrong_firm_404s(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $request = OffboardingRequest::factory()->forFirm($firmA)->create();

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $this->actingAs($admin, 'platform_admin')
            ->get(ViewOffboardingRequest::getUrl(['firmUuid' => $firmB->uuid, 'id' => $request->id]))
            ->assertNotFound();
    }

    public function test_an_honest_empty_state_is_shown_when_no_requests_exist(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $response = $this->actingAs($admin, 'platform_admin')->get(OffboardingRequestResource::getUrl());
        $response->assertOk();
        $response->assertSee('No offboarding requests found');
    }

    // --- No-N+1 proof ---

    public function test_listing_many_requests_for_one_firm_does_not_n_plus_one(): void
    {
        $firm = Firm::factory()->create();
        OffboardingRequest::factory()->forFirm($firm)->create();

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $onePass = [];
        DB::listen(function ($query) use (&$onePass): void {
            $onePass[] = $query->sql;
        });
        $this->get(OffboardingRequestResource::getUrl())->assertOk();
        $oneCount = count($onePass);

        OffboardingRequest::factory()->forFirm($firm)->count(9)->create();

        $tenPass = [];
        DB::listen(function ($query) use (&$tenPass): void {
            $tenPass[] = $query->sql;
        });
        $this->get(OffboardingRequestResource::getUrl())->assertOk();
        $tenCount = count($tenPass);

        $this->assertLessThan($oneCount + 9, $tenCount);
    }

    // --- Complete action lifecycle ---

    public function test_complete_action_completes_a_ready_for_deletion_request(): void
    {
        $firm = Firm::factory()->create();
        $request = OffboardingRequest::factory()->forFirm($firm)->create(['status' => OffboardingRequestStatus::ReadyForDeletion]);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(ListOffboardingRequests::class);
        $test->assertOk();
        $test->mountTableAction(CompleteOffboardingRequestAction::getDefaultName(), '0');
        $test->callMountedTableAction();
        $test->assertHasNoTableActionErrors();

        $fresh = $this->runWithFirmContext($firm, fn () => $request->fresh());
        $this->assertSame(OffboardingRequestStatus::Completed, $fresh->status);
        $this->assertNotNull($fresh->completed_at);
    }

    public function test_complete_action_is_hidden_unless_ready_for_deletion(): void
    {
        $firm = Firm::factory()->create();
        OffboardingRequest::factory()->forFirm($firm)->create(['status' => OffboardingRequestStatus::Requested]);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(ListOffboardingRequests::class);
        $test->assertOk();
        $test->assertTableActionHidden(CompleteOffboardingRequestAction::getDefaultName(), '0');
    }

    public function test_a_role_without_manage_data_exports_cannot_complete_a_request(): void
    {
        $firm = Firm::factory()->create();
        $request = OffboardingRequest::factory()->forFirm($firm)->create(['status' => OffboardingRequestStatus::ReadyForDeletion]);

        $admin = $this->adminWithRole(PlatformRoleCode::SecurityAuditor);
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(ListOffboardingRequests::class);
        $test->assertOk();
        $test->mountTableAction(CompleteOffboardingRequestAction::getDefaultName(), '0');
        $test->callMountedTableAction();

        $fresh = $this->runWithFirmContext($firm, fn () => $request->fresh());
        $this->assertSame(OffboardingRequestStatus::ReadyForDeletion, $fresh->status, 'canManageDataExports() must block a SecurityAuditor from completing the request.');
    }

    // --- Cancel action lifecycle ---

    public function test_cancel_action_cancels_a_request(): void
    {
        $firm = Firm::factory()->create();
        $request = OffboardingRequest::factory()->forFirm($firm)->create(['status' => OffboardingRequestStatus::Requested]);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(ListOffboardingRequests::class);
        $test->assertOk();
        $test->mountTableAction(CancelOffboardingRequestAction::getDefaultName(), '0');
        $test->setTableActionData(['reason' => 'Firm reactivated its account.']);
        $test->callMountedTableAction();
        $test->assertHasNoTableActionErrors();

        $fresh = $this->runWithFirmContext($firm, fn () => $request->fresh());
        $this->assertSame(OffboardingRequestStatus::Cancelled, $fresh->status);
        $this->assertSame('Firm reactivated its account.', $fresh->cancelled_reason);
    }

    public function test_a_read_only_auditor_with_super_admin_also_held_still_cannot_cancel(): void
    {
        $firm = Firm::factory()->create();
        $request = OffboardingRequest::factory()->forFirm($firm)->create(['status' => OffboardingRequestStatus::Requested]);

        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, PlatformRoleCode::SuperAdmin);
        app(PlatformRoleService::class)->grant($admin, PlatformRoleCode::ReadOnlyAuditor);
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(ListOffboardingRequests::class);
        $test->assertOk();
        $test->mountTableAction(CancelOffboardingRequestAction::getDefaultName(), '0');
        $test->setTableActionData(['reason' => 'Attempted cancel.']);
        $test->callMountedTableAction();

        $fresh = $this->runWithFirmContext($firm, fn () => $request->fresh());
        $this->assertSame(OffboardingRequestStatus::Requested, $fresh->status, 'canMutate() must block a read_only_auditor.');
    }

    // --- Nested Offboarding Export + Verify action lifecycle ---

    public function test_the_nested_exports_table_shows_generated_exports_and_verify_verifies_them(): void
    {
        $firm = Firm::factory()->create();
        $request = OffboardingRequest::factory()->forFirm($firm)->create();
        $export = OffboardingExport::factory()->forOffboardingRequest($request)->create([
            'status' => OffboardingExportStatus::Generated,
            'package_manifest_json' => ['clients', 'matters'],
            'generated_at' => now(),
        ]);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $viewResponse = $this->get(ViewOffboardingRequest::getUrl(['firmUuid' => $firm->uuid, 'id' => $request->id]));
        $viewResponse->assertOk();

        $test = Livewire::test(ViewOffboardingRequest::class, ['firmUuid' => $firm->uuid, 'id' => $request->id]);
        $test->assertOk();
        $test->assertSee('clients');
        $test->mountTableAction(MarkOffboardingExportVerifiedAction::getDefaultName(), '0');
        $test->callMountedTableAction();
        $test->assertHasNoTableActionErrors();

        $fresh = $export->fresh();
        $this->assertSame(OffboardingExportStatus::Verified, $fresh->status);
        $this->assertSame($admin->id, $fresh->verified_by_platform_admin_id);
    }

    public function test_verify_action_never_leaks_across_firms_via_the_nested_table(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $requestA = OffboardingRequest::factory()->forFirm($firmA)->create();
        $requestB = OffboardingRequest::factory()->forFirm($firmB)->create();
        OffboardingExport::factory()->forOffboardingRequest($requestB)->create(['status' => OffboardingExportStatus::Generated]);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        // Viewing firm A's (export-less) offboarding request must show
        // an empty nested exports table — firm B's export must never
        // appear here, proving the join-through-the-parent discipline.
        $test = Livewire::test(ViewOffboardingRequest::class, ['firmUuid' => $firmA->uuid, 'id' => $requestA->id]);
        $test->assertOk();
        $this->assertCount(0, $test->instance()->getTableRecords());
    }
}
