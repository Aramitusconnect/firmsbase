<?php

namespace Tests\Feature\Security\FirmUser2fa;

use App\Enums\FirmUserRole;
use App\Enums\FirmUserStatus;
use App\Enums\TwoFactorMode;
use App\Models\Firm;
use App\Models\FirmSettings;
use App\Models\FirmUser;
use App\Models\User;
use App\Services\FirmUser2faPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FirmUser2faPolicyServiceTest — Section 39B. Proves the backend 2FA
 * policy: required mode demands every ACTIVE firm user have
 * User.two_factor_confirmed_at set, optional/off mode never blocks,
 * no role is exempt, inactive/removed firm users never block, and a
 * FirmUser with no related User is non-compliant whenever required.
 */
class FirmUser2faPolicyServiceTest extends TestCase
{
    use RefreshDatabase;

    private FirmUser2faPolicyService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new FirmUser2faPolicyService();
    }

    private function firmWithMode(TwoFactorMode $mode): Firm
    {
        $firm = Firm::factory()->create();
        FirmSettings::factory()->forFirm($firm)->create(['firm_user_2fa_mode' => $mode]);

        return $firm;
    }

    public function test_required_mode_requires_every_active_firm_user_to_have_confirmed_2fa(): void
    {
        $firm = $this->firmWithMode(TwoFactorMode::Required);

        $confirmedUser = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $unconfirmedUser = User::factory()->create(['two_factor_confirmed_at' => null]);

        $compliantFirmUser = FirmUser::factory()->forFirm($firm)->forUser($confirmedUser)->create(['status' => FirmUserStatus::Active]);
        $nonCompliantFirmUser = FirmUser::factory()->forFirm($firm)->forUser($unconfirmedUser)->create(['status' => FirmUserStatus::Active]);

        $this->assertTrue($this->service->isCompliant($compliantFirmUser));
        $this->assertFalse($this->service->isCompliant($nonCompliantFirmUser));
    }

    public function test_optional_mode_treats_a_non_privileged_firm_user_as_compliant_without_confirmed_2fa(): void
    {
        $firm = $this->firmWithMode(TwoFactorMode::Optional);

        $unconfirmedUser = User::factory()->create(['two_factor_confirmed_at' => null]);
        $firmUser = FirmUser::factory()->forFirm($firm)->forUser($unconfirmedUser)->role(FirmUserRole::Paralegal)->create(['status' => FirmUserStatus::Active]);

        $this->assertFalse($this->service->isRequiredForFirm($firm));
        $this->assertTrue($this->service->isCompliant($firmUser));
    }

    public function test_disabled_mode_treats_a_non_privileged_firm_user_as_compliant_without_confirmed_2fa(): void
    {
        $firm = $this->firmWithMode(TwoFactorMode::Disabled);

        $unconfirmedUser = User::factory()->create(['two_factor_confirmed_at' => null]);
        $firmUser = FirmUser::factory()->forFirm($firm)->forUser($unconfirmedUser)->role(FirmUserRole::Paralegal)->create(['status' => FirmUserStatus::Active]);

        $this->assertFalse($this->service->isRequiredForFirm($firm));
        $this->assertTrue($this->service->isCompliant($firmUser));
    }

    /**
     * Non-Payment Completion Program, Workstream 7: FirmOwner and
     * Attorney hold trust-approval, financial-integration-approval,
     * and/or API-credential authority, so they must enroll in 2FA
     * regardless of the firm's own firm_user_2fa_mode setting.
     */
    public function test_platform_minimum_requires_2fa_for_firm_owner_and_attorney_even_when_firm_mode_is_optional(): void
    {
        $firm = $this->firmWithMode(TwoFactorMode::Optional);

        foreach ([FirmUserRole::FirmOwner, FirmUserRole::Attorney] as $privilegedRole) {
            $unconfirmedUser = User::factory()->create(['two_factor_confirmed_at' => null]);
            $firmUser = FirmUser::factory()->forFirm($firm)->forUser($unconfirmedUser)->role($privilegedRole)->create(['status' => FirmUserStatus::Active]);

            $this->assertTrue($this->service->isRequiredForFirmUser($firmUser), "Role {$privilegedRole->value} must be required by the platform minimum.");
            $this->assertFalse($this->service->isCompliant($firmUser), "Role {$privilegedRole->value} must not be compliant without confirmed 2FA.");
            $this->assertTrue($this->service->isRequiredOnlyByPlatformMinimum($firmUser));
        }

        $confirmedUser = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $compliantFirmUser = FirmUser::factory()->forFirm($firm)->forUser($confirmedUser)->role(FirmUserRole::FirmOwner)->create(['status' => FirmUserStatus::Active]);
        $this->assertTrue($this->service->isCompliant($compliantFirmUser));
    }

    public function test_platform_minimum_does_not_apply_to_non_privileged_roles(): void
    {
        $firm = $this->firmWithMode(TwoFactorMode::Optional);

        foreach ([FirmUserRole::Paralegal, FirmUserRole::LegalAssistant, FirmUserRole::Receptionist, FirmUserRole::BillingStaff] as $role) {
            $unconfirmedUser = User::factory()->create(['two_factor_confirmed_at' => null]);
            $firmUser = FirmUser::factory()->forFirm($firm)->forUser($unconfirmedUser)->role($role)->create(['status' => FirmUserStatus::Active]);

            $this->assertFalse($this->service->isRequiredForFirmUser($firmUser), "Role {$role->value} must not be required by the platform minimum.");
            $this->assertFalse($this->service->isRequiredOnlyByPlatformMinimum($firmUser));
        }
    }

    public function test_requirement_summary_flags_platform_minimum_applies_when_firm_mode_would_not_have_required_it(): void
    {
        $firm = $this->firmWithMode(TwoFactorMode::Optional);

        $unconfirmedUser = User::factory()->create(['two_factor_confirmed_at' => null]);
        FirmUser::factory()->forFirm($firm)->forUser($unconfirmedUser)->role(FirmUserRole::FirmOwner)->create(['status' => FirmUserStatus::Active]);

        $summary = $this->service->requirementSummary($firm);

        $this->assertFalse($summary['required']);
        $this->assertTrue($summary['platform_minimum_applies']);
        $this->assertSame(1, $summary['non_compliant_count']);
    }

    public function test_no_firm_role_is_exempt_from_required_2fa(): void
    {
        $firm = $this->firmWithMode(TwoFactorMode::Required);

        foreach (FirmUserRole::cases() as $role) {
            $user = User::factory()->create(['two_factor_confirmed_at' => null]);
            $firmUser = FirmUser::factory()->forFirm($firm)->forUser($user)->role($role)->create(['status' => FirmUserStatus::Active]);

            $this->assertFalse($this->service->isCompliant($firmUser), "Role {$role->value} must not be exempt from required 2FA.");
        }
    }

    public function test_inactive_or_removed_firm_users_do_not_block_readiness(): void
    {
        $firm = $this->firmWithMode(TwoFactorMode::Required);

        $confirmedUser = User::factory()->create(['two_factor_confirmed_at' => now()]);
        FirmUser::factory()->forFirm($firm)->forUser($confirmedUser)->create(['status' => FirmUserStatus::Active]);

        foreach ([FirmUserStatus::Invited, FirmUserStatus::Suspended, FirmUserStatus::Removed] as $status) {
            $unconfirmedUser = User::factory()->create(['two_factor_confirmed_at' => null]);
            FirmUser::factory()->forFirm($firm)->forUser($unconfirmedUser)->create(['status' => $status]);
        }

        $this->assertTrue($this->service->firmIsReadyForPilotData($firm));
        $this->assertCount(0, $this->service->nonCompliantFirmUsers($firm));
    }

    public function test_firm_user_with_no_related_user_is_non_compliant_when_required(): void
    {
        // firm_users.user_id is NOT NULL with cascadeOnDelete(), so a
        // real orphaned row can never be persisted — this exercises
        // the defensive "no related User" guard via an unsaved model
        // instance pointing at a non-existent user id, which is the
        // only way $firmUser->user legitimately resolves to null.
        $firm = $this->firmWithMode(TwoFactorMode::Required);

        $firmUser = FirmUser::factory()->forFirm($firm)->make([
            'status' => FirmUserStatus::Active,
            'user_id' => 999999999,
        ]);

        $this->assertNull($firmUser->user);
        $this->assertFalse($this->service->isCompliant($firmUser));
    }

    public function test_non_compliant_firm_users_returns_only_active_non_compliant_firm_users(): void
    {
        $firm = $this->firmWithMode(TwoFactorMode::Required);

        $confirmedUser = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $compliantActive = FirmUser::factory()->forFirm($firm)->forUser($confirmedUser)->create(['status' => FirmUserStatus::Active]);

        $unconfirmedUser1 = User::factory()->create(['two_factor_confirmed_at' => null]);
        $nonCompliantActive = FirmUser::factory()->forFirm($firm)->forUser($unconfirmedUser1)->create(['status' => FirmUserStatus::Active]);

        $unconfirmedUser2 = User::factory()->create(['two_factor_confirmed_at' => null]);
        $nonCompliantSuspended = FirmUser::factory()->forFirm($firm)->forUser($unconfirmedUser2)->create(['status' => FirmUserStatus::Suspended]);

        $nonCompliant = $this->service->nonCompliantFirmUsers($firm);

        $this->assertCount(1, $nonCompliant);
        $this->assertTrue($nonCompliant->contains('id', $nonCompliantActive->id));
        $this->assertFalse($nonCompliant->contains('id', $compliantActive->id));
        $this->assertFalse($nonCompliant->contains('id', $nonCompliantSuspended->id));
    }

    public function test_firm_is_ready_for_pilot_data_is_false_when_required_mode_has_non_compliant_active_users(): void
    {
        $firm = $this->firmWithMode(TwoFactorMode::Required);

        $unconfirmedUser = User::factory()->create(['two_factor_confirmed_at' => null]);
        FirmUser::factory()->forFirm($firm)->forUser($unconfirmedUser)->create(['status' => FirmUserStatus::Active]);

        $this->assertFalse($this->service->firmIsReadyForPilotData($firm));
    }

    public function test_firm_is_ready_for_pilot_data_is_true_when_required_mode_has_all_active_users_confirmed(): void
    {
        $firm = $this->firmWithMode(TwoFactorMode::Required);

        $confirmedUser1 = User::factory()->create(['two_factor_confirmed_at' => now()]);
        FirmUser::factory()->forFirm($firm)->forUser($confirmedUser1)->create(['status' => FirmUserStatus::Active]);

        $confirmedUser2 = User::factory()->create(['two_factor_confirmed_at' => now()]);
        FirmUser::factory()->forFirm($firm)->forUser($confirmedUser2)->create(['status' => FirmUserStatus::Active]);

        $this->assertTrue($this->service->firmIsReadyForPilotData($firm));
    }

    public function test_requirement_summary_returns_a_structured_breakdown(): void
    {
        $firm = $this->firmWithMode(TwoFactorMode::Required);

        $confirmedUser = User::factory()->create(['two_factor_confirmed_at' => now()]);
        FirmUser::factory()->forFirm($firm)->forUser($confirmedUser)->create(['status' => FirmUserStatus::Active]);

        $unconfirmedUser = User::factory()->create(['two_factor_confirmed_at' => null]);
        FirmUser::factory()->forFirm($firm)->forUser($unconfirmedUser)->create(['status' => FirmUserStatus::Active]);

        $summary = $this->service->requirementSummary($firm);

        $this->assertSame('required', $summary['mode']);
        $this->assertTrue($summary['required']);
        $this->assertSame(2, $summary['active_firm_user_count']);
        $this->assertSame(1, $summary['compliant_count']);
        $this->assertSame(1, $summary['non_compliant_count']);
        $this->assertCount(1, $summary['non_compliant_firm_user_ids']);
        $this->assertFalse($summary['ready_for_pilot_data']);
    }

    public function test_isRequiredForFirmUser_matches_isRequiredForFirm(): void
    {
        $firm = $this->firmWithMode(TwoFactorMode::Required);
        $firmUser = FirmUser::factory()->forFirm($firm)->create(['status' => FirmUserStatus::Active]);

        $this->assertTrue($this->service->isRequiredForFirmUser($firmUser));
        $this->assertSame($this->service->isRequiredForFirm($firm), $this->service->isRequiredForFirmUser($firmUser));
    }
}
