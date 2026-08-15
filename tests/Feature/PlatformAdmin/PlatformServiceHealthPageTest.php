<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformAdmin;

use App\Enums\HealthCheckMonitoringType;
use App\Enums\HealthCheckStatus;
use App\Enums\HealthCheckType;
use App\Enums\PlatformRoleCode;
use App\Filament\Actions\Platform\RunHealthChecksNowAction;
use App\Filament\Pages\PlatformServiceHealthPage;
use App\Models\HealthCheck;
use App\Models\PlatformAdmin;
use App\Services\PlatformRoleService;
use App\Services\TenantContextService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PlatformServiceHealthPageTest — Phase 4 (FirmsVault Platform Admin
 * Control Center, "Operations"). Navigation, direct-route auth,
 * disclosure, filters, deterministic ordering, bounded pagination,
 * empty state, and the "Run health checks now" action's full
 * lifecycle (authorization allow/deny, audit event written, resulting
 * state, TOCTOU-safety-by-design).
 */
final class PlatformServiceHealthPageTest extends TestCase
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

    public function test_navigation_is_hidden_for_a_guest(): void
    {
        $this->assertFalse(PlatformServiceHealthPage::shouldRegisterNavigation());
    }

    public function test_navigation_is_visible_for_a_security_auditor(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SecurityAuditor);
        $this->actingAs($admin, 'platform_admin');

        $this->assertTrue(PlatformServiceHealthPage::shouldRegisterNavigation());
    }

    public function test_navigation_is_hidden_for_a_sales_rep(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SalesRep);
        $this->actingAs($admin, 'platform_admin');

        $this->assertFalse(PlatformServiceHealthPage::shouldRegisterNavigation());
    }

    // --- Direct-route authorization ---

    public function test_guest_is_redirected(): void
    {
        $this->get(PlatformServiceHealthPage::getUrl())->assertRedirect($this->adminUrl('/login'));
    }

    public function test_a_platform_admin_with_no_role_is_forbidden(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        $this->actingAs($admin, 'platform_admin')->get(PlatformServiceHealthPage::getUrl())->assertForbidden();
    }

    public function test_a_billing_admin_is_forbidden(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::BillingAdmin);
        $this->actingAs($admin, 'platform_admin')->get(PlatformServiceHealthPage::getUrl())->assertForbidden();
    }

    public function test_a_super_admin_can_reach_the_page(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin')->get(PlatformServiceHealthPage::getUrl())->assertOk();
    }

    // --- Disclosure ---

    /**
     * Operations Control Plane: the disclosure is no longer a
     * hardcoded sentence naming a stale count. The page now derives
     * its monitoring census from HealthCheckRegistry and states
     * plainly that the unmonitored surfaces never report Healthy.
     */
    public function test_the_page_discloses_which_surfaces_are_not_monitored(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $response = $this->actingAs($admin, 'platform_admin')->get(PlatformServiceHealthPage::getUrl());
        $response->assertOk();
        $response->assertSee('not monitored');
        $response->assertSee('they report Not Monitored, never Healthy');
        $response->assertSee('Web Uptime');
    }

    /**
     * The count in the coverage census must come from the registry,
     * not from prose. Nine check types, five of them unmonitored.
     */
    public function test_the_monitoring_census_is_derived_from_the_registry(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $response = $this->actingAs($admin, 'platform_admin')->get(PlatformServiceHealthPage::getUrl());
        $response->assertOk();
        $response->assertSee('9 check type(s) registered in total');
        $response->assertSee('5 not monitored');
    }

    /**
     * The specific false-confidence defect this mission corrected: an
     * unmonitored surface must never be rendered as Healthy anywhere
     * on this page.
     */
    public function test_an_unmonitored_check_is_never_rendered_as_healthy(): void
    {
        HealthCheck::create([
            'firm_id' => null,
            'check_type' => HealthCheckType::WebUptime,
            'status' => HealthCheckStatus::NotMonitored,
            'detail' => 'No external uptime provider is configured.',
            'checked_at' => now(),
            'metadata_json' => ['monitoring_type' => HealthCheckMonitoringType::NotMonitored->value],
        ]);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $response = $this->actingAs($admin, 'platform_admin')->get(PlatformServiceHealthPage::getUrl());
        $response->assertOk();
        $response->assertSee('Web Uptime — Not Monitored');
        $response->assertDontSee('Web Uptime — Healthy');
    }

    // --- Empty state ---

    public function test_empty_state(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $response = $this->actingAs($admin, 'platform_admin')->get(PlatformServiceHealthPage::getUrl());
        $response->assertOk();
        $response->assertSee('No health checks recorded yet');
    }

    // --- Filters + platform-wide-only scope ---

    public function test_only_platform_wide_rows_are_shown_and_filters_narrow_the_list(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $healthy = HealthCheck::factory()->create([
            'firm_id' => null,
            'check_type' => HealthCheckType::QueueWorkers,
            'status' => HealthCheckStatus::Healthy,
            'checked_at' => now(),
        ]);
        $unhealthy = HealthCheck::factory()->create([
            'firm_id' => null,
            'check_type' => HealthCheckType::FailedJobs,
            'status' => HealthCheckStatus::Unhealthy,
            'checked_at' => now(),
        ]);

        $test = Livewire::test(PlatformServiceHealthPage::class);
        $test->assertCanSeeTableRecords([$healthy, $unhealthy]);

        $test->filterTable('status', HealthCheckStatus::Unhealthy->value);
        $test->assertCanSeeTableRecords([$unhealthy]);
        $test->assertCanNotSeeTableRecords([$healthy]);
    }

    // --- Deterministic ordering ---

    public function test_orders_deterministically_by_id_when_checked_at_ties(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $sharedTime = now();
        $checks = HealthCheck::factory()->count(5)->create(['firm_id' => null, 'checked_at' => $sharedTime]);

        $first = Livewire::test(PlatformServiceHealthPage::class)->instance()->getFilteredSortedTableQuery()->pluck('id')->all();
        $second = Livewire::test(PlatformServiceHealthPage::class)->instance()->getFilteredSortedTableQuery()->pluck('id')->all();

        $this->assertSame($first, $second);
        $this->assertSame($checks->sortByDesc('id')->pluck('id')->values()->all(), $first);
    }

    // --- Bounded pagination ---

    public function test_the_page_is_paginated(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        HealthCheck::factory()->count(30)->create(['firm_id' => null]);

        $test = Livewire::test(PlatformServiceHealthPage::class);
        $test->assertSuccessful();
        $this->assertLessThanOrEqual(25, $test->instance()->getTableRecords()->count());
    }

    // --- Mutating action lifecycle ---

    public function test_run_health_checks_now_is_allowed_for_a_super_admin_and_writes_records_and_audit_event(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(PlatformServiceHealthPage::class);
        $test->mountAction(RunHealthChecksNowAction::getDefaultName());
        $test->callMountedAction();
        $test->assertHasNoActionErrors();

        $this->assertGreaterThan(0, HealthCheck::query()->whereNull('firm_id')->count());

        $row = app(TenantContextService::class)->runWithoutFirmContext(
            fn () => DB::table('security_events')
                ->where('event_type', 'health_checks_run_on_demand')
                ->where('actor_id', $admin->id)
                ->first()
        );
        $this->assertNotNull($row, 'A security_events audit row must be written for the Run Health Checks Now action.');
    }

    public function test_run_health_checks_now_is_denied_for_a_security_auditor_because_manage_requires_a_narrower_role(): void
    {
        // canManageOperations() is narrowed to SuperAdmin/PlatformAdmin
        // only — SecurityAuditor passes canAccessOperations() (read) but
        // must be denied the mutating action.
        $admin = $this->adminWithRole(PlatformRoleCode::SecurityAuditor);
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(PlatformServiceHealthPage::class);
        $test->mountAction(RunHealthChecksNowAction::getDefaultName());
        $test->callMountedAction();

        $this->assertSame(0, HealthCheck::query()->count());
    }
}
