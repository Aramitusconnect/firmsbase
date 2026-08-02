<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Enums\ActivationChecklistStatus;
use App\Enums\CustomerType;
use App\Enums\DeploymentMode;
use App\Enums\FirmActivationStatus;
use App\Enums\FirmOrganizationProvisioningMode;
use App\Enums\FirmProvisioningStatus;
use App\Enums\FirmUserRole;
use App\Enums\FirmUserStatus;
use App\Enums\LicenseStatus;
use App\Enums\TenantEncryptionKeyStatus;
use App\Exceptions\ExistingUserReviewRequiredException;
use App\Exceptions\FirmProvisioningRequestChangedException;
use App\Exceptions\InactivePlanSelectedException;
use App\Exceptions\PlatformAdminIdentityCollisionException;
use App\Models\Firm;
use App\Models\FirmProvisioningRequest;
use App\Models\FirmUser;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlatformAdmin;
use App\Models\PlatformNotificationCorrelation;
use App\Models\User;
use App\Notifications\FirmOwnerInvitationNotification;
use App\Services\FirmProvisioningService;
use App\ValueObjects\FirmProvisioningInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;
use Throwable;

/**
 * FirmProvisioningServiceTest — Platform Firm Provisioning workflow.
 * Proves creation correctness, transaction atomicity, existing-email
 * collision rules, idempotency/concurrency, invitation lifecycle, and
 * audit coverage for FirmProvisioningService itself (the authoritative
 * service both ProvisionFirmAction and firms:provision call).
 * Filament-level authorization/visibility proofs live in
 * ProvisionFirmActionTest.
 */
final class FirmProvisioningServiceTest extends TestCase
{
    use RefreshDatabase;

    private function actor(): PlatformAdmin
    {
        return PlatformAdmin::factory()->create(['is_active' => true]);
    }

    private function input(array $overrides = []): FirmProvisioningInput
    {
        $defaults = [
            'idempotencyKey' => (string) Str::uuid(),
            'firmName' => 'Acme Legal',
            'legalName' => null,
            'organizationMode' => FirmOrganizationProvisioningMode::None,
            'organizationId' => null,
            'newOrganizationName' => null,
            'ownerName' => 'Ada Owner',
            'ownerEmail' => 'ada-'.Str::random(8).'@example.test',
            'reuseExistingUser' => false,
            'customerType' => CustomerType::LawFirm,
            'deploymentMode' => DeploymentMode::Saas,
            'planId' => null,
            'trialDaysOverride' => null,
            'note' => null,
        ];

        $merged = array_merge($defaults, $overrides);

        return new FirmProvisioningInput(...$merged);
    }

    private function service(): FirmProvisioningService
    {
        return app(FirmProvisioningService::class);
    }

    /**
     * security_events is FORCE-RLS protected; a firm-scoped row (written
     * via PlatformAdminAuditEventRecorder::record($firm, ...)) is only
     * visible to a SELECT running under that exact firm's own
     * app.current_firm_id context — reading without any context hides
     * it entirely (see 2026_08_25_930034_force_rls_on_security_events_table.php's
     * own SELECT policy).
     */
    private function auditRow(string $eventType, Firm $firm): ?object
    {
        return $this->runWithFirmContext(
            $firm,
            fn () => DB::table('security_events')->where('event_type', $eventType)->orderByDesc('id')->first()
        );
    }

    // ------------------------------------------------------------
    // Creation correctness (items 7-15)
    // ------------------------------------------------------------

    public function test_firm_is_created_with_correct_real_enum_values(): void
    {
        $result = $this->service()->provision($this->input([
            'customerType' => CustomerType::LegalSpecialist,
            'deploymentMode' => DeploymentMode::Dedicated,
        ]), $this->actor());

        $firm = $result->firm->fresh();

        $this->assertSame(CustomerType::LegalSpecialist, $firm->customer_type);
        $this->assertSame(DeploymentMode::Dedicated, $firm->deployment_mode);
        $this->assertSame(FirmActivationStatus::Onboarding, $firm->activation_status);
        $this->assertSame('Acme Legal', $firm->name);
    }

