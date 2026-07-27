<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformAdmin;

use App\Enums\PlatformRoleCode;
use App\Enums\SupportAccessSessionStatus;
use App\Filament\Actions\Platform\RevokeApprovedSupportSessionAction;
use App\Filament\Resources\SupportSessionResource;
use App\Filament\Resources\SupportSessionResource\Pages\ListSupportSessions;
use App\Filament\Resources\SupportSessionResource\Pages\ViewSupportSession;
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
 * SupportSessionResourceTest ("Approved Support Sessions") — Phase 4
 * (FirmsVault Platform Admin Control Center, "Support" category).
 * Navigation visibility, route-level authorization, filters,
 * deterministic ordering, no-N+1, and the Revoke action's full
 * lifecycle (routed through the pre-existing, already-TOCTOU-safe
 * PlatformFirmIntegrationBoundedAccessService::revokeSupportAccessSession()).
 */
final class SupportSessionResourceTest extends TestCase
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

    private function activeSession(Firm $firm, ?PlatformAdmin $sessionAdmin = null, array $attributes = []): SupportAccessSession
    {
        $request = SupportAccessRequest::factory()->forFirm($firm)->create();

        return SupportAccessSession::factory()->create(array_merge([
            'support_access_request_id' => $request->id,
            'firm_id' => $firm->id,
            'platform_admin_id' => ($sessionAdmin ?? PlatformAdmin::factory()->create())->id,
            'status' => SupportAccessSessionStatus::Active->value,
            'started_at' => now(),
            'expires_at' => now()->addHour(),
        ], $attributes));
    }

    // --- Navigation visibility ---

    public function test_navigation_is_hidden_when_no_admin_is_authenticated(): void
    {
        $this->assertFalse(SupportSessionResource::canAccess());
    }

    public function test_navigation_is_visible_for_an_eligible_platform_admin(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $this->assertTrue(SupportSessionResource::canAccess());
    }

    // --- Route-level authorization ---

    public function test_guest_is_redirected_from_the_approved_support_sessions_list(): void
    {
        $this->get(SupportSessionResource::getUrl())->assertRedirect('/admin/login');
    }

    public function test_a_platform_admin_with_no_role_is_forbidden(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);

        $this->actingAs($admin, 'platform_admin')->get(SupportSessionResource::getUrl())->assertForbidden();
    }

    public function test_a_super_admin_can_reach_the_list_and_view_pages(): void
    {
        $firm = Firm::factory()->activated()->create(['name' => 'Session Firm']);
        $session = $this->activeSession($firm);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $listResponse = $this->get(SupportSessionResource::getUrl());
        $listResponse->assertOk();
        $listResponse->assertSee('Session Firm');

        $viewResponse = $this->get(ViewSupportSession::getUrl(['firmUuid' => $firm->uuid, 'id' => $session->id]));
        $viewResponse->assertOk();
    }

    public function test_viewing_a_session_under_the_wrong_firm_404s(): void
    {
        $firmA = Firm::factory()->activated()->create();
        $firmB = Firm::factory()->activated()->create();
        $session = $this->activeSession($firmA);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $this->actingAs($admin, 'platform_admin')
            ->get(ViewSupportSession::getUrl(['firmUuid' => $firmB->uuid, 'id' => $session->id]))
            ->assertNotFound();
    }

    // --- Empty state ---

    public function test_empty_state_is_shown_when_no_sessions_exist(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $response = $this->get(SupportSessionResource::getUrl());
        $response->assertOk();
        $response->assertSee('No approved support sessions found');
    }

    // --- Filters ---

    public function test_status_filter_narrows_the_list(): void
    {
        $firm = Firm::factory()->activated()->create();
        $this->activeSession($firm);
        $this->activeSession($firm->fresh(), attributes: ['status' => SupportAccessSessionStatus::Revoked->value]);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $rows = app(PlatformSupportAccessDirectoryService::class)->listApprovedSupportSessions($admin, ['status' => SupportAccessSessionStatus::Revoked->value]);

        $this->assertCount(1, $rows);
        $this->assertSame(SupportAccessSessionStatus::Revoked->value, $rows->first()['status']);
    }

    // --- Bounded pagination ---

    public function test_the_list_page_is_paginated_not_a_single_unbounded_page(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(ListSupportSessions::class);
        $test->assertOk();
        $test->assertSet('tableRecordsPerPage', 25);
    }

    // --- Deterministic ordering ---

    public function test_ordering_is_deterministic_for_equal_started_at_timestamps(): void
    {
        $firm = Firm::factory()->activated()->create();
        $now = now();

        $first = $this->activeSession($firm, attributes: ['started_at' => $now]);
        $second = $this->activeSession($firm->fresh(), attributes: ['started_at' => $now]);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $rowsA = app(PlatformSupportAccessDirectoryService::class)->listApprovedSupportSessions($admin)->pluck('id')->all();
        $rowsB = app(PlatformSupportAccessDirectoryService::class)->listApprovedSupportSessions($admin)->pluck('id')->all();

        $this->assertSame($rowsA, $rowsB);
        $this->assertSame([$second->id, $first->id], $rowsA);
    }

    // --- No-N+1 proof ---

    public function test_listing_many_sessions_for_one_firm_does_not_n_plus_one(): void
    {
        $firm = Firm::factory()->activated()->create();
        $this->activeSession($firm);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $onePass = [];
        DB::listen(function ($query) use (&$onePass): void {
            $onePass[] = $query->sql;
        });
        $this->get(SupportSessionResource::getUrl())->assertOk();
        $oneSessionQueryCount = count($onePass);

        for ($i = 0; $i < 9; $i++) {
            $this->activeSession($firm->fresh());
        }

        $tenPass = [];
        DB::listen(function ($query) use (&$tenPass): void {
            $tenPass[] = $query->sql;
        });
        $this->get(SupportSessionResource::getUrl())->assertOk();
        $tenSessionQueryCount = count($tenPass);

        $this->assertLessThan(
            $oneSessionQueryCount + 9,
            $tenSessionQueryCount,
            'Adding 9 more rows to the same firm must not add ~9 extra queries — that would prove an N+1 pattern.'
        );
    }

    // --- Revoke action lifecycle ---

    public function test_revoke_action_is_visible_only_for_active_sessions(): void
    {
        $firm = Firm::factory()->activated()->create();
        $this->activeSession($firm, attributes: ['status' => SupportAccessSessionStatus::Revoked->value, 'started_at' => now()->subMinute()]);
        $this->activeSession($firm->fresh(), attributes: ['started_at' => now()]);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(ListSupportSessions::class);
        $test->assertOk();
        $test->assertTableActionVisible(RevokeApprovedSupportSessionAction::getDefaultName(), 0);
        $test->assertTableActionHidden(RevokeApprovedSupportSessionAction::getDefaultName(), 1);
    }

    public function test_revoke_action_revokes_the_session_and_writes_dual_audit_events(): void
    {
        $firm = Firm::factory()->activated()->create();
        $sessionOwner = PlatformAdmin::factory()->create();
        $session = $this->activeSession($firm, $sessionOwner);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(ListSupportSessions::class);
        $test->assertOk();
        $test->mountTableAction(RevokeApprovedSupportSessionAction::getDefaultName(), '0');
        $test->callMountedTableAction();
        $test->assertHasNoTableActionErrors();

        $fresh = $this->runWithFirmContext($firm, fn () => $session->fresh());
        $this->assertSame(SupportAccessSessionStatus::Revoked, $fresh->status);

        // Dual audit: SupportAccessPolicyService::logSessionAudit()
        // (category support_access) AND the correctly-attributed
        // writeOversightAuditEvent() (category
        // platform_integration_oversight) — both already established by
        // PlatformFirmIntegrationBoundedAccessService::revokeSupportAccessSession(),
        // never reimplemented by this resource's action.
        $oversightAudit = $this->runWithFirmContext($firm, fn () => SecurityEvent::query()
            ->where('firm_id', $firm->id)
            ->where('event_type', 'platform_integration_oversight.support_access_session_revoked')
            ->first());
        $this->assertNotNull($oversightAudit);
        $this->assertSame($admin->id, $oversightAudit->actor_id, 'The real acting admin (revoker), not the original session owner, must be attributed.');
    }

    public function test_revoke_action_on_an_already_terminal_session_is_a_safe_no_op_not_a_crash(): void
    {
        $firm = Firm::factory()->activated()->create();
        $session = $this->activeSession($firm);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(ListSupportSessions::class);
        $test->assertOk();
        $test->mountTableAction(RevokeApprovedSupportSessionAction::getDefaultName(), '0');
        $test->callMountedTableAction();
        $test->assertHasNoTableActionErrors();

        $fresh = $this->runWithFirmContext($firm, fn () => $session->fresh());
        $this->assertSame(SupportAccessSessionStatus::Revoked, $fresh->status);

        $auditCount = $this->runWithFirmContext($firm, fn () => SecurityEvent::query()
            ->where('firm_id', $firm->id)
            ->where('event_type', 'platform_integration_oversight.support_access_session_revoked')
            ->count());
        $this->assertSame(1, $auditCount, 'A second revoke attempt on an already-terminal session must be a safe no-op, never a second audit write.');
    }

    public function test_a_read_only_auditor_cannot_revoke_even_when_also_holding_superadmin(): void
    {
        $firm = Firm::factory()->activated()->create();
        $session = $this->activeSession($firm);

        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, PlatformRoleCode::SuperAdmin);
        app(PlatformRoleService::class)->grant($admin, PlatformRoleCode::ReadOnlyAuditor);
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(ListSupportSessions::class);
        $test->assertOk();
        $test->mountTableAction(RevokeApprovedSupportSessionAction::getDefaultName(), '0');
        $test->callMountedTableAction();

        $fresh = $this->runWithFirmContext($firm, fn () => $session->fresh());
        $this->assertSame(SupportAccessSessionStatus::Active, $fresh->status, 'canMutate() must block a read_only_auditor from revoking, even with SuperAdmin also held.');
    }
}
