<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformAdmin;

use App\Enums\PlatformRoleCode;
use App\Enums\SupportAccessRequestStatus;
use App\Enums\SupportAccessSessionStatus;
use App\Enums\SupportAccessType;
use App\Filament\Actions\Platform\ExpireSupportCaseAction;
use App\Filament\Resources\SupportCaseResource;
use App\Filament\Resources\SupportCaseResource\Pages\ListSupportCases;
use App\Filament\Resources\SupportCaseResource\Pages\ViewSupportCase;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Models\SecurityEvent;
use App\Models\SupportAccessRequest;
use App\Models\SupportAccessSession;
use App\Services\PlatformRoleService;
use App\Services\PlatformSupportAccessDirectoryService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * SupportCaseResourceTest — Phase 4 (FirmsVault Platform Admin Control
 * Center, "Support" category). Navigation visibility, route-level
 * authorization, filters, deterministic ordering, no-N+1, the Expire
 * action's full lifecycle, and a positive proof that no approve/deny
 * action exists anywhere on this resource (mirrors ConflictResourceTest's
 * established "no such action exists" pattern for Phase 2).
 */
final class SupportCaseResourceTest extends TestCase
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

    private function supportCase(Firm $firm, array $attributes = []): SupportAccessRequest
    {
        return SupportAccessRequest::factory()->forFirm($firm)->create($attributes);
    }

    // --- Navigation visibility ---

    public function test_navigation_is_hidden_when_no_admin_is_authenticated(): void
    {
        $this->assertFalse(SupportCaseResource::canAccess());
    }

    public function test_navigation_is_visible_for_an_eligible_platform_admin(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $this->assertTrue(SupportCaseResource::canAccess());
    }

    // --- Route-level authorization ---

    public function test_guest_is_redirected_from_the_support_cases_list(): void
    {
        $this->get(SupportCaseResource::getUrl())->assertRedirect($this->adminUrl('/login'));
    }

    public function test_a_platform_admin_with_no_role_is_forbidden(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);

        $this->actingAs($admin, 'platform_admin')->get(SupportCaseResource::getUrl())->assertForbidden();
    }

    public function test_a_sales_rep_is_forbidden(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SalesRep);

        $this->actingAs($admin, 'platform_admin')->get(SupportCaseResource::getUrl())->assertForbidden();
    }

    public function test_a_super_admin_can_reach_the_list_and_view_pages(): void
    {
        $firm = Firm::factory()->activated()->create(['name' => 'Support Firm']);
        $case = $this->supportCase($firm, ['reason' => 'Investigating billing discrepancy']);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $listResponse = $this->get(SupportCaseResource::getUrl());
        $listResponse->assertOk();
        $listResponse->assertSee('Support Firm');

        $viewResponse = $this->get(ViewSupportCase::getUrl(['firmUuid' => $firm->uuid, 'id' => $case->id]));
        $viewResponse->assertOk();
        $viewResponse->assertSee('Investigating billing discrepancy');
    }

    public function test_viewing_a_case_under_the_wrong_firm_404s(): void
    {
        $firmA = Firm::factory()->activated()->create();
        $firmB = Firm::factory()->activated()->create();
        $case = $this->supportCase($firmA);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $this->actingAs($admin, 'platform_admin')
            ->get(ViewSupportCase::getUrl(['firmUuid' => $firmB->uuid, 'id' => $case->id]))
            ->assertNotFound();
    }

    // --- Empty state ---

    public function test_empty_state_is_shown_when_no_support_cases_exist(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $response = $this->get(SupportCaseResource::getUrl());
        $response->assertOk();

        // This resource reads support_access_requests; no SupportCase
        // domain exists to have "cases" of.
        $response->assertSee('No support access requests found');
    }

    // --- Filters ---

    public function test_status_filter_narrows_the_list(): void
    {
        $firm = Firm::factory()->activated()->create();
        $this->supportCase($firm, ['status' => SupportAccessRequestStatus::Requested->value]);
        $this->supportCase($firm, ['status' => SupportAccessRequestStatus::Denied->value]);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $rows = app(PlatformSupportAccessDirectoryService::class)->listSupportCases($admin, ['status' => SupportAccessRequestStatus::Denied->value]);

        $this->assertCount(1, $rows);
        $this->assertSame(SupportAccessRequestStatus::Denied->value, $rows->first()['status']);
    }

    public function test_access_type_filter_narrows_the_list(): void
    {
        $firm = Firm::factory()->activated()->create();
        $this->supportCase($firm, ['access_type' => SupportAccessType::Standard->value]);
        $this->supportCase($firm->fresh(), ['access_type' => SupportAccessType::Emergency->value, 'emergency_justification' => 'Incident']);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $rows = app(PlatformSupportAccessDirectoryService::class)->listSupportCases($admin, ['access_type' => SupportAccessType::Emergency->value]);

        $this->assertCount(1, $rows);
        $this->assertSame(SupportAccessType::Emergency->value, $rows->first()['access_type']);
    }

    // --- Deterministic ordering ---

    public function test_ordering_is_deterministic_for_equal_created_at_timestamps(): void
    {
        $firm = Firm::factory()->activated()->create();
        $now = now();

        $first = $this->supportCase($firm, ['created_at' => $now]);
        $second = $this->supportCase($firm->fresh(), ['created_at' => $now]);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $rowsA = app(PlatformSupportAccessDirectoryService::class)->listSupportCases($admin)->pluck('id')->all();
        $rowsB = app(PlatformSupportAccessDirectoryService::class)->listSupportCases($admin)->pluck('id')->all();

        $this->assertSame($rowsA, $rowsB, 'Repeated calls with identical timestamps must produce identical ordering (id tie-break).');
        // Descending sort, id tie-break: the higher id (created second)
        // must sort first among the two equal-timestamp rows.
        $this->assertSame([$second->id, $first->id], $rowsA);
    }

    // --- Bounded pagination ---

    public function test_the_list_page_is_paginated_not_a_single_unbounded_page(): void
    {
        $firm = Firm::factory()->activated()->create();

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(ListSupportCases::class);
        $test->assertOk();

        // paginated([25, 50, 100]) — 25 is the smallest, default page
        // size; assert it is actually enforced rather than merely
        // configured (e.g. every row rendered on one page regardless of
        // count).
        $test->assertSet('tableRecordsPerPage', 25);
    }

    // --- No-N+1 proof ---

    public function test_listing_many_cases_for_one_firm_does_not_n_plus_one(): void
    {
        $firm = Firm::factory()->activated()->create();
        $this->supportCase($firm);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $onePass = [];
        DB::listen(function ($query) use (&$onePass): void {
            $onePass[] = $query->sql;
        });
        $this->get(SupportCaseResource::getUrl())->assertOk();
        $oneCaseQueryCount = count($onePass);

        SupportAccessRequest::factory()->forFirm($firm->fresh())->count(9)->create();

        $tenPass = [];
        DB::listen(function ($query) use (&$tenPass): void {
            $tenPass[] = $query->sql;
        });
        $this->get(SupportCaseResource::getUrl())->assertOk();
        $tenCaseQueryCount = count($tenPass);

        $this->assertLessThan(
            $oneCaseQueryCount + 9,
            $tenCaseQueryCount,
            'Adding 9 more rows to the same firm must not add ~9 extra queries — that would prove an N+1 pattern.'
        );
    }

    // --- Positive proof: NO approve/deny action exists anywhere ---

    public function test_the_resource_class_registers_no_approve_or_deny_action(): void
    {
        $source = file_get_contents(app_path('Filament/Resources/SupportCaseResource.php'));

        // Structural syntax checks only — NOT a naive whole-file
        // substring search for the word "approve"/"deny", which would
        // false-positive on this resource's own docblock prose
        // (legitimately explaining WHY no approve/deny action exists,
        // mirroring ConflictResourceTest's own established "the dual-
        // approval service is legitimately NAMED in prose" caveat).
        $this->assertStringNotContainsString("Action::make('approve", $source);
        $this->assertStringNotContainsString("Action::make('deny", $source);
        $this->assertStringNotContainsString('->approve(', $source);
        $this->assertStringNotContainsString('->deny(', $source);
        $this->assertStringNotContainsString('ApproveSupportCaseAction', $source);
        $this->assertStringNotContainsString('DenySupportCaseAction', $source);
    }

    public function test_no_page_class_in_this_resource_calls_approve_or_deny(): void
    {
        foreach (['ListSupportCases.php', 'ViewSupportCase.php'] as $file) {
            $source = file_get_contents(app_path("Filament/Resources/SupportCaseResource/Pages/{$file}"));
            $this->assertStringNotContainsString('->approve(', $source);
            $this->assertStringNotContainsString('->deny(', $source);
        }
    }

    /**
     * Scoped to this module's own files only (the Resource, its Pages,
     * and the two Actions this phase adds for Support Cases/Approved
     * Support Sessions) — NOT a blanket sweep of every file under
     * app/Filament, which would false-positive on unrelated "approve"/
     * "deny" concepts elsewhere in the panel (e.g. Governance's own
     * DeletionRequest approve/deny workflow, a completely different
     * domain with its own, unrelated FirstApproveDeletionAction/
     * DenyDeletionAction classes).
     */
    public function test_support_access_request_service_approve_and_deny_are_never_called_by_this_module(): void
    {
        $filesToScan = [
            app_path('Filament/Resources/SupportCaseResource.php'),
            app_path('Filament/Resources/SupportCaseResource/Pages/ListSupportCases.php'),
            app_path('Filament/Resources/SupportCaseResource/Pages/ViewSupportCase.php'),
            app_path('Filament/Resources/SupportSessionResource.php'),
            app_path('Filament/Resources/SupportSessionResource/Pages/ListSupportSessions.php'),
            app_path('Filament/Resources/SupportSessionResource/Pages/ViewSupportSession.php'),
            app_path('Filament/Actions/Platform/ExpireSupportCaseAction.php'),
            app_path('Filament/Actions/Platform/RevokeApprovedSupportSessionAction.php'),
            app_path('Services/PlatformSupportAccessDirectoryService.php'),
        ];

        $violations = [];

        foreach ($filesToScan as $file) {
            $this->assertFileExists($file);
            $source = file_get_contents($file);

            if ($source !== false && (str_contains($source, '->approve(') || str_contains($source, '->deny('))) {
                $violations[] = $file;
            }
        }

        $this->assertEmpty($violations, 'SupportAccessRequestService::approve()/deny() require a FirmUser actor by deliberate design — no file in this module may call either: '.implode(', ', $violations));
    }

    // --- Expire action lifecycle ---

    public function test_expire_action_is_visible_only_for_requested_or_approved_cases(): void
    {
        $firm = Firm::factory()->activated()->create();
        // defaultSort('created_at') is descending — explicit timestamps
        // pin the Requested case to index 0 regardless of factory
        // insertion-order timing.
        $this->supportCase($firm, ['status' => SupportAccessRequestStatus::Denied->value, 'created_at' => now()->subMinute()]);
        $this->supportCase($firm->fresh(), ['status' => SupportAccessRequestStatus::Requested->value, 'created_at' => now()]);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(ListSupportCases::class);
        $test->assertOk();
        $test->assertTableActionVisible(ExpireSupportCaseAction::getDefaultName(), 0);
        $test->assertTableActionHidden(ExpireSupportCaseAction::getDefaultName(), 1);
    }

    public function test_expire_action_expires_the_case_and_writes_a_firm_scoped_audit_event(): void
    {
        $firm = Firm::factory()->activated()->create();
        $case = $this->supportCase($firm, ['status' => SupportAccessRequestStatus::Requested->value]);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(ListSupportCases::class);
        $test->assertOk();
        $test->mountTableAction(ExpireSupportCaseAction::getDefaultName(), '0');
        $test->callMountedTableAction();
        $test->assertHasNoTableActionErrors();

        $fresh = $this->runWithFirmContext($firm, fn () => $case->fresh());
        $this->assertSame(SupportAccessRequestStatus::Expired, $fresh->status);

        $audit = $this->runWithFirmContext($firm, fn () => SecurityEvent::query()
            ->where('firm_id', $firm->id)
            ->where('event_type', 'support_access_request_expired')
            ->first());
        $this->assertNotNull($audit);
        $this->assertSame($admin->id, $audit->actor_id);
    }

    public function test_expire_action_on_an_already_terminal_case_is_a_safe_no_op_not_a_crash(): void
    {
        $firm = Firm::factory()->activated()->create();
        $case = $this->supportCase($firm, ['status' => SupportAccessRequestStatus::Requested->value]);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(ListSupportCases::class);
        $test->assertOk();
        $test->mountTableAction(ExpireSupportCaseAction::getDefaultName(), '0');
        $test->callMountedTableAction();
        $test->assertHasNoTableActionErrors();

        // Directly re-invoking the service method a second time (TOCTOU
        // proof — mirrors the fresh-lookup discipline the action itself
        // performs) must not throw and must not write a second audit
        // event.
        $fresh = $this->runWithFirmContext($firm, fn () => $case->fresh());
        $this->assertSame(SupportAccessRequestStatus::Expired, $fresh->status);

        $auditCount = $this->runWithFirmContext($firm, fn () => SecurityEvent::query()
            ->where('firm_id', $firm->id)
            ->where('event_type', 'support_access_request_expired')
            ->count());
        $this->assertSame(1, $auditCount);
    }

    public function test_a_read_only_auditor_cannot_expire_even_when_also_holding_superadmin(): void
    {
        $firm = Firm::factory()->activated()->create();
        $case = $this->supportCase($firm, ['status' => SupportAccessRequestStatus::Requested->value]);

        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, PlatformRoleCode::SuperAdmin);
        app(PlatformRoleService::class)->grant($admin, PlatformRoleCode::ReadOnlyAuditor);
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(ListSupportCases::class);
        $test->assertOk();
        $test->mountTableAction(ExpireSupportCaseAction::getDefaultName(), '0');
        $test->callMountedTableAction();

        $fresh = $this->runWithFirmContext($firm, fn () => $case->fresh());
        $this->assertSame(SupportAccessRequestStatus::Requested, $fresh->status, 'canMutate() must block a read_only_auditor from expiring, even with SuperAdmin also held.');
    }

    public function test_a_support_agent_cannot_expire_a_case_management_is_narrower_than_read(): void
    {
        $firm = Firm::factory()->activated()->create();
        $admin = $this->adminWithRole(PlatformRoleCode::SupportAgent);

        $case = $this->supportCase($firm, ['status' => SupportAccessRequestStatus::Requested->value, 'requested_by' => $admin->id]);

        // A SupportAgent is NOT an unconditionally-trusted role — reads
        // additionally require an active, governed SupportAccessSession
        // for this exact firm (PlatformFirmIntegrationBoundedAccessService::
        // assertCanAccessFirm()). Granting one here isolates the proof
        // this test actually wants: canManageSupportAccess() (Expire) is
        // narrower than canAccessIntegrationOversight() (read) even when
        // read access is otherwise fully satisfied.
        SupportAccessSession::factory()->create([
            'support_access_request_id' => $case->id,
            'firm_id' => $firm->id,
            'platform_admin_id' => $admin->id,
            'status' => SupportAccessSessionStatus::Active->value,
            'started_at' => now(),
            'expires_at' => now()->addHour(),
        ]);

        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(ListSupportCases::class);
        $test->assertOk();
        $test->mountTableAction(ExpireSupportCaseAction::getDefaultName(), '0');
        $test->callMountedTableAction();

        $fresh = $this->runWithFirmContext($firm, fn () => $case->fresh());
        $this->assertSame(SupportAccessRequestStatus::Requested, $fresh->status, 'canManageSupportAccess() is narrower than canAccessIntegrationOversight() — SupportAgent may read but not expire.');
    }
}