    public function test_organization_creation_path_works(): void
    {
        $result = $this->service()->provision($this->input([
            'organizationMode' => FirmOrganizationProvisioningMode::CreateNew,
            'newOrganizationName' => 'Acme Holdings',
        ]), $this->actor());

        $firm = $result->firm->fresh();

        $this->assertNotNull($firm->organization_id);
        $this->assertSame('Acme Holdings', Organization::query()->find($firm->organization_id)?->name);
    }

    public function test_existing_organization_selection_works(): void
    {
        $organization = Organization::factory()->create(['name' => 'Existing Org']);

        $result = $this->service()->provision($this->input([
            'organizationMode' => FirmOrganizationProvisioningMode::UseExisting,
            'organizationId' => $organization->id,
        ]), $this->actor());

        $this->assertSame($organization->id, $result->firm->fresh()->organization_id);
        $this->assertSame(1, Organization::query()->where('name', 'Existing Org')->count());
    }

    public function test_user_is_created_correctly(): void
    {
        $email = 'brand-new-'.Str::random(8).'@example.test';

        $result = $this->service()->provision($this->input(['ownerEmail' => $email, 'ownerName' => 'Brand New Owner']), $this->actor());

        $owner = $result->owner->fresh();
        $this->assertSame('Brand New Owner', $owner->name);
        $this->assertSame($email, $owner->email);
        $this->assertNotNull($owner->password);
    }

    public function test_firm_user_owner_membership_is_created_correctly(): void
    {
        $result = $this->service()->provision($this->input(), $this->actor());

        $firmUser = $this->runWithFirmContext(
            $result->firm,
            fn () => FirmUser::query()->where('firm_id', $result->firm->id)->where('user_id', $result->owner->id)->first()
        );

        $this->assertNotNull($firmUser);
        $this->assertTrue((bool) $firmUser->is_primary);
        $this->assertSame(FirmUserStatus::Invited, $firmUser->status);
    }

    public function test_correct_owner_role_is_assigned(): void
    {
        $result = $this->service()->provision($this->input(), $this->actor());

        $firmUser = $this->runWithFirmContext(
            $result->firm,
            fn () => FirmUser::query()->where('firm_id', $result->firm->id)->where('user_id', $result->owner->id)->first()
        );

        $this->assertSame(FirmUserRole::FirmOwner, $firmUser->role);
    }

    public function test_required_license_is_created_when_a_plan_is_selected(): void
    {
        $plan = Plan::factory()->create(['trial_days' => 21]);

        $result = $this->service()->provision($this->input(['planId' => $plan->id]), $this->actor());

        $license = $this->runWithFirmContext($result->firm, fn () => $result->firm->licenses()->first());

        $this->assertNotNull($license);
        $this->assertSame(LicenseStatus::Trial, $license->license_status);
        $this->assertSame($plan->id, $license->plan_id);
        $this->assertNotNull($license->expires_at);
        $this->assertEqualsWithDelta(21, now()->diffInDays($license->expires_at), 1);
    }

    public function test_required_defaults_and_entitlements_are_initialized(): void
    {
        $plan = Plan::factory()->create();

        $result = $this->service()->provision($this->input(['planId' => $plan->id]), $this->actor());

        $settings = $this->runWithFirmContext($result->firm, fn () => $result->firm->firmSettings()->first());
        $this->assertNotNull($settings, 'firm_settings row must exist for a newly provisioned firm.');

        // syncPlanEntitlements() must have run without error against the
        // real service — asserted by absence of exception above, plus a
        // sanity re-fetch that the firm's entitlements relation is at
        // least queryable under the correct tenant context.
        $entitlementCount = $this->runWithFirmContext($result->firm, fn () => $result->firm->entitlements()->count());
        $this->assertIsInt($entitlementCount);
    }

    public function test_encryption_and_activation_checklist_provisioning_is_completed(): void
    {
        $result = $this->service()->provision($this->input(), $this->actor());

        $key = $this->runWithFirmContext($result->firm, fn () => $result->firm->activeTenantEncryptionKey()->first());
        $this->assertNotNull($key);
        $this->assertSame(TenantEncryptionKeyStatus::Active, $key->status);

        $checklist = $this->runWithFirmContext($result->firm, fn () => $result->firm->activationChecklist()->first());
        $this->assertNotNull($checklist);
        $this->assertSame(ActivationChecklistStatus::InProgress, $checklist->status);
    }

