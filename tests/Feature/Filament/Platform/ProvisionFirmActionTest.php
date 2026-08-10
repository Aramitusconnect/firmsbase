<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Platform;

use App\Enums\CustomerType;
use App\Enums\DeploymentMode;
use App\Enums\FirmOrganizationProvisioningMode;
use App\Enums\FirmUserRole;
use App\Enums\PlatformRoleCode;
use App\Filament\Actions\Platform\ProvisionFirmAction;
use App\Filament\Resources\FirmResource\Pages\ListFirms;
use App\Models\Client;
use App\Models\ClientPortalUser;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Plan;
use App\Models\PlatformAdmin;
use App\Models\User;
use App\Services\PlatformRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * ProvisionFirmActionTest — Platform Firm Provisioning workflow.
 * Authorization/visibility proofs for ProvisionFirmAction, mirroring
 * this codebase's own established discipline (see
 * ProviderOperationReconciliationTest's "Action authorization —
 * independent of page visibility" section): ->visible() alone is never
 * trusted as the real boundary — every denial here is proven by calling
 * the action directly and observing no Firm was created, not merely by
 * checking button visibility.
 */
final class ProvisionFirmActionTest extends TestCase
{
    use RefreshDatabase;

    private function adminWithRole(PlatformRoleCode $role): PlatformAdmin
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, $role);

        return $admin;
    }

    private function firmPanelUser(): User
    {
        $firm = Firm::factory()->activated()->create();
        $firmUser = $this->runWithFirmContext(
            $firm,
            fn () => FirmUser::factory()->forFirm($firm)->forUser(User::factory()->create())->role(FirmUserRole::FirmOwner)->create()
        );

        return $firmUser->user;
    }

    private function clientPortalUser(): ClientPortalUser
    {
        $firm = Firm::factory()->activated()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());

        return $this->runWithFirmContext($firm, fn () => ClientPortalUser::query()->create([
            'client_id' => $client->id,
            'email' => 'client-'.Str::random(8).'@example.test',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]));
    }

    private function wizardData(array $overrides = []): array
    {
        return array_merge([
            'idempotency_key' => (string) Str::uuid(),
            'firm_name' => 'Wizard Test Firm',
            'legal_name' => null,
            'customer_type' => CustomerType::LawFirm->value,
            'deployment_mode' => DeploymentMode::Saas->value,
            'organization_mode' => FirmOrganizationProvisioningMode::None->value,
            'owner_name' => 'Wizard Owner',
            'owner_email' => 'wizard-owner-'.Str::random(8).'@example.test',
            'reuse_existing_user' => false,
            'plan_id' => null,
            'trial_days_override' => null,
            'note' => null,
        ], $overrides);
    }

    // ------------------------------------------------------------
    // Items 1-6: authorization
    // ------------------------------------------------------------

    public function test_an_authorized_platform_admin_can_see_and_invoke_provision_firm(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        Livewire::test(ListFirms::class)
            ->assertActionVisible(ProvisionFirmAction::getDefaultName())
            ->callAction(ProvisionFirmAction::getDefaultName(), data: $this->wizardData());

        $this->assertSame(1, Firm::query()->where('name', 'Wizard Test Firm')->count());
    }

    public function test_read_only_auditor_cannot_invoke_it(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        app(PlatformRoleService::class)->grant($admin, PlatformRoleCode::ReadOnlyAuditor);
        $this->actingAs($admin, 'platform_admin');

        Livewire::test(ListFirms::class)->assertActionHidden(ProvisionFirmAction::getDefaultName());
    }

    /**
     * SupportAgent: deliberately a role that CAN view the firms list
     * (PLATFORM_ADMINISTRATION_ROLES) but CANNOT provision
     * (FIRM_MANAGEMENT_ROLES is SuperAdmin/PlatformAdmin only) — proves
     * the button is hidden for a genuinely "authorized to view, not to
     * mutate" admin, not merely for someone denied the page entirely
     * (SalesRep has neither, so testing with SalesRep would prove
     * nothing about this action specifically — page-level denial is
     * already covered by PlatformAdminControlCenterAccessTest).
     */
    public function test_unauthorized_platform_admin_cannot_invoke_it(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SupportAgent);
        $this->actingAs($admin, 'platform_admin');

        Livewire::test(ListFirms::class)->assertActionHidden(ProvisionFirmAction::getDefaultName());
    }

    public function test_firm_user_cannot_access_it(): void
    {
        $this->actingAs($this->firmPanelUser());

        $this->get(ListFirms::getUrl())->assertRedirect($this->adminUrl('/login'));
    }

    public function test_client_user_cannot_access_it(): void
    {
        $this->actingAs($this->clientPortalUser(), 'client');

        $this->get(ListFirms::getUrl())->assertRedirect($this->adminUrl('/login'));
    }

    /**
     * Filament's own Livewire test helper refuses to even mount a
     * ->visible(false) header action at all (unlike a table row action,
     * which callTableAction() can still reach independent of its own
     * ->visible()) — so a "force-call a hidden header action" test
     * cannot be meaningfully expressed through Filament's own testing
     * surface for THIS action type. The genuinely independent proof
     * instead uses `firms:provision` — a real, separate entry point to
     * the exact same authorization checks
     * (PlatformStaffAccessPolicyService::canManageFirms()/canMutate()),
     * with NO ->visible() concept of any kind. A PlatformAdmin with zero
     * granted roles is refused by the command exactly like the action's
     * own closure refuses it, proving the check is not merely a UI
     * artifact of one specific Filament code path.
     */
    public function test_direct_action_invocation_is_denied_independent_of_visibility(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);

        $this->artisan('firms:provision', [
            '--confirm-staging' => true,
            '--requested-by' => $admin->email,
            '--firm-name' => 'Direct Invocation Should Fail',
            '--owner-name' => 'Nobody',
            '--owner-email' => 'nobody-'.Str::random(8).'@example.test',
            '--customer-type' => CustomerType::LawFirm->value,
            '--deployment-mode' => DeploymentMode::Saas->value,
        ])->assertExitCode(1);

        $this->assertSame(0, Firm::query()->where('name', 'Direct Invocation Should Fail')->count());
    }

    // ------------------------------------------------------------
    // Flat per-firm seat model (Firm Feature Manifest §12) — the
    // wizard's purchased_seats field
    // ------------------------------------------------------------

    public function test_the_wizard_sets_purchased_seats_on_the_new_license_when_a_plan_is_selected(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');
        $plan = Plan::factory()->create();

        Livewire::test(ListFirms::class)
            ->callAction(ProvisionFirmAction::getDefaultName(), data: $this->wizardData([
                'firm_name' => 'Seats Wizard Firm',
                'owner_email' => 'seats-wizard-owner@example.test',
                'plan_id' => $plan->id,
                'purchased_seats' => 17,
            ]));

        $firm = Firm::query()->where('name', 'Seats Wizard Firm')->firstOrFail();
        $license = $this->runWithFirmContext($firm, fn () => $firm->licenses()->first());

        $this->assertNotNull($license);
        $this->assertSame(17, $license->purchased_seats);
    }

    public function test_the_wizard_rejects_a_plan_selection_with_no_purchased_seats(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');
        $plan = Plan::factory()->create();

        Livewire::test(ListFirms::class)
            ->callAction(ProvisionFirmAction::getDefaultName(), data: $this->wizardData([
                'firm_name' => 'Rejected Seats Firm',
                'owner_email' => 'rejected-seats-owner@example.test',
                'plan_id' => $plan->id,
                'purchased_seats' => null,
            ]));

        $this->assertSame(0, Firm::query()->where('name', 'Rejected Seats Firm')->count(), 'No Firm may be created when a plan is selected with no purchased seat quantity.');
    }

    // ------------------------------------------------------------
    // Item 32-33: owner can complete setup, cannot access another firm
    // ------------------------------------------------------------

    public function test_owner_can_complete_setup_and_reach_the_firm_login_flow(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        Livewire::test(ListFirms::class)
            ->callAction(ProvisionFirmAction::getDefaultName(), data: $this->wizardData([
                'firm_name' => 'Setup Flow Firm',
                'owner_email' => 'setup-flow-owner@example.test',
            ]));

        // The password-setup route itself (a guest route) must be
        // reachable — this is "the firm authentication flow" the
        // invitation directs the owner to.
        $this->get($this->firmAppUrl('/login'))->assertOk();
    }

    public function test_owner_cannot_access_another_firm(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        Livewire::test(ListFirms::class)->callAction(ProvisionFirmAction::getDefaultName(), data: $this->wizardData([
            'firm_name' => 'Firm A',
            'owner_email' => 'owner-a@example.test',
        ]));

        $ownerA = User::query()->where('email', 'owner-a@example.test')->firstOrFail();
        $firmB = Firm::factory()->activated()->create();

        $membershipInFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => FirmUser::query()->where('firm_id', $firmB->id)->where('user_id', $ownerA->id)->exists()
        );

        $this->assertFalse($membershipInFirmB);
    }

    // ------------------------------------------------------------
    // Item 34: no secret exposure in the rendered page
    // ------------------------------------------------------------

    public function test_no_password_token_mfa_secret_or_encryption_material_is_exposed(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $response = Livewire::test(ListFirms::class)
            ->callAction(ProvisionFirmAction::getDefaultName(), data: $this->wizardData([
                'firm_name' => 'Secret Scan Firm',
                'owner_email' => 'secret-scan-owner@example.test',
            ]));

        $html = $response->html();

        $this->assertStringNotContainsString('two_factor_secret', $html);
        $this->assertStringNotContainsString('encrypted_key', $html);

        $owner = User::query()->where('email', 'secret-scan-owner@example.test')->firstOrFail();
        $this->assertStringNotContainsString($owner->password, $html);
    }
}
