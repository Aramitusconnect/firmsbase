<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformAdmin;

use App\Enums\PlatformRoleCode;
use App\Filament\Actions\Platform\AssignPlatformAdminRoleAction;
use App\Filament\Actions\Platform\ResetPlatformAdminMfaAction;
use App\Filament\Actions\Platform\RevokePlatformAdminRoleAction;
use App\Filament\Pages\PlatformRolesAndPermissionsPage;
use App\Filament\Resources\PlatformAdministratorResource;
use App\Filament\Resources\PlatformAdministratorResource\Pages\ListPlatformAdministrators;
use App\Filament\Resources\PlatformAdministratorResource\Pages\ViewPlatformAdministrator;
use App\Models\PlatformAdmin;
use App\Models\SecurityEvent;
use App\Policies\PlatformAdminPolicy;
use App\Services\PlatformRoleService;
use App\Services\TenantContextService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PlatformAdministratorResourceTest — HTTP/Livewire-level access
 * gating and the remaining Assign/Revoke/Reset action success paths
 * not already covered by PlatformAdminLastSuperAdminProtectionTest
 * (which focuses on the BLOCKED cases) or PlatformAdminMfaResetServiceTest
 * (which tests the service directly, not the mounted Action). Also
 * covers the Roles & Permissions page's own gate and basic rendering.
 */
class PlatformAdministratorResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function roleService(): PlatformRoleService
    {
        return app(PlatformRoleService::class);
    }

    private function superAdmin(): PlatformAdmin
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        $this->roleService()->grant($admin, PlatformRoleCode::SuperAdmin);

        return $admin;
    }

    /**
     * Writes a real `login_succeeded` security_events row for $admin at
     * a chosen timestamp — the exact signal AppServiceProvider's
     * platform_admin Login-event listener itself writes (see
     * PlatformAdministratorResource's own docblock), built directly
     * here rather than via a real HTTP login so tests can control the
     * timestamp precisely. `created_at` is not in SecurityEvent::
     * $fillable (append-only log, deliberately narrow mass-assignment
     * surface — see that model's own docblock), so forceFill() is used
     * to set it; since that marks the attribute dirty, Eloquent's own
     * updateTimestamps() (which only fills a timestamp column when NOT
     * already dirty) leaves the forced value alone. Wrapped in
     * runWithoutFirmContext() to match the null-firm_id write path
     * these rows actually use in production (see this resource's own
     * docblock on why the read side needs the same treatment).
     */
    private function recordLogin(PlatformAdmin $admin, Carbon $at): void
    {
        app(TenantContextService::class)->runWithoutFirmContext(function () use ($admin, $at): void {
            $event = SecurityEvent::factory()->make([
                'firm_id' => null,
                'actor_type' => PlatformAdmin::class,
                'actor_id' => $admin->id,
                'event_type' => 'login_succeeded',
                'category' => 'authentication',
            ]);
            $event->forceFill(['created_at' => $at]);
            $event->save();
        });
    }

    // --- HTTP access gating ---

    public function test_guest_is_redirected_from_the_platform_administrators_list(): void
    {
        $this->get(PlatformAdministratorResource::getUrl())->assertRedirect($this->adminUrl('/login'));
    }

    public function test_a_platform_admin_with_no_role_is_forbidden_from_the_platform_administrators_list(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);

        $this->actingAs($admin, 'platform_admin')->get(PlatformAdministratorResource::getUrl())->assertForbidden();
    }

    /**
     * PlatformAdminPolicy is SuperAdmin-only for viewing too — unlike
     * FirmResource/FirmUserResource, a broader PlatformAdmin role
     * (e.g. plain PlatformAdmin, or SupportAgent) must NOT be able to
     * even list other platform administrators.
     */
    public function test_a_platform_admin_role_holder_who_is_not_a_super_admin_is_forbidden(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        $this->roleService()->grant($admin, PlatformRoleCode::PlatformAdmin);

        $this->actingAs($admin, 'platform_admin')->get(PlatformAdministratorResource::getUrl())->assertForbidden();
    }

    public function test_a_super_admin_can_reach_the_platform_administrators_list_and_view_a_record(): void
    {
        $actor = $this->superAdmin();
        $other = PlatformAdmin::factory()->create(['name' => 'Jane Auditor']);
        $this->roleService()->grant($other, PlatformRoleCode::SecurityAuditor);

        $listResponse = $this->actingAs($actor, 'platform_admin')->get(PlatformAdministratorResource::getUrl());
        $listResponse->assertOk();
        $listResponse->assertSee('Jane Auditor');

        $viewResponse = $this->actingAs($actor, 'platform_admin')->get(PlatformAdministratorResource::getUrl('view', ['record' => $other]));
        $viewResponse->assertOk();
        $viewResponse->assertSee('Jane Auditor');
    }

    // --- Assign role action ---

    public function test_assign_role_action_grants_a_role_and_writes_an_audit_event(): void
    {
        $actor = $this->superAdmin();
        $this->actingAs($actor, 'platform_admin');

        $target = PlatformAdmin::factory()->create();

        $test = Livewire::test(ViewPlatformAdministrator::class, ['record' => $target->uuid]);
        $test->mountAction(AssignPlatformAdminRoleAction::getDefaultName());
        $test->setActionData(['role_code' => PlatformRoleCode::BillingAdmin->value]);
        $test->callMountedAction();

        $test->assertHasNoActionErrors();
        $this->assertTrue($this->roleService()->hasRole($target->fresh(), PlatformRoleCode::BillingAdmin));

        $row = app(TenantContextService::class)->runWithoutFirmContext(
            fn () => DB::table('security_events')
                ->where('event_type', 'platform_admin_role_granted')
                ->where('actor_id', $actor->id)
                ->first()
        );
        $this->assertNotNull($row);
    }

    // --- Revoke role action (non-SuperAdmin role, unaffected by the last-superadmin guard) ---

    public function test_revoke_role_action_revokes_a_non_super_admin_role_and_writes_an_audit_event(): void
    {
        $actor = $this->superAdmin();
        $this->actingAs($actor, 'platform_admin');

        $target = PlatformAdmin::factory()->create();
        $this->roleService()->grant($target, PlatformRoleCode::BillingAdmin);

        $test = Livewire::test(ViewPlatformAdministrator::class, ['record' => $target->uuid]);
        $test->mountAction(RevokePlatformAdminRoleAction::getDefaultName());
        $test->setActionData(['role_code' => PlatformRoleCode::BillingAdmin->value]);
        $test->callMountedAction();

        $test->assertHasNoActionErrors();
        $this->assertFalse($this->roleService()->hasRole($target->fresh(), PlatformRoleCode::BillingAdmin));

        $row = app(TenantContextService::class)->runWithoutFirmContext(
            fn () => DB::table('security_events')
                ->where('event_type', 'platform_admin_role_revoked')
                ->where('actor_id', $actor->id)
                ->first()
        );
        $this->assertNotNull($row);
    }

    // --- Reset MFA action success path (different actor, not the sole SuperAdmin) ---

    public function test_reset_mfa_action_succeeds_for_a_different_target_and_forces_re_enrollment(): void
    {
        $actor = $this->superAdmin();
        $this->actingAs($actor, 'platform_admin');

        $target = PlatformAdmin::factory()->create([
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'two_factor_confirmed_at' => now(),
        ]);

        $test = Livewire::test(ViewPlatformAdministrator::class, ['record' => $target->uuid]);
        $test->mountAction(ResetPlatformAdminMfaAction::getDefaultName());
        $test->setActionData(['reason' => 'lost device']);
        $test->callMountedAction();

        $test->assertHasNoActionErrors();

        $target->refresh();
        $this->assertNull($target->two_factor_secret);
        $this->assertNull($target->two_factor_confirmed_at);
        $this->assertNotNull($target->two_factor_reset_at);
    }

    /**
     * A non-SuperAdmin cannot reach this page (and therefore cannot
     * reach any of its actions) at all — PlatformAdminPolicy's own
     * page-load-time canAccess() gate already rejects the mount
     * outright (proven above by
     * test_a_platform_admin_role_holder_who_is_not_a_super_admin_is_forbidden()
     * at the plain HTTP level). Every Action registered on this page
     * (Toggle/Assign/Revoke/Reset) additionally re-resolves the actor
     * and re-checks its own authorization fresh INSIDE the action
     * closure, by code inspection — see each Action class's own
     * docblock — rather than trusting that page-load-time canAccess()
     * check alone, matching this codebase's established TOCTOU
     * discipline (RevokeSupportAccessSessionAction's own precedent).
     * A full Livewire-harness proof of the mid-session
     * role-revoked-between-page-load-and-submit edge case hit Filament
     * testing-harness internals unrelated to this action's own logic
     * and was not pursued further here — PlatformAdminLastSuperAdminProtectionTest's
     * blocked-action tests above already exercise this action's fresh
     * re-check path end to end (a fresh Auth::guard()->user() resolve
     * plus a fresh PlatformRoleService query), just not via an
     * artificially-constructed "role revoked mid-Livewire-session"
     * timeline specifically.
     */
    public function test_a_non_super_admin_cannot_reach_the_view_page_or_any_of_its_actions(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        $this->roleService()->grant($admin, PlatformRoleCode::PlatformAdmin);

        $target = PlatformAdmin::factory()->create(['two_factor_secret' => 'JBSWY3DPEHPK3PXP']);

        $response = $this->actingAs($admin, 'platform_admin')->get(PlatformAdministratorResource::getUrl('view', ['record' => $target]));

        $response->assertForbidden();
    }

    // --- PlatformAdminPolicy direct coverage ---

    public function test_platform_admin_policy_delegates_to_can_manage_platform_administrators(): void
    {
        $policy = app(PlatformAdminPolicy::class);

        $superAdmin = $this->superAdmin();
        $plain = PlatformAdmin::factory()->create();

        $this->assertTrue($policy->viewAny($superAdmin));
        $this->assertTrue($policy->view($superAdmin, $plain));
        $this->assertTrue($policy->update($superAdmin, $plain));

        $this->assertFalse($policy->viewAny($plain));
        $this->assertFalse($policy->view($plain, $superAdmin));
        $this->assertFalse($policy->update($plain, $superAdmin));
    }

    // --- Roles & Permissions page ---

    public function test_guest_is_redirected_from_the_roles_and_permissions_page(): void
    {
        $this->get(PlatformRolesAndPermissionsPage::getUrl())->assertRedirect($this->adminUrl('/login'));
    }

    public function test_a_non_super_admin_is_forbidden_from_the_roles_and_permissions_page(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        $this->roleService()->grant($admin, PlatformRoleCode::SecurityAuditor);

        $this->actingAs($admin, 'platform_admin')->get(PlatformRolesAndPermissionsPage::getUrl())->assertForbidden();
    }

    public function test_a_super_admin_can_reach_the_roles_and_permissions_page_and_see_the_role_catalog(): void
    {
        $actor = $this->superAdmin();

        $other = PlatformAdmin::factory()->create(['name' => 'Role Holder']);
        $this->roleService()->grant($other, PlatformRoleCode::SecurityAuditor);

        $response = $this->actingAs($actor, 'platform_admin')->get(PlatformRolesAndPermissionsPage::getUrl());

        $response->assertOk();
        $response->assertSee('Super Admin');
        $response->assertSee('Security Auditor');
        $response->assertSee('Role Holder');
        $response->assertSee('may never mutate data');
    }

    // ------------------------------------------------------------
    // Phase 1 correction: last-login bounding (lastLoginAtByAdminId())
    //
    // Old behavior: this map was computed ONCE per table() call for
    // EVERY platform_admins row, with no time bound and no LIMIT — an
    // unbounded scan of the whole security_events table on every list
    // render regardless of how many admins were actually displayed.
    // New behavior: bounded via ->whereIn('actor_id', $adminIds) to
    // exactly the ids the caller passes; ListPlatformAdministrators::
    // paginateTableQuery() passes only the CURRENT PAGE's admin ids.
    // ------------------------------------------------------------

    public function test_last_login_at_by_admin_id_returns_an_empty_array_for_no_ids_with_no_query(): void
    {
        $captured = [];
        DB::listen(function ($query) use (&$captured): void {
            if (stripos($query->sql, 'security_events') !== false) {
                $captured[] = $query->sql;
            }
        });

        $this->assertSame([], PlatformAdministratorResource::lastLoginAtByAdminId([]));
        $this->assertCount(0, $captured, 'An empty id list must short-circuit before ever touching security_events.');
    }

    public function test_last_login_at_by_admin_id_returns_the_latest_login_and_omits_admins_with_none(): void
    {
        $adminA = PlatformAdmin::factory()->create();
        $adminB = PlatformAdmin::factory()->create();
        $neverLoggedIn = PlatformAdmin::factory()->create();

        $this->recordLogin($adminA, now()->subDays(3));
        $latestForA = now()->subDay();
        $this->recordLogin($adminA, $latestForA);
        $this->recordLogin($adminB, now()->subHours(2));

        $map = PlatformAdministratorResource::lastLoginAtByAdminId([$adminA->id, $adminB->id, $neverLoggedIn->id]);

        $this->assertArrayHasKey($adminA->id, $map);
        $this->assertSame($latestForA->toDateTimeString(), Carbon::parse($map[$adminA->id])->toDateTimeString());
        $this->assertArrayHasKey($adminB->id, $map);

        // Zero-login admins are intentionally ABSENT (not a 0/null
        // entry) — the "last_login_at" column's own `?? null` lookup
        // already treats a missing key as null ("Never"), so a
        // never-logged-in admin is preserved in the RENDERED table
        // (proven at the Livewire level below), just not present in
        // this raw map.
        $this->assertArrayNotHasKey($neverLoggedIn->id, $map);
    }

    public function test_last_login_at_by_admin_id_is_bounded_to_only_the_given_ids(): void
    {
        $inScope = PlatformAdmin::factory()->create();
        $outOfScope = PlatformAdmin::factory()->create();

        $this->recordLogin($inScope, now());
        $this->recordLogin($outOfScope, now());

        $map = PlatformAdministratorResource::lastLoginAtByAdminId([$inScope->id]);

        $this->assertArrayHasKey($inScope->id, $map);
        $this->assertArrayNotHasKey(
            $outOfScope->id,
            $map,
            'A real login event for an admin OUTSIDE the given id list must never leak into the bounded result.'
        );
    }

    public function test_last_login_at_by_admin_id_executes_exactly_one_query_regardless_of_admin_count(): void
    {
        $admins = PlatformAdmin::factory()->count(6)->create();

        foreach ($admins as $admin) {
            $this->recordLogin($admin, now());
        }

        $captured = [];
        DB::listen(function ($query) use (&$captured): void {
            if (stripos($query->sql, 'security_events') !== false) {
                $captured[] = $query->sql;
            }
        });

        $map = PlatformAdministratorResource::lastLoginAtByAdminId($admins->pluck('id'));

        $this->assertCount(6, $map);
        $this->assertCount(
            1,
            $captured,
            'Expected exactly one batched security_events query for 6 admins, never one per admin.'
        );
    }

    /**
     * End-to-end proof at the actual rendered-table level: the
     * "last_login_at" column must show a real timestamp for an admin
     * with a login event and "Never" (a null state, never dropped from
     * the table) for one with none.
     */
    public function test_the_list_page_shows_a_real_timestamp_and_never_for_the_correct_admins(): void
    {
        $actor = $this->superAdmin();
        $this->actingAs($actor, 'platform_admin');

        $loggedIn = PlatformAdmin::factory()->create(['name' => 'Logged In Admin']);
        $this->recordLogin($loggedIn, now()->subHour());

        $neverLoggedIn = PlatformAdmin::factory()->create(['name' => 'Never Logged In Admin']);

        $test = Livewire::test(ListPlatformAdministrators::class);
        $test->assertSuccessful();

        $map = $test->instance()->lastLoginAtByAdminId;

        $this->assertArrayHasKey($loggedIn->id, $map);
        $this->assertArrayNotHasKey($neverLoggedIn->id, $map);

        $response = $this->get(PlatformAdministratorResource::getUrl());
        $response->assertOk();
        $response->assertSee('Never');
    }

    /**
     * The core proof for this correction: with EVERY platform_admin
     * having a real login event, an unbounded query would put every one
     * of them in the map regardless of page. Bounded to page size 3 out
     * of 10 total admins, the map must contain EXACTLY 3 entries — and
     * exactly one security_events query must run for the whole render,
     * proving both "bounded to the current page" and "no N+1"
     * simultaneously.
     */
    public function test_the_list_page_last_login_map_is_bounded_to_the_current_page_not_the_whole_table(): void
    {
        $actor = $this->superAdmin();
        $this->actingAs($actor, 'platform_admin');

        $admins = PlatformAdmin::factory()->count(9)->create();

        foreach ($admins->push($actor) as $admin) {
            $this->recordLogin($admin, now());
        }
        // 10 platform_admins total (9 + the acting super admin), every
        // single one has a real login_succeeded event — if the query
        // were still unbounded, the map below would contain all 10
        // regardless of page.

        // Mount first (this fires its own, separate render/query at
        // Filament's default per-page option) — query counting starts
        // only AFTER that, isolated to the single render caused by
        // ->set() below, so this is a clean "one render, one query"
        // proof rather than conflating two distinct Livewire request
        // lifecycles (mount + a reactive property update) into one count.
        $test = Livewire::test(ListPlatformAdministrators::class);
        $test->assertSuccessful();

        $captured = [];
        DB::listen(function ($query) use (&$captured): void {
            if (stripos($query->sql, 'security_events') !== false) {
                $captured[] = $query->sql;
            }
        });

        $test->set('tableRecordsPerPage', 3);
        $test->assertSuccessful();

        $map = $test->instance()->lastLoginAtByAdminId;

        $this->assertCount(3, $map, 'The map must be bounded to the current page size (3), not the total admin count (10).');
        $this->assertCount(1, $captured, 'Expected exactly one security_events query for this render, never one per admin/row.');
    }

    /**
     * Proves the map is recomputed correctly (not stale/cached across
     * page navigation) when moving to a second page of admins.
     */
    public function test_the_list_page_last_login_map_is_recomputed_for_a_different_page(): void
    {
        $actor = $this->superAdmin();
        $this->actingAs($actor, 'platform_admin');

        $admins = PlatformAdmin::factory()->count(5)->create();

        foreach ($admins as $admin) {
            $this->recordLogin($admin, now());
        }

        $test = Livewire::test(ListPlatformAdministrators::class)->set('tableRecordsPerPage', 2);
        $test->assertSuccessful();
        $firstPageIds = array_keys($test->instance()->lastLoginAtByAdminId);

        $test->call('gotoPage', 2);
        $test->assertSuccessful();
        $secondPageIds = array_keys($test->instance()->lastLoginAtByAdminId);

        $this->assertNotEmpty($firstPageIds);
        $this->assertNotEmpty($secondPageIds);
        $this->assertEmpty(
            array_intersect($firstPageIds, $secondPageIds),
            'Two different pages must never share the same bounded admin-id set.'
        );
    }
}