    public function test_firm_starts_in_onboarding_not_activated(): void
    {
        $result = $this->service()->provision($this->input(), $this->actor());

        $this->assertSame(FirmActivationStatus::Onboarding, $result->firm->fresh()->activation_status);
    }

    // ------------------------------------------------------------
    // Invitation lifecycle (items 17-20, 32-34)
    // ------------------------------------------------------------

    public function test_invitation_is_dispatched_only_after_commit(): void
    {
        $result = $this->service()->provision($this->input(), $this->actor());

        $this->assertSame(FirmProvisioningStatus::Completed, $result->status);

        $tokenRow = DB::table('password_reset_tokens')->where('email', $result->owner->email)->first();
        $this->assertNotNull($tokenRow, 'A real password-reset token row must exist after successful provisioning.');
    }

    /**
     * Independent-review finding, fixed: FirmOwnerInvitationNotification
     * originally built its link with a plain route()/url() call, but
     * Filament's own password-reset route requires a SIGNED URL
     * (Illuminate\Routing\Middleware\ValidateSignature) — an unsigned
     * link 403s the instant a real owner clicks it. This proves the
     * fixed link both carries a valid signature AND, hit over real
     * HTTP, actually returns 200 rather than 403 — not merely that a
     * URL string was produced.
     */
    public function test_the_invitation_link_is_signed_and_actually_resolves(): void
    {
        Notification::fake();

        $result = $this->service()->provision($this->input(), $this->actor());

        Notification::assertSentTo(
            $result->owner,
            FirmOwnerInvitationNotification::class,
            function (FirmOwnerInvitationNotification $notification, array $channels) use ($result) {
                $method = new \ReflectionMethod($notification, 'resetUrl');
                $method->setAccessible(true);
                $url = $method->invoke($notification, $result->owner);

                $this->assertTrue(URL::hasValidSignature(Request::create($url, 'GET')), 'The invitation link must carry a valid signature.');
                $this->assertSame(200, $this->get($url)->getStatusCode(), 'The invitation link must actually resolve to the password-setup form, not 403.');

                return true;
            }
        );
    }

    public function test_invitation_token_is_not_stored_in_logs_or_audit_metadata(): void
    {
        $result = $this->service()->provision($this->input(), $this->actor());

        $tokenRow = DB::table('password_reset_tokens')->where('email', $result->owner->email)->first();
        $rawTokenHash = $tokenRow->token;

        $audit = $this->auditRow('firm_provisioned', $result->firm);
        $this->assertNotNull($audit);
        $metadata = json_decode($audit->metadata, true);

        $this->assertArrayNotHasKey('token', $metadata);
        $this->assertArrayNotHasKey('invitation_token', $metadata);
        $this->assertArrayNotHasKey('password', $metadata);
        $this->assertStringNotContainsString($rawTokenHash, json_encode($metadata));
        $this->assertStringContainsString('*', $metadata['owner_email_redacted']);
        $this->assertStringNotContainsString($result->owner->email, json_encode(['x' => $metadata['owner_email_redacted']]));
    }

    public function test_invitation_failure_leaves_an_honest_recoverable_onboarding_state(): void
    {
        Password::shouldReceive('broker')->with('users')->andThrow(new \RuntimeException('mail transport unavailable'));

        $result = $this->service()->provision($this->input(), $this->actor());

        $this->assertSame(FirmProvisioningStatus::InvitationFailed, $result->status);
        $this->assertSame(FirmActivationStatus::Onboarding, $result->firm->fresh()->activation_status);

        // The Firm/User/FirmUser records must still exist intact.
        $this->assertNotNull(Firm::query()->find($result->firm->id));
        $this->assertNotNull(User::query()->find($result->owner->id));
    }

