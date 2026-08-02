<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Enums\ClientPortalStatus;
use App\Enums\NotificationEventStatus;
use App\Models\Client;
use App\Models\ClientPortalUser;
use App\Models\Firm;
use App\Models\PlatformNotificationCorrelation;
use App\Models\User;
use App\Notifications\ClientPortalResetPasswordNotification;
use App\Notifications\FirmOwnerInvitationNotification;
use App\Services\PlatformNotificationCorrelationService;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PasswordResetPlatformCorrelationFallbackTest — post-9722e88 audit
 * remediation. Covers User::sendPasswordResetNotification() and
 * ClientPortalUser::sendPasswordResetNotification()'s uncorrelated-firm
 * branch: it now routes through CorrelatedPasswordResetSenderService's
 * platform-scope path, which fails CLOSED on every one of its own
 * failure modes (missing HMAC key, a suppression-check error, a
 * correlation-row DB failure) — no email is ever sent on failure, and
 * there is no bare, uncorrelated ->notify() fallback anywhere in this
 * codebase any more (the exact defect an earlier version of this fix
 * introduced and this test class used to assert as correct behavior).
 */
class PasswordResetPlatformCorrelationFallbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.platform_notifications.recipient_fingerprint_hmac_key' => 'test-fingerprint-hmac-key']);
    }

    private function makePortalUserWithNoResolvableFirm(): ClientPortalUser
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create(['portal_status' => ClientPortalStatus::Active]));
        $portalUser = $this->runWithFirmContext($firm, fn () => ClientPortalUser::query()->create([
            'client_id' => $client->id,
            'email' => $client->email,
            'password' => Hash::make('Sup3rSecret!Pass'),
            'is_active' => true,
        ]));

        // Force the "cannot resolve a firm" branch deterministically —
        // constructing a genuinely orphaned ClientPortalUser isn't
        // possible without violating the client_id FK (cascadeOnDelete
        // would delete this row along with its Client), so this stubs
        // the exact same TenantContextService dependency the real
        // method calls, returning null from withClientSelfLookupContext()
        // regardless of client_id — equivalent in effect to a detached
        // Client record.
        $fakeTenantContext = \Mockery::mock(TenantContextService::class);
        $fakeTenantContext->shouldReceive('withClientSelfLookupContext')->once()->andReturn(null);
        $this->app->instance(TenantContextService::class, $fakeTenantContext);

        return $portalUser;
    }

    public function test_user_with_no_active_firm_user_gets_a_platform_correlation_instead_of_an_uncorrelated_send(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email' => 'owner@example.com']);

        $user->sendPasswordResetNotification('test-token');

        $correlation = PlatformNotificationCorrelation::query()->sole();

        $this->assertSame(User::class, $correlation->account_type);
        $this->assertSame($user->id, $correlation->account_id);
        $this->assertSame('user_password_reset', $correlation->notification_type);

        Notification::assertSentTo($user, FirmOwnerInvitationNotification::class);
    }

    public function test_client_portal_user_with_no_resolvable_firm_gets_a_platform_correlation(): void
    {
        Notification::fake();

        $portalUser = $this->makePortalUserWithNoResolvableFirm();

        $portalUser->sendPasswordResetNotification('test-token');

        $correlation = PlatformNotificationCorrelation::query()->sole();

        $this->assertSame(ClientPortalUser::class, $correlation->account_type);
        $this->assertSame($portalUser->id, $correlation->account_id);
        $this->assertSame('client_portal_password_reset', $correlation->notification_type);

        Notification::assertSentTo($portalUser, ClientPortalResetPasswordNotification::class);
    }

    /**
     * Root-caused a real regression during the previous audit round: an
     * earlier version of this fix let a missing HMAC key fall through
     * to a bare, uncorrelated ->notify() call. That fallback has been
     * removed entirely — a missing key must now fail closed.
     */
    public function test_missing_hmac_key_sends_zero_emails(): void
    {
        Notification::fake();
        Log::spy();

        config(['services.platform_notifications.recipient_fingerprint_hmac_key' => null]);

        $user = User::factory()->create(['email' => 'owner@example.com']);

        $user->sendPasswordResetNotification('test-token');

        Notification::assertNothingSent();
        $this->assertSame(0, PlatformNotificationCorrelation::query()->count());
        Log::shouldHaveReceived('critical')
            ->once()
            ->withArgs(fn (string $message) => $message === 'correlated_password_reset_platform_suppression_check_failed');
    }

    public function test_client_portal_user_missing_hmac_key_sends_zero_emails(): void
    {
        Notification::fake();
        Log::spy();

        config(['services.platform_notifications.recipient_fingerprint_hmac_key' => null]);

        $portalUser = $this->makePortalUserWithNoResolvableFirm();

        $portalUser->sendPasswordResetNotification('test-token');

        Notification::assertNothingSent();
        $this->assertSame(0, PlatformNotificationCorrelation::query()->count());
        Log::shouldHaveReceived('critical')
            ->once()
            ->withArgs(fn (string $message) => $message === 'correlated_password_reset_platform_suppression_check_failed');
    }

    /**
     * A before-send DB failure creating the correlation row must also
     * fail closed — simulated here via an oversized notification_type
     * value that exceeds the column's length, a genuine DB-level
     * failure rather than a mock.
     */
    public function test_correlation_database_failure_before_send_sends_zero_emails(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email' => 'owner@example.com']);

        // Corrupt the config to force correlate() to attempt an
        // over-length write: notification_type is hardcoded inside
        // sendForUnresolvedFirm(), so instead this drives the failure
        // via a DB connection that cannot be written to — the
        // recipient_fingerprint_hmac_key check already covers the
        // "before fingerprinting" failure mode above, so this covers
        // the "after fingerprinting, during the correlation INSERT"
        // failure mode specifically by dropping the table structure
        // the INSERT depends on.
        // Postgres supports transactional DDL, and RefreshDatabase wraps
        // this whole test in one transaction that rolls back afterward
        // — this drop is undone automatically, no manual restore needed.
        Schema::drop('platform_notification_correlations');

        $user->sendPasswordResetNotification('test-token');

        Notification::assertNothingSent();
    }

    public function test_suppression_check_failure_sends_zero_emails(): void
    {
        Notification::fake();
        Log::spy();

        $user = User::factory()->create(['email' => 'owner@example.com']);

        Schema::drop('platform_notification_suppressions');

        $user->sendPasswordResetNotification('test-token');

        Notification::assertNothingSent();
        Log::shouldHaveReceived('critical')
            ->once()
            ->withArgs(fn (string $message) => $message === 'correlated_password_reset_platform_suppression_check_failed');
    }

    public function test_user_send_is_skipped_entirely_when_the_address_is_already_platform_suppressed(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email' => 'bounced@example.com']);

        $platformCorrelation = app(PlatformNotificationCorrelationService::class);
        $seed = PlatformNotificationCorrelation::create([
            'correlation_id' => (string) Str::uuid(),
            'account_type' => User::class,
            'account_id' => $user->id,
            'notification_type' => 'user_password_reset',
            'recipient_fingerprint' => $platformCorrelation->fingerprintFor('bounced@example.com'),
            'provider_message_id' => 'msg-already-bounced',
        ]);
        $platformCorrelation->recordOutcome($seed, NotificationEventStatus::Bounced, 'ses_bounce_permanent');

        $user->sendPasswordResetNotification('test-token');

        Notification::assertNothingSent();
        // Only the seed row above — sendPasswordResetNotification() must
        // not create a second correlation for a suppressed address.
        $this->assertSame(1, PlatformNotificationCorrelation::query()->count());
    }

    /**
     * Anti-enumeration: the PUBLIC password-reset request flow
     * (Illuminate\Auth\Passwords\PasswordBroker::sendResetLink() called
     * with no custom callback, exactly as a real "forgot password"
     * controller would) must return its normal generic RESET_LINK_SENT
     * status regardless of whether the internal send actually
     * succeeded — a thrown exception or a different return value here
     * would be an observable side channel distinguishing "this account
     * exists but had an internal error" from "this account doesn't
     * exist" (which returns INVALID_USER, a different status, but for
     * an entirely different, non-enumeration-leaking reason: no user
     * record was found at all before this method is ever reached).
     */
    public function test_public_password_reset_response_remains_generic_when_internal_sending_fails(): void
    {
        config(['services.platform_notifications.recipient_fingerprint_hmac_key' => null]);

        $user = User::factory()->create(['email' => 'owner@example.com']);

        $status = Password::broker('users')->sendResetLink(['email' => $user->email]);

        $this->assertSame(Password::RESET_LINK_SENT, $status);
    }
}
