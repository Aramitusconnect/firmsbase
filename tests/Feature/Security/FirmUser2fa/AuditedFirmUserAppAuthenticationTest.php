<?php

declare(strict_types=1);

namespace Tests\Feature\Security\FirmUser2fa;

use App\Enums\FirmUserStatus;
use App\Filament\MultiFactor\AuditedFirmUserAppAuthentication;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\User;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

/**
 * AuditedFirmUserAppAuthenticationTest — Mission 1C (Security
 * Validation, Activation & Staging Proof), section 19. Mirrors
 * AuditedAppAuthenticationTest's own convention exactly: proves every
 * event type is written as a real, firm-scoped security_events row.
 * The 4 challenge-time events are exercised via the private
 * recordIfFirmUser() hook directly (reflection), same rationale as the
 * Platform Admin equivalent — that private method IS the actual
 * audit-writing logic the challenge form's rule closures call.
 */
class AuditedFirmUserAppAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private AuditedFirmUserAppAuthentication $appAuthentication;

    protected function setUp(): void
    {
        parent::setUp();
        $this->appAuthentication = app(AuditedFirmUserAppAuthentication::class);
    }

    private function activeFirmUser(): FirmUser
    {
        $firm = Firm::factory()->create();
        $user = User::factory()->create();

        return FirmUser::factory()->forFirm($firm)->forUser($user)->create(['status' => FirmUserStatus::Active]);
    }

    public function test_saving_a_secret_records_mfa_enrolled(): void
    {
        $firmUser = $this->activeFirmUser();

        $this->appAuthentication->saveSecret($firmUser->user, 'JBSWY3DPEHPK3PXP');

        $this->assertEventRecorded($firmUser, 'mfa_enrolled');
    }

    public function test_clearing_a_secret_records_mfa_disabled(): void
    {
        $firmUser = $this->activeFirmUser();
        $firmUser->user->update(['two_factor_secret' => 'JBSWY3DPEHPK3PXP']);

        $this->appAuthentication->saveSecret($firmUser->user, null);

        $this->assertEventRecorded($firmUser, 'mfa_disabled');
    }

    public function test_saving_recovery_codes_records_generated_event(): void
    {
        $firmUser = $this->activeFirmUser();

        $this->appAuthentication->saveRecoveryCodes($firmUser->user, ['code-one', 'code-two']);

        $this->assertEventRecorded($firmUser, 'mfa_recovery_codes_generated');
    }

    public function test_clearing_recovery_codes_records_cleared_event(): void
    {
        $firmUser = $this->activeFirmUser();

        $this->appAuthentication->saveRecoveryCodes($firmUser->user, null);

        $this->assertEventRecorded($firmUser, 'mfa_recovery_codes_cleared');
    }

    public function test_challenge_success_and_failure_events_are_recorded(): void
    {
        $firmUser = $this->activeFirmUser();

        $this->invokeRecordIfFirmUser($firmUser->user, 'mfa_challenge_succeeded');
        $this->assertEventRecorded($firmUser, 'mfa_challenge_succeeded');

        $this->invokeRecordIfFirmUser($firmUser->user, 'mfa_challenge_failed');
        $this->assertEventRecorded($firmUser, 'mfa_challenge_failed');

        $this->invokeRecordIfFirmUser($firmUser->user, 'mfa_recovery_code_used');
        $this->assertEventRecorded($firmUser, 'mfa_recovery_code_used');

        $this->invokeRecordIfFirmUser($firmUser->user, 'mfa_recovery_code_verification_failed');
        $this->assertEventRecorded($firmUser, 'mfa_recovery_code_verification_failed');
    }

    public function test_a_user_with_no_active_firm_membership_is_not_audited(): void
    {
        $user = User::factory()->create();

        $this->invokeRecordIfFirmUser($user, 'mfa_challenge_succeeded');

        $count = app(TenantContextService::class)->runWithoutFirmContext(
            fn () => DB::table('security_events')->where('event_type', 'mfa_challenge_succeeded')->count()
        );

        $this->assertSame(0, $count);
    }

    public function test_a_non_user_actor_is_not_audited(): void
    {
        $method = new ReflectionMethod(AuditedFirmUserAppAuthentication::class, 'recordIfFirmUser');
        $method->setAccessible(true);
        $method->invoke($this->appAuthentication, new \stdClass, 'mfa_challenge_succeeded');

        $count = app(TenantContextService::class)->runWithoutFirmContext(
            fn () => DB::table('security_events')->where('event_type', 'mfa_challenge_succeeded')->count()
        );

        $this->assertSame(0, $count);
    }

    private function invokeRecordIfFirmUser(User $user, string $eventType): void
    {
        $method = new ReflectionMethod(AuditedFirmUserAppAuthentication::class, 'recordIfFirmUser');
        $method->setAccessible(true);
        $method->invoke($this->appAuthentication, $user, $eventType);
    }

    private function assertEventRecorded(FirmUser $firmUser, string $eventType): void
    {
        $row = app(TenantContextService::class)->runWithFirmContext(
            $firmUser->firm_id,
            fn () => DB::table('security_events')
                ->where('actor_type', User::class)
                ->where('actor_id', $firmUser->user_id)
                ->where('event_type', $eventType)
                ->first()
        );

        $this->assertNotNull($row, "Expected a security_events row for event_type [{$eventType}].");
        $this->assertSame('firm_user_mfa', $row->category);
        $this->assertSame($firmUser->firm_id, $row->firm_id);
    }
}