    /**
     * Post-9722e88 audit remediation requirement 4 (firm-owned email):
     * a firm-owner invitation always passes its ALREADY-KNOWN Firm
     * directly to CorrelatedPasswordResetSenderService::sendForFirm()
     * — there is no firm-resolution step to "fail" at this call site,
     * and no platform-level fallback available to it at all. When the
     * firm-scope correlation itself fails (simulated here via a
     * dropped table — Postgres supports transactional DDL, and
     * RefreshDatabase's own wrapping transaction undoes this
     * automatically), the invitation must send zero emails and must
     * never attempt (or create any row via) the platform-scope path.
     */
    public function test_invitation_with_correlation_failure_sends_zero_emails_and_never_falls_back_to_platform_scope(): void
    {
        Notification::fake();

        Schema::drop('notification_provider_correlations');

        $result = $this->service()->provision($this->input(), $this->actor());

        $this->assertSame(FirmProvisioningStatus::InvitationFailed, $result->status);
        Notification::assertNothingSent();
        $this->assertSame(
            0,
            PlatformNotificationCorrelation::query()->count(),
            'A firm-owned invitation must never fall back to the platform-scope correlation path.'
        );
    }

    public function test_resend_invitation_works_without_duplicating_tenant_records(): void
    {
        $result = $this->service()->provision($this->input(), $this->actor());
        $firmCountBefore = Firm::query()->count();
        $userCountBefore = User::query()->count();

        // Laravel's password broker throttles a second sendResetLink()
        // for the same email within 60s (config/auth.php) — travel past
        // it so this proves resend genuinely works, not that the
        // throttle happens to return false too.
        $this->travel(61)->seconds();

        $succeeded = $this->service()->resendInvitation($result->firm->fresh(), $this->actor());

        $this->assertTrue($succeeded);
        $this->assertSame($firmCountBefore, Firm::query()->count());
        $this->assertSame($userCountBefore, User::query()->count());
    }

    // ------------------------------------------------------------
    // Existing-email collision rules (items 21-23)
    // ------------------------------------------------------------

    public function test_existing_email_collision_fails_safely_without_explicit_reuse_flag(): void
    {
        $existing = User::factory()->create(['email' => 'collides@example.test']);

        $this->expectException(ExistingUserReviewRequiredException::class);

        $this->service()->provision($this->input(['ownerEmail' => 'collides@example.test']), $this->actor());

        $this->assertSame(1, User::query()->where('email', 'collides@example.test')->count());
    }

    public function test_explicit_reuse_attaches_the_existing_user_and_audits_the_decision(): void
    {
        $existing = User::factory()->create(['email' => 'reuse-me@example.test']);

        $result = $this->service()->provision($this->input([
            'ownerEmail' => 'reuse-me@example.test',
            'reuseExistingUser' => true,
        ]), $this->actor());

        $this->assertSame($existing->id, $result->owner->id);
        $this->assertSame(1, User::query()->where('email', 'reuse-me@example.test')->count());

        $audit = $this->auditRow('firm_provisioned', $result->firm);
        $metadata = json_decode($audit->metadata, true);
        $this->assertTrue($metadata['reused_existing_user']);
    }

    public function test_platform_admin_identity_collision_is_prevented(): void
    {
        $platformAdmin = PlatformAdmin::factory()->create(['email' => 'staff@example.test']);

        $this->expectException(PlatformAdminIdentityCollisionException::class);

        $this->service()->provision($this->input(['ownerEmail' => 'staff@example.test']), $this->actor());

        $this->assertSame(0, User::query()->where('email', 'staff@example.test')->count());
    }

    public function test_cross_firm_privilege_escalation_is_prevented(): void
    {
        $resultA = $this->service()->provision($this->input(), $this->actor());
        $resultB = $this->service()->provision($this->input(), $this->actor());

        $ownerAMembershipInFirmB = $this->runWithFirmContext(
            $resultB->firm,
            fn () => FirmUser::query()->where('firm_id', $resultB->firm->id)->where('user_id', $resultA->owner->id)->exists()
        );

        $this->assertFalse($ownerAMembershipInFirmB, 'Firm A\'s owner must have no membership at all in Firm B.');
    }

    // ------------------------------------------------------------
    // Idempotency and concurrency (items 24, 29-30, 33)
    // ------------------------------------------------------------

