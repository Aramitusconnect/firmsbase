<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Admin;

use App\Enums\PlatformRoleCode;
use App\Enums\SupportAccessSessionStatus;
use App\Filament\Pages\PlatformFirmIntegrationDetailPage;
use App\Filament\Pages\PlatformFirmIntegrationsPage;
use App\Filament\Pages\PlatformIntegrationOverviewPage;
use App\Integrations\Models\FirmIntegration;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Models\SupportAccessRequest;
use App\Models\SupportAccessSession;
use App\Services\PlatformFirmIntegrationBoundedAccessService;
use App\Services\PlatformRoleService;
use App\Services\PlatformStaffAccessPolicyService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * IntegrationAdminRouteAuthorizationTest — Checkpoint 11 (frozen-design-
 * post-security-review.md §2 item 3, §8, §11, §12). The full role-ceiling
 * matrix for `PlatformStaffAccessPolicyService::canAccessIntegrationOversight()`
 * and, layered on top of it, `PlatformFirmIntegrationBoundedAccessService::
 * assertCanAccessFirm()`'s additional per-firm SupportAccessSession
 * requirement for every role outside the unconditionally-trusted ceiling:
 *
 *   - SuperAdmin / PlatformAdmin / ImplementationSpecialist: pass BOTH
 *     the coarse oversight gate AND the per-firm gate unconditionally —
 *     no support-access session required, ever.
 *   - SupportAgent: passes the coarse oversight gate, but the per-firm
 *     gate additionally requires an active, governed SupportAccessSession
 *     scoped to the EXACT target firm.
 *   - BillingAdmin / SalesManager / SalesRep / SecurityAuditor /
 *     ReadOnlyAuditor: denied outright at the coarse gate — never reach
 *     the per-firm check at all.
 */
