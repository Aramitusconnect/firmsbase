<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformAdmin;

use App\Enums\PlatformRoleCode;
use App\Filament\Pages\PlatformTenantIsolationPage;
use App\Models\PlatformAdmin;
use App\Services\PlatformRoleService;
use App\Services\TenantContextService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * CoreSuperAdminPhase4TenantIsolationTest — CORE SuperAdmin mission
 * (admin/core-superadmin-security), Phase 4. Proves the "Run
 * Verification" rename now writes an audit event (none existed
 * before), the new Exemption Drill-Down / Table-Level Coverage /
 * Verification History sections render, and the corrected denominator
 * wording is honest.
 */
final class CoreSuperAdminPhase4TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Cache::flush();
    }

    private function securityAuditor(): PlatformAdmin
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, PlatformRoleCode::SecurityAuditor);

        return $admin;
    }

    public function test_run_verification_writes_an_audit_event(): void
    {
        $admin = $this->securityAuditor();
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(PlatformTenantIsolationPage::class);
        $test->mountAction('runVerification');
        $test->callMountedAction();
        $test->assertHasNoActionErrors();

        $row = app(TenantContextService::class)->runWithoutFirmContext(
            fn () => DB::table('security_events')
                ->where('event_type', 'tenant_isolation_verification_run')
                ->where('actor_id', $admin->id)
                ->first()
        );
        $this->assertNotNull($row, 'Run Verification must write an audit event — none existed before this mission.');
    }

    public function test_run_verification_is_rate_limited_per_admin(): void
    {
        $admin = $this->securityAuditor();
        $this->actingAs($admin, 'platform_admin');

        $first = Livewire::test(PlatformTenantIsolationPage::class);
        $first->mountAction('runVerification');
        $first->callMountedAction();

        $second = Livewire::test(PlatformTenantIsolationPage::class);
        $second->mountAction('runVerification');
        $second->callMountedAction();

        $count = app(TenantContextService::class)->runWithoutFirmContext(
            fn () => DB::table('security_events')->where('event_type', 'tenant_isolation_verification_run')->where('actor_id', $admin->id)->count()
        );
        $this->assertSame(1, $count, 'A second Run Verification within the rate-limit window must not write a second audit event.');
    }

    public function test_the_page_shows_the_new_sections_and_honest_denominator_wording(): void
    {
        $admin = $this->securityAuditor();

        $response = $this->actingAs($admin, 'platform_admin')->get(PlatformTenantIsolationPage::getUrl());

        $response->assertOk();
        $response->assertSee('Run Verification');
        $response->assertDontSee('>Refresh<', false);
        $response->assertSee('Exemption Drill-Down');
        $response->assertSee('Not recorded');
        $response->assertSee('Table-Level Coverage');
        $response->assertSee('Verification History');
        $response->assertSee('TENANT_ISOLATION_VERIFICATION_HISTORY_UNAVAILABLE');
        $response->assertSee('Non-Tenant / RLS-Exempt Tables');
        $response->assertSee('excludes exempt/non-tenant');
    }

    public function test_table_level_coverage_lists_a_known_prepared_table(): void
    {
        $admin = $this->securityAuditor();

        $response = $this->actingAs($admin, 'platform_admin')->get(PlatformTenantIsolationPage::getUrl());

        $response->assertOk();
        // firm_users is a long-stable, already-prepared tenant-owned
        // table (see FirmUserResource's own docblock) — a safe,
        // non-rollout-dependent fixture for "the inventory renders real
        // rows".
        $response->assertSee('firm_users');
    }
}