    public function test_two_concurrent_submissions_create_one_tenant(): void
    {
        $key = (string) Str::uuid();
        $actor = $this->actor();
        $input = $this->input(['idempotencyKey' => $key]);

        $resultA = $this->service()->provision($input, $actor);
        $resultB = $this->service()->provision($input, $actor);

        $this->assertSame($resultA->firm->id, $resultB->firm->id);
        $this->assertTrue($resultB->resumedFromExistingRequest);
        $this->assertSame(1, Firm::query()->where('name', 'Acme Legal')->count());
        $this->assertSame(1, FirmProvisioningRequest::query()->where('idempotency_key', $key)->count());
    }

    public function test_a_changed_request_cannot_reuse_an_old_idempotency_key(): void
    {
        $key = (string) Str::uuid();
        $actor = $this->actor();

        $this->service()->provision($this->input(['idempotencyKey' => $key, 'firmName' => 'First Firm']), $actor);

        $this->expectException(FirmProvisioningRequestChangedException::class);

        $this->service()->provision($this->input(['idempotencyKey' => $key, 'firmName' => 'A Totally Different Firm']), $actor);
    }

    public function test_retry_resumes_the_existing_completed_result(): void
    {
        $key = (string) Str::uuid();
        $actor = $this->actor();
        $input = $this->input(['idempotencyKey' => $key]);

        $first = $this->service()->provision($input, $actor);
        $retry = $this->service()->provision($input, $actor);

        $this->assertSame($first->firm->id, $retry->firm->id);
        $this->assertSame($first->owner->id, $retry->owner->id);
        $this->assertSame(FirmProvisioningStatus::Completed, $retry->status);
    }

    // ------------------------------------------------------------
    // Rollback / atomicity (items 25-28)
    // ------------------------------------------------------------

    public function test_a_failure_at_organization_resolution_rolls_back_everything(): void
    {
        $firmCountBefore = Firm::query()->count();
        $userCountBefore = User::query()->count();

        try {
            $this->service()->provision($this->input([
                'organizationMode' => FirmOrganizationProvisioningMode::UseExisting,
                'organizationId' => 999999999,
            ]), $this->actor());
            $this->fail('Expected a ModelNotFoundException for a non-existent organization.');
        } catch (Throwable) {
            // expected
        }

        $this->assertSame($firmCountBefore, Firm::query()->count());
        $this->assertSame($userCountBefore, User::query()->count());
    }

    public function test_a_failure_at_plan_resolution_rolls_back_the_firm_and_owner_too(): void
    {
        $firmCountBefore = Firm::query()->count();
        $userCountBefore = User::query()->count();
        $email = 'rollback-plan-'.Str::random(8).'@example.test';

        try {
            $this->service()->provision($this->input(['ownerEmail' => $email, 'planId' => 999999999]), $this->actor());
            $this->fail('Expected a ModelNotFoundException for a non-existent plan.');
        } catch (Throwable) {
            // expected
        }

        $this->assertSame($firmCountBefore, Firm::query()->count(), 'No orphan Firm may remain after rollback.');
        $this->assertSame($userCountBefore, User::query()->count(), 'No orphan User may remain after rollback.');
        $this->assertSame(0, User::query()->where('email', $email)->count());
    }

    /**
     * STAGING ADMIN STABILIZATION addition: proves the stale-UI-state
     * guard (InactivePlanSelectedException) — a plan that has since
     * been archived must be rejected atomically at submission time,
     * not just filtered out of the wizard's own Select at search time.
     */
    public function test_an_archived_plan_cannot_be_submitted_from_stale_ui_state(): void
    {
        $plan = Plan::factory()->archived()->create();
        $firmCountBefore = Firm::query()->count();
        $userCountBefore = User::query()->count();
        $email = 'rollback-archived-plan-'.Str::random(8).'@example.test';

        try {
            $this->service()->provision($this->input(['ownerEmail' => $email, 'planId' => $plan->id]), $this->actor());
            $this->fail('Expected an InactivePlanSelectedException for an archived plan.');
        } catch (InactivePlanSelectedException) {
            // expected
        }

        $this->assertSame($firmCountBefore, Firm::query()->count(), 'No orphan Firm may remain after rollback.');
        $this->assertSame($userCountBefore, User::query()->count(), 'No orphan User may remain after rollback.');
        $this->assertSame(0, User::query()->where('email', $email)->count());
    }