final class IntegrationAdminRouteAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private const CEILING_ROLES = [
        PlatformRoleCode::SuperAdmin,
        PlatformRoleCode::PlatformAdmin,
        PlatformRoleCode::ImplementationSpecialist,
    ];

    private const DENIED_ROLES = [
        PlatformRoleCode::BillingAdmin,
        PlatformRoleCode::SalesManager,
        PlatformRoleCode::SalesRep,
        PlatformRoleCode::SecurityAuditor,
        PlatformRoleCode::ReadOnlyAuditor,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    // ------------------------------------------------------------
    // 1. canAccessIntegrationOversight() — the coarse, role-level gate
    // ------------------------------------------------------------

    public function test_every_ceiling_role_passes_the_coarse_oversight_gate(): void
    {
        $policy = app(PlatformStaffAccessPolicyService::class);

        foreach (self::CEILING_ROLES as $role) {
            $admin = $this->adminWithRole($role);

            $this->assertTrue($policy->canAccessIntegrationOversight($admin)->allowed, "Role {$role->value} must pass canAccessIntegrationOversight().");
        }
    }

    public function test_support_agent_passes_the_coarse_oversight_gate(): void
    {
        $policy = app(PlatformStaffAccessPolicyService::class);
        $admin = $this->adminWithRole(PlatformRoleCode::SupportAgent);

        $this->assertTrue($policy->canAccessIntegrationOversight($admin)->allowed);
    }

    public function test_every_other_role_is_denied_the_coarse_oversight_gate_outright(): void
    {
        $policy = app(PlatformStaffAccessPolicyService::class);

        foreach (self::DENIED_ROLES as $role) {
            $admin = $this->adminWithRole($role);

            $this->assertFalse($policy->canAccessIntegrationOversight($admin)->allowed, "Role {$role->value} must NOT pass canAccessIntegrationOversight().");
        }
    }

    // ------------------------------------------------------------
    // 2. HTTP/canAccess() level — overview page needs only the coarse
    //    gate (frozen design §2 item 3: no support-access grant needed).
    // ------------------------------------------------------------

    public function test_every_ceiling_role_and_support_agent_can_access_the_overview_page(): void
    {
        foreach ([...self::CEILING_ROLES, PlatformRoleCode::SupportAgent] as $role) {
            $admin = $this->adminWithRole($role);
            $this->actingAs($admin, 'platform_admin');

            $this->assertTrue(PlatformIntegrationOverviewPage::canAccess(), "Role {$role->value} must access the overview page.");
        }
    }

    public function test_every_denied_role_cannot_access_the_overview_page(): void
    {
        foreach (self::DENIED_ROLES as $role) {
            $admin = $this->adminWithRole($role);
            $this->actingAs($admin, 'platform_admin');

            $this->assertFalse(PlatformIntegrationOverviewPage::canAccess(), "Role {$role->value} must NOT access the overview page.");
        }
    }

    // ------------------------------------------------------------
    // 3. Per-firm gate — ceiling roles never need a session.
    // ------------------------------------------------------------

    public function test_every_ceiling_role_can_access_a_firm_with_zero_active_support_access_sessions(): void
    {
        $bounded = app(PlatformFirmIntegrationBoundedAccessService::class);
        $firm = Firm::factory()->activated()->create();

        foreach (self::CEILING_ROLES as $role) {
            $admin = $this->adminWithRole($role);

            // Must not throw.
            $bounded->assertCanAccessFirm($admin, $firm);
            $this->addToAssertionCount(1);
        }
    }

    public function test_ceiling_roles_never_require_a_support_access_session(): void
    {
        $bounded = app(PlatformFirmIntegrationBoundedAccessService::class);

        foreach (self::CEILING_ROLES as $role) {
            $admin = $this->adminWithRole($role);

            $this->assertFalse($bounded->requiresSupportAccessSession($admin), "Role {$role->value} must never require a support access session.");
        }
    }

    // ------------------------------------------------------------
    // 4. Per-firm gate — SupportAgent requires an active session for
    //    the EXACT target firm.
    // ------------------------------------------------------------

    public function test_support_agent_requires_a_support_access_session(): void
    {
        $bounded = app(PlatformFirmIntegrationBoundedAccessService::class);
        $admin = $this->adminWithRole(PlatformRoleCode::SupportAgent);

        $this->assertTrue($bounded->requiresSupportAccessSession($admin));
    }

    public function test_support_agent_without_an_active_session_is_denied_per_firm_access(): void
    {
        $bounded = app(PlatformFirmIntegrationBoundedAccessService::class);
        $firm = Firm::factory()->activated()->create();
        $admin = $this->adminWithRole(PlatformRoleCode::SupportAgent);

        $this->expectException(RuntimeException::class);
        $bounded->assertCanAccessFirm($admin, $firm);
    }

    public function test_support_agent_with_an_active_session_for_the_exact_firm_is_allowed(): void
    {
        $bounded = app(PlatformFirmIntegrationBoundedAccessService::class);
        $firm = Firm::factory()->activated()->create();
        $admin = $this->adminWithRole(PlatformRoleCode::SupportAgent);

        $this->activeSessionFor($admin, $firm);

        $bounded->assertCanAccessFirm($admin, $firm);
        $this->addToAssertionCount(1);
    }

    public function test_support_agent_with_an_active_session_for_a_different_firm_is_still_denied(): void
    {
        $bounded = app(PlatformFirmIntegrationBoundedAccessService::class);
        $targetFirm = Firm::factory()->activated()->create();
        $otherFirm = Firm::factory()->activated()->create();
        $admin = $this->adminWithRole(PlatformRoleCode::SupportAgent);

        $this->activeSessionFor($admin, $otherFirm);

        $this->expectException(RuntimeException::class);
        $bounded->assertCanAccessFirm($admin, $targetFirm);
    }

    // ------------------------------------------------------------
    // 5. Denied roles never reach the per-firm check at all — the
    //    coarse gate rejects them first, even with an (irrelevant)
    //    active session.
    // ------------------------------------------------------------

    public function test_every_denied_role_is_denied_per_firm_access_regardless_of_any_session(): void
    {
        $bounded = app(PlatformFirmIntegrationBoundedAccessService::class);
        $firm = Firm::factory()->activated()->create();

        foreach (self::DENIED_ROLES as $role) {
            $admin = $this->adminWithRole($role);

            try {
                $bounded->assertCanAccessFirm($admin, $firm);
                $this->fail("Role {$role->value} must be denied assertCanAccessFirm().");
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // ------------------------------------------------------------
    // 6. Livewire mount()-level proof: SupportAgent without a session is
    //    denied a clean 403 on the firm-scoped pages (mount() throws
    //    HttpException(403) — see PlatformFirmIntegrationsPage::mount()
    //    / PlatformFirmIntegrationDetailPage::mount()).
    // ------------------------------------------------------------

    public function test_support_agent_without_a_session_is_forbidden_mounting_the_firm_integrations_page(): void
    {
        $firm = Firm::factory()->activated()->create();
        $admin = $this->adminWithRole(PlatformRoleCode::SupportAgent);

        $this->actingAs($admin, 'platform_admin');
        $test = Livewire::test(PlatformFirmIntegrationsPage::class, ['firmUuid' => $firm->uuid]);

        $test->assertForbidden();
    }

    public function test_support_agent_with_an_active_session_can_mount_the_firm_integrations_page(): void
    {
        $firm = Firm::factory()->activated()->create();
        $admin = $this->adminWithRole(PlatformRoleCode::SupportAgent);
        $this->activeSessionFor($admin, $firm);

        $this->actingAs($admin, 'platform_admin');
        $test = Livewire::test(PlatformFirmIntegrationsPage::class, ['firmUuid' => $firm->uuid]);

        $test->assertOk();
    }

    public function test_support_agent_without_a_session_is_forbidden_mounting_the_connection_detail_page(): void
    {
        $firm = Firm::factory()->activated()->create();
        $connection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());
        $admin = $this->adminWithRole(PlatformRoleCode::SupportAgent);

        $this->actingAs($admin, 'platform_admin');
        $test = Livewire::test(PlatformFirmIntegrationDetailPage::class, [
            'firmUuid' => $firm->uuid,
            'connectionUuid' => $connection->uuid,
        ]);

        $test->assertForbidden();
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    private function adminWithRole(PlatformRoleCode $role): PlatformAdmin
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, $role);

        return $admin;
    }

    private function activeSessionFor(PlatformAdmin $admin, Firm $firm): SupportAccessSession
    {
        $request = $this->runWithFirmContext(
            $firm,
            fn () => SupportAccessRequest::factory()->forFirm($firm)->create(['requested_by' => $admin->id])
        );

        return $this->runWithFirmContext(
            $firm,
            fn () => SupportAccessSession::factory()->create([
                'firm_id' => $firm->id,
                'support_access_request_id' => $request->id,
                'platform_admin_id' => $admin->id,
                'status' => SupportAccessSessionStatus::Active->value,
                'started_at' => now(),
                'expires_at' => now()->addHour(),
            ])
        );
    }
}
