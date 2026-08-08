<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformAdmin;

use App\Enums\FirmUserStatus;
use App\Enums\LicenseStatus;
use App\Enums\PlatformRoleCode;
use App\Filament\Resources\FirmResource;
use App\Models\Firm;
use App\Models\FirmLicense;
use App\Models\FirmUser;
use App\Models\Plan;
use App\Models\PlatformAdmin;
use App\Services\PlatformRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FirmResourceSeatLicensingTest — Admin acceptance-audit follow-up.
 * Covers the new "Commercial / License" section on ViewFirm: Plan,
 * License status, and purchased/used/remaining seats sourced
 * exclusively from FirmSeatCapacityService (Firm Feature Manifest §12's
 * flat per-firm seat model) — never a duplicated ad-hoc query. No
 * seat-adjustment action exists (see ViewFirm's own docblock for why:
 * no reusable domain service to call), so this section is read-only
 * throughout.
 */
final class FirmResourceSeatLicensingTest extends TestCase
{
    use RefreshDatabase;

    private function adminWithRole(PlatformRoleCode $role): PlatformAdmin
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, $role);

        return $admin;
    }

    // ------------------------------------------------------------
    // Authorization
    // ------------------------------------------------------------

    public function test_guest_is_redirected_from_the_firm_view_page(): void
    {
        $firm = Firm::factory()->create();

        $this->get(FirmResource::getUrl('view', ['record' => $firm]))
            ->assertRedirect('/admin/login');
    }

    public function test_a_platform_admin_with_no_role_is_forbidden(): void
    {
        $firm = Firm::factory()->create();
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);

        $this->actingAs($admin, 'platform_admin')
            ->get(FirmResource::getUrl('view', ['record' => $firm]))
            ->assertForbidden();
    }

    public function test_a_sales_rep_is_forbidden(): void
    {
        $firm = Firm::factory()->create();
        $admin = $this->adminWithRole(PlatformRoleCode::SalesRep);

        $this->actingAs($admin, 'platform_admin')
            ->get(FirmResource::getUrl('view', ['record' => $firm]))
            ->assertForbidden();
    }

    public function test_a_super_admin_can_view_the_firm_page(): void
    {
        $firm = Firm::factory()->create();
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $this->actingAs($admin, 'platform_admin')
            ->get(FirmResource::getUrl('view', ['record' => $firm]))
            ->assertOk();
    }

    // ------------------------------------------------------------
    // Seat/license visibility
    // ------------------------------------------------------------

    public function test_firm_with_a_license_and_used_seats_shows_accurate_reconciled_values(): void
    {
        $plan = Plan::factory()->create(['name' => 'Acceptance Test Plan']);
        $firm = Firm::factory()->create();

        $this->runWithFirmContext($firm, function () use ($firm, $plan): void {
            FirmLicense::factory()->forFirm($firm)->create([
                'plan_id' => $plan->id,
                'license_status' => LicenseStatus::Active,
                'purchased_seats' => 3,
            ]);

            FirmUser::factory()->forFirm($firm)->create(['status' => FirmUserStatus::Active]);
            FirmUser::factory()->forFirm($firm)->create(['status' => FirmUserStatus::Invited]);
            FirmUser::factory()->forFirm($firm)->create(['status' => FirmUserStatus::Removed]);
        });

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $response = $this->actingAs($admin, 'platform_admin')
            ->get(FirmResource::getUrl('view', ['record' => $firm]));

        $response->assertOk();
        $response->assertSee('Acceptance Test Plan');
        // 3 purchased, 2 used (Active + Invited; Removed excluded), 1 remaining.
        $response->assertSeeInOrder(['Purchased seats', '3']);
        $response->assertSeeInOrder(['Seats used', '2']);
        $response->assertSeeInOrder(['Seats remaining', '1']);
    }

    public function test_firm_with_no_license_row_shows_seats_as_unset_never_zero(): void
    {
        $firm = Firm::factory()->create();
        // Deliberately no FirmLicense row at all -- mirrors Firm 3's
        // real staging state.

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $response = $this->actingAs($admin, 'platform_admin')
            ->get(FirmResource::getUrl('view', ['record' => $firm]));

        $response->assertOk();
        $response->assertSee('No plan assigned');
        $response->assertSee('No license');
        $response->assertSee('Not configured / Unset');
        // Never render a bare "0" for purchased seats -- that would
        // misrepresent "unconfigured" as "correctly configured with
        // zero seats" (FirmSeatCapacityService's own explicit
        // null-never-zero discipline).
        $response->assertDontSee('Purchased seats0', false);
    }

    public function test_no_seat_adjustment_action_is_offered(): void
    {
        $firm = Firm::factory()->create();
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $response = $this->actingAs($admin, 'platform_admin')
            ->get(FirmResource::getUrl('view', ['record' => $firm]));

        $response->assertOk();
        $response->assertDontSee('Adjust Licensed Seats');
        $response->assertDontSee('Adjust Seats');
    }
}