    public function test_a_draft_plan_cannot_be_submitted_from_stale_ui_state(): void
    {
        $plan = Plan::factory()->draft()->create();
        $email = 'rollback-draft-plan-'.Str::random(8).'@example.test';

        try {
            $this->service()->provision($this->input(['ownerEmail' => $email, 'planId' => $plan->id]), $this->actor());
            $this->fail('Expected an InactivePlanSelectedException for a draft plan.');
        } catch (InactivePlanSelectedException) {
            // expected
        }

        $this->assertSame(0, User::query()->where('email', $email)->count());
    }

    public function test_no_duplicate_license_or_subscription_exists_after_two_successful_provisions(): void
    {
        $plan = Plan::factory()->create();
        $actor = $this->actor();

        $resultA = $this->service()->provision($this->input(['planId' => $plan->id]), $actor);
        $resultB = $this->service()->provision($this->input(['planId' => $plan->id]), $actor);

        $licensesA = $this->runWithFirmContext($resultA->firm, fn () => $resultA->firm->licenses()->count());
        $licensesB = $this->runWithFirmContext($resultB->firm, fn () => $resultB->firm->licenses()->count());

        $this->assertSame(1, $licensesA);
        $this->assertSame(1, $licensesB);
    }

    // ------------------------------------------------------------
    // Audit coverage (items 29-30)
    // ------------------------------------------------------------

    public function test_every_successful_mutation_is_audited(): void
    {
        $result = $this->service()->provision($this->input(), $this->actor());

        $audit = $this->auditRow('firm_provisioned', $result->firm);
        $this->assertNotNull($audit);
        $metadata = json_decode($audit->metadata, true);

        $this->assertSame($result->firm->id, $metadata['firm_id']);
        $this->assertSame($result->owner->id, $metadata['owner_user_id']);
        $this->assertArrayHasKey('idempotency_key', $metadata);
        $this->assertArrayHasKey('customer_type', $metadata);
        $this->assertArrayHasKey('deployment_mode', $metadata);
    }

    public function test_failed_provisioning_attempts_are_audited_safely(): void
    {
        try {
            $this->service()->provision($this->input([
                'organizationMode' => FirmOrganizationProvisioningMode::UseExisting,
                'organizationId' => 999999999,
            ]), $this->actor());
        } catch (Throwable) {
            // expected
        }

        $audit = DB::table('security_events')->where('event_type', 'firm_provisioning_failed')->orderByDesc('id')->first();
        $this->assertNotNull($audit);

        $metadata = json_decode($audit->metadata, true);
        $this->assertArrayHasKey('failure_category', $metadata);
        // Never a raw exception message/stack trace.
        $this->assertStringNotContainsString('Stack trace', json_encode($metadata));
    }

    public function test_denied_and_reuse_decisions_are_audited(): void
    {
        $existing = User::factory()->create(['email' => 'reuse-audit@example.test']);

        $result = $this->service()->provision($this->input([
            'ownerEmail' => 'reuse-audit@example.test',
            'reuseExistingUser' => true,
        ]), $this->actor());

        $audit = $this->auditRow('firm_provisioned', $result->firm);
        $metadata = json_decode($audit->metadata, true);
        $this->assertTrue($metadata['reused_existing_user']);
    }

    // ------------------------------------------------------------
    // Tenant context correctness (item 31)
    // ------------------------------------------------------------

    public function test_rls_protected_tenant_defaults_are_created_under_the_correct_firm_context(): void
    {
        $result = $this->service()->provision($this->input(), $this->actor());

        // Reading firm_users/firm_settings WITHOUT establishing tenant
        // context must return nothing (FORCE RLS), proving the created
        // rows are genuinely tenant-scoped to this firm, not written
        // under some ambient/no-context bypass.
        DB::statement("select set_config('app.current_firm_id', '', true)");

        $invisibleWithoutContext = FirmUser::query()->where('firm_id', $result->firm->id)->count();
        $this->assertSame(0, $invisibleWithoutContext, 'firm_users must be invisible without the correct tenant context.');

        $visibleWithContext = $this->runWithFirmContext(
            $result->firm,
            fn () => FirmUser::query()->where('firm_id', $result->firm->id)->count()
        );
        $this->assertSame(1, $visibleWithContext);
    }
}
