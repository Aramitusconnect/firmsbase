<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformAdmin;

use App\Enums\DeletionRequestStatus;
use App\Enums\PlatformRoleCode;
use App\Enums\RetentionPolicyStatus;
use App\Enums\RetentionRecordType;
use App\Filament\Actions\Platform\DenyDeletionAction;
use App\Filament\Actions\Platform\FirstApproveDeletionAction;
use App\Filament\Actions\Platform\RequestDeletionApprovalAction;
use App\Filament\Actions\Platform\SecondApproveDeletionAction;
use App\Filament\Actions\Platform\SubmitDeletionRequestForApprovalAction;
use App\Filament\Resources\DeletionRequestResource;
use App\Filament\Resources\DeletionRequestResource\Pages\ListDeletionRequests;
use App\Filament\Resources\DeletionRequestResource\Pages\ViewDeletionRequest;
use App\Models\DeletionRequest;
use App\Models\Firm;
use App\Models\Matter;
use App\Models\PlatformAdmin;
use App\Models\RetentionPolicy;
use App\Models\SecurityEvent;
use App\Services\OffboardingExportService;
use App\Services\OffboardingRequestService;
use App\Services\PlatformRoleService;
use App\Services\TenantContextService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * DeletionRequestResourceTest — Phase 4 (FirmsVault Platform Admin
 * Control Center, "Operations, Governance, Support, and
 * Configuration"), Governance category. Route-level authorization,
 * cross-firm listing, and the full request -> submit-for-approval ->
 * request-approval -> first-approve -> second-approve ->
 * ReadyForExecution lifecycle (never "deleted"), plus the deny path and
 * the security_events audit trail HighRiskPlatformChangePolicyService
 * writes at every step.
 */
final class DeletionRequestResourceTest extends TestCase
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

    /**
     * A deletion request that is genuinely clearable: a Matter subject,
     * an already-elapsed retention policy, no legal hold, and no
     * verified export yet (SubmitDeletionRequestForApprovalAction's own
     * clearance check will fail until one exists).
     */
    private function clearableRequest(Firm $firm, Matter $matter): DeletionRequest
    {
        RetentionPolicy::factory()->create([
            'firm_id' => null,
            'record_type' => RetentionRecordType::Matter,
            'retention_period_days' => 1,
            'status' => RetentionPolicyStatus::Active,
        ]);

        $request = DeletionRequest::factory()->forFirm($firm)->create([
            'subject_type' => Matter::class,
            'subject_id' => $matter->id,
            'status' => DeletionRequestStatus::Requested,
        ]);
        $request->forceFill(['created_at' => now()->subYears(5)])->save();

        return $request->fresh();
    }

    public function test_navigation_is_hidden_when_no_admin_is_authenticated(): void
    {
        $this->assertFalse(DeletionRequestResource::canAccess());
    }

    public function test_guest_is_redirected_from_the_deletion_requests_list(): void
    {
        $this->get(DeletionRequestResource::getUrl())->assertRedirect($this->adminUrl('/login'));
    }

    public function test_a_platform_admin_with_no_role_is_forbidden(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        $this->actingAs($admin, 'platform_admin')->get(DeletionRequestResource::getUrl())->assertForbidden();
    }

    public function test_a_super_admin_can_reach_the_list_and_view_pages(): void
    {
        $firm = Firm::factory()->create(['name' => 'Deletion Firm']);
        $request = DeletionRequest::factory()->forFirm($firm)->create();

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $listResponse = $this->get(DeletionRequestResource::getUrl());
        $listResponse->assertOk();
        $listResponse->assertSee('Deletion Firm');

        $viewResponse = $this->get(ViewDeletionRequest::getUrl(['firmUuid' => $firm->uuid, 'id' => $request->id]));
        $viewResponse->assertOk();
    }

    public function test_viewing_a_request_under_the_wrong_firm_404s(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $request = DeletionRequest::factory()->forFirm($firmA)->create();

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $this->actingAs($admin, 'platform_admin')
            ->get(ViewDeletionRequest::getUrl(['firmUuid' => $firmB->uuid, 'id' => $request->id]))
            ->assertNotFound();
    }

    public function test_ready_for_execution_is_labeled_approved_for_execution_never_deleted(): void
    {
        $firm = Firm::factory()->create();
        $request = DeletionRequest::factory()->forFirm($firm)->create(['status' => DeletionRequestStatus::ReadyForExecution]);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $response = $this->get(ViewDeletionRequest::getUrl(['firmUuid' => $firm->uuid, 'id' => $request->id]));
        $response->assertOk();
        $response->assertSee('Approved for execution');
        $response->assertDontSee('Deleted');
    }

    public function test_an_honest_empty_state_is_shown_when_no_requests_exist(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $response = $this->actingAs($admin, 'platform_admin')->get(DeletionRequestResource::getUrl());
        $response->assertOk();
        $response->assertSee('No deletion requests found');
        $response->assertSee('approved for execution');
    }

    // --- No-N+1 proof ---

    public function test_listing_many_requests_for_one_firm_does_not_n_plus_one(): void
    {
        $firm = Firm::factory()->create();
        DeletionRequest::factory()->forFirm($firm)->create();

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $onePass = [];
        DB::listen(function ($query) use (&$onePass): void {
            $onePass[] = $query->sql;
        });
        $this->get(DeletionRequestResource::getUrl())->assertOk();
        $oneCount = count($onePass);

        DeletionRequest::factory()->forFirm($firm)->count(9)->create();

        $tenPass = [];
        DB::listen(function ($query) use (&$tenPass): void {
            $tenPass[] = $query->sql;
        });
        $this->get(DeletionRequestResource::getUrl())->assertOk();
        $tenCount = count($tenPass);

        $this->assertLessThan($oneCount + 9, $tenCount);
    }

    // --- Full lifecycle: Requested -> ... -> ReadyForExecution ---

    public function test_the_full_approval_lifecycle_reaches_ready_for_execution_and_never_deletes_the_matter(): void
    {
        $firm = Firm::factory()->create();
        $matter = Matter::factory()->create(['firm_id' => $firm->id]);
        $request = $this->clearableRequest($firm, $matter);

        $admin1 = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $admin2 = $this->adminWithRole(PlatformRoleCode::PlatformAdmin);

        $this->actingAs($admin1, 'platform_admin');

        // Step 1: submit-for-approval fails until a verified export
        // exists (checkClearance()'s export gate) — proves the
        // clearance check is genuinely wired, not skipped.
        $test = Livewire::test(ListDeletionRequests::class);
        $test->assertOk();
        $test->mountTableAction(SubmitDeletionRequestForApprovalAction::getDefaultName(), '0');
        $test->callMountedTableAction();

        $stillRequested = $this->runWithFirmContext($firm, fn () => $request->fresh());
        $this->assertNotSame(DeletionRequestStatus::PendingApproval, $stillRequested->status);

        // Provide the verified export and retry.
        $offboardingRequest = app(OffboardingRequestService::class)->request($firm, $admin1, 'Offboarding for deletion.');
        $export = app(OffboardingExportService::class)->generate($offboardingRequest, requestedByPlatformAdmin: $admin1);
        app(OffboardingExportService::class)->verify($export, $admin1);

        $this->runWithFirmContext($firm, fn () => $request->update(['offboarding_export_id' => $export->id]));

        $test2 = Livewire::test(ListDeletionRequests::class);
        $test2->assertOk();
        $test2->mountTableAction(SubmitDeletionRequestForApprovalAction::getDefaultName(), '0');
        $test2->callMountedTableAction();
        $test2->assertHasNoTableActionErrors();

        $pending = $this->runWithFirmContext($firm, fn () => $request->fresh());
        $this->assertSame(DeletionRequestStatus::PendingApproval, $pending->status);

        // Step 2: request approval.
        $test3 = Livewire::test(ListDeletionRequests::class);
        $test3->assertOk();
        $test3->mountTableAction(RequestDeletionApprovalAction::getDefaultName(), '0');
        $test3->setTableActionData(['reason' => 'Retention cleared, export verified.']);
        $test3->callMountedTableAction();
        $test3->assertHasNoTableActionErrors();

        // HighRiskPlatformChangePolicyService::audit() writes with
        // firm_id => null and no null-bypass-under-any-context read
        // policy (unlike health_checks/incident_events) — security_events'
        // own FORCE RLS read policy only makes a null-firm_id row visible
        // when NO tenant context is active, so this must be read under
        // runWithoutFirmContext(), not runWithFirmContext().
        $audited = app(TenantContextService::class)->runWithoutFirmContext(fn () => SecurityEvent::query()
            ->where('event_type', 'high_risk_change_requested')
            ->exists());
        $this->assertTrue($audited, 'Requesting approval must write a high_risk_change_requested security_events row.');

        // Step 3: first approve.
        $test4 = Livewire::test(ListDeletionRequests::class);
        $test4->assertOk();
        $test4->mountTableAction(FirstApproveDeletionAction::getDefaultName(), '0');
        $test4->callMountedTableAction();
        $test4->assertHasNoTableActionErrors();

        // Step 4: second approve, by a DIFFERENT admin.
        $this->actingAs($admin2, 'platform_admin');

        $test5 = Livewire::test(ListDeletionRequests::class);
        $test5->assertOk();
        $test5->mountTableAction(SecondApproveDeletionAction::getDefaultName(), '0');
        $test5->callMountedTableAction();
        $test5->assertHasNoTableActionErrors();

        $final = $this->runWithFirmContext($firm, fn () => $request->fresh());
        $this->assertSame(DeletionRequestStatus::ReadyForExecution, $final->status);

        // Never physically deletes the underlying Matter.
        $this->runWithFirmContext($firm, fn () => $this->assertDatabaseHas('matters', ['id' => $matter->id]));
    }

    // --- Deny path ---

    public function test_deny_action_denies_the_request_and_writes_a_security_event(): void
    {
        $firm = Firm::factory()->create();
        $request = DeletionRequest::factory()->forFirm($firm)->create(['status' => DeletionRequestStatus::PendingApproval]);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(ListDeletionRequests::class);
        $test->assertOk();
        $test->mountTableAction(RequestDeletionApprovalAction::getDefaultName(), '0');
        $test->setTableActionData(['reason' => 'Reason.']);
        $test->callMountedTableAction();
        $test->assertHasNoTableActionErrors();

        $test2 = Livewire::test(ListDeletionRequests::class);
        $test2->assertOk();
        $test2->mountTableAction(DenyDeletionAction::getDefaultName(), '0');
        $test2->setTableActionData(['reason' => 'Not actually cleared.']);
        $test2->callMountedTableAction();
        $test2->assertHasNoTableActionErrors();

        $fresh = $this->runWithFirmContext($firm, fn () => $request->fresh());
        $this->assertSame(DeletionRequestStatus::Denied, $fresh->status);

        $audited = app(TenantContextService::class)->runWithoutFirmContext(fn () => SecurityEvent::query()
            ->where('event_type', 'high_risk_change_denied')
            ->exists());
        $this->assertTrue($audited);
    }

    // --- Authorization ---

    public function test_a_role_without_manage_deletion_governance_cannot_request_approval(): void
    {
        $firm = Firm::factory()->create();
        $request = DeletionRequest::factory()->forFirm($firm)->create(['status' => DeletionRequestStatus::PendingApproval]);

        $admin = $this->adminWithRole(PlatformRoleCode::SecurityAuditor);
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(ListDeletionRequests::class);
        $test->assertOk();
        $test->mountTableAction(RequestDeletionApprovalAction::getDefaultName(), '0');
        $test->setTableActionData(['reason' => 'Attempted.']);
        $test->callMountedTableAction();

        $fresh = $this->runWithFirmContext($firm, fn () => $request->fresh());
        $this->assertNull($fresh->approval, 'canManageDeletionGovernance() must block a SecurityAuditor even though it can view this data.');
    }

    public function test_a_read_only_auditor_with_super_admin_also_held_still_cannot_request_approval(): void
    {
        $firm = Firm::factory()->create();
        $request = DeletionRequest::factory()->forFirm($firm)->create(['status' => DeletionRequestStatus::PendingApproval]);

        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, PlatformRoleCode::SuperAdmin);
        app(PlatformRoleService::class)->grant($admin, PlatformRoleCode::ReadOnlyAuditor);
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(ListDeletionRequests::class);
        $test->assertOk();
        $test->mountTableAction(RequestDeletionApprovalAction::getDefaultName(), '0');
        $test->setTableActionData(['reason' => 'Attempted.']);
        $test->callMountedTableAction();

        $fresh = $this->runWithFirmContext($firm, fn () => $request->fresh());
        $this->assertNull($fresh->approval, 'canMutate() must block a read_only_auditor, even with SuperAdmin also held.');
    }
}
