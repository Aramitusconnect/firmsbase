<?php

declare(strict_types=1);

namespace Tests\Feature\Security\PlatformAdminMfa;

use App\Filament\MultiFactor\AuditedAppAuthentication;
use App\Models\PlatformAdmin;
use App\Notifications\PlatformAdminRecoveryCodeUsedNotification;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use ReflectionMethod;
use Tests\TestCase;

/**
 * AuditedAppAuthenticationTest — MFA design proposal §7. Proves every
 * one of the 8 event types AuditedAppAuthentication itself is
 * responsible for (the 9th, mfa_reset_by_admin, is
 * PlatformAdminMfaResetService's own — see
 * PlatformAdminMfaResetServiceTest) is written as a real, null-firm_id
 * `security_events` row, attributed to the correct PlatformAdmin actor,
 * category 'platform_admin_mfa'.
 *
 * The 4 challenge-time events (mfa_challenge_succeeded/failed,
 * mfa_recovery_code_used/verification_failed) are exercised via
 * the private recordIfPlatformAdmin() hook directly (reflection) rather
 * than driving Filament's full Livewire login-challenge form — that
 * private method IS the actual audit-writing logic those rule closures
 * call on success/failure (see getChallengeFormComponents()'s own
 * docblock for why the closures themselves aren't independently
 * unit-testable without the full Livewire schema machinery); this
 * proves the exact behavior those closures trigger without coupling
 * this test to Filament's internal schema/validation plumbing.
 */
class AuditedAppAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private AuditedAppAuthentication $appAuthentication;

    protected function setUp(): void
    {
        parent::setUp();
        $this->appAuthentication = app(AuditedAppAuthentication::class);
    }

    public function test_saving_a_secret_records_mfa_enrolled(): void
    {
        $admin = PlatformAdmin::factory()->create();

        $this->appAuthentication->saveSecret($admin, 'JBSWY3DPEHPK3PXP');

        $this->assertEventRecorded($admin, 'mfa_enrolled');
    }

    public function test_clearing_a_secret_records_mfa_disabled(): void
    {
        $admin = PlatformAdmin::factory()->create(['two_factor_secret' => 'JBSWY3DPEHPK3PXP']);

        $this->appAuthentication->saveSecret($admin, null);

        $this->assertEventRecorded($admin, 'mfa_disabled');
    }

    public function test_saving_recovery_codes_records_generated_event(): void
    {
        $admin = PlatformAdmin::factory()->create();

        $this->appAuthentication->saveRecoveryCodes($admin, ['code-one', 'code-two']);

        $this->assertEventRecorded($admin, 'mfa_recovery_codes_generated');
    }

    public function test_clearing_recovery_codes_records_cleared_event(): void
    {
        $admin = PlatformAdmin::factory()->create();

        $this->appAuthentication->saveRecoveryCodes($admin, null);

        $this->assertEventRecorded($admin, 'mfa_recovery_codes_cleared');
    }

    public function test_challenge_success_and_failure_events_are_recorded(): void
    {
        $admin = PlatformAdmin::factory()->create();

        $this->invokeRecordIfPlatformAdmin($admin, 'mfa_challenge_succeeded');
        $this->assertEventRecorded($admin, 'mfa_challenge_succeeded');

        $this->invokeRecordIfPlatformAdmin($admin, 'mfa_challenge_failed');
        $this->assertEventRecorded($admin, 'mfa_challenge_failed');

        $this->invokeRecordIfPlatformAdmin($admin, 'mfa_recovery_code_used');
        $this->assertEventRecorded($admin, 'mfa_recovery_code_used');

        $this->invokeRecordIfPlatformAdmin($admin, 'mfa_recovery_code_verification_failed');
        $this->assertEventRecorded($admin, 'mfa_recovery_code_verification_failed');
    }

    public function test_using_a_recovery_code_notifies_the_admin(): void
    {
        Notification::fake();

        $admin = PlatformAdmin::factory()->create();

        $method = new ReflectionMethod(AuditedAppAuthentication::class, 'notifyIfPlatformAdmin');
        $method->setAccessible(true);
        $method->invoke($this->appAuthentication, $admin);

        Notification::assertSentTo($admin, PlatformAdminRecoveryCodeUsedNotification::class);
    }

    public function test_a_non_platform_admin_actor_is_not_notified(): void
    {
        Notification::fake();

        $method = new ReflectionMethod(AuditedAppAuthentication::class, 'notifyIfPlatformAdmin');
        $method->setAccessible(true);
        $method->invoke($this->appAuthentication, new \stdClass);

        Notification::assertNothingSent();
    }

    public function test_non_platform_admin_actor_is_not_audited(): void
    {
        $method = new ReflectionMethod(AuditedAppAuthentication::class, 'recordIfPlatformAdmin');
        $method->setAccessible(true);
        $method->invoke($this->appAuthentication, new \stdClass, 'mfa_challenge_succeeded');

        $count = app(TenantContextService::class)->runWithoutFirmContext(
            fn () => DB::table('security_events')->where('event_type', 'mfa_challenge_succeeded')->count()
        );

        $this->assertSame(0, $count);
    }

    private function invokeRecordIfPlatformAdmin(PlatformAdmin $admin, string $eventType): void
    {
        $method = new ReflectionMethod(AuditedAppAuthentication::class, 'recordIfPlatformAdmin');
        $method->setAccessible(true);
        $method->invoke($this->appAuthentication, $admin, $eventType);
    }

    private function assertEventRecorded(PlatformAdmin $admin, string $eventType): void
    {
        $row = app(TenantContextService::class)->runWithoutFirmContext(
            fn () => DB::table('security_events')
                ->where('actor_type', PlatformAdmin::class)
                ->where('actor_id', $admin->id)
                ->where('event_type', $eventType)
                ->first()
        );

        $this->assertNotNull($row, "Expected a security_events row for event_type [{$eventType}].");
        $this->assertSame('platform_admin_mfa', $row->category);
        $this->assertNull($row->firm_id);
    }
}
