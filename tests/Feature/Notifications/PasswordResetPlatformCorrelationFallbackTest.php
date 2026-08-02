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
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PasswordResetPlatformCorrelationFallbackTest — post-578ee98 audit
 * remediation (finding H1). Covers User::sendPasswordResetNotification()
 * and ClientPortalUser::sendPasswordResetNotification()'s
 * uncorrelated-firm fallback branch: it must never send silently and
 * untracked (the original 578ee98 behavior) — it must get a
 * platform-scope correlation, emit an operational alert, and skip
 * sending entirely for an already-platform-suppressed address.
 */
class PasswordResetPlatformCorrelationFallbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.platform_notifications.recipient_fingerprint_hmac_key' => 'test-fingerprint-hmac-key']);
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

    public function test_user_with_no_active_firm_user_emits_an_operational_alert(): void
    {
        Notification::fake();
        Log::spy();

        $user = User::factory()->create();

        $user->sendPasswordResetNotification('test-token');

        Log::shouldHaveReceived('alert')
            ->once()
            ->withArgs(fn (string $message) => $message === 'user_password_reset_no_firm_correlation');
    }

    /**
     * Root-caused a real regression during this audit: an earlier
     * version of this fix let isRecipientSuppressed()/correlate()'s own
     * exceptions (e.g. a missing HMAC key) propagate all the way up
     * into FirmProvisioningService::dispatchOwnerInvitation()'s broad
     * catch(Throwable) — silently marking a genuine owner invitation
     * as failed before notify() was ever reached, discovered via
     * FirmProvisioningServiceTest's own real (non-faked) send path.
     * The platform-correlation subsystem must be best-effort, layered
     * on top of the send — never a precondition for it.
     */
    public function test_user_send_still_happens_when_the_platform_correlation_subsystem_is_unavailable(): void
    {
        Notification::fake();
        Log::spy();

        // No recipient_fingerprint_hmac_key configured — the exact
        // condition that caused the regression this test guards.
        config(['services.platform_notifications.recipient_fingerprint_hmac_key' => null]);

        $user = User::factory()->create(['email' => 'owner@example.com']);

        $user->sendPasswordResetNotification('test-token');

        Notification::assertSentTo($user, FirmOwnerInvitationNotification::class);
        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(fn (string $message) => $message === 'user_password_reset_platform_correlation_unavailable');
        $this->assertSame(0, PlatformNotificationCorrelation::query()->count());
    }

    public function test_client_portal_user_send_still_happens_when_the_platform_correlation_subsystem_is_unavailable(): void
    {
        Notification::fake();
        Log::spy();

        config(['services.platform_notifications.recipient_fingerprint_hmac_key' => null]);

        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create(['portal_status' => ClientPortalStatus::Active]));
        $portalUser = $this->runWithFirmContext($firm, fn () => ClientPortalUser::query()->create([
            'client_id' => $client->id,
            'email' => $client->email,
            'password' => Hash::make('Sup3rSecret!Pass'),
            'is_active' => true,
        ]));

        $fakeTenantContext = \Mockery::mock(TenantContextService::class);
        $fakeTenantContext->shouldReceive('withClientSelfLookupContext')->once()->andReturn(null);
        $this->app->instance(TenantContextService::class, $fakeTenantContext);

        $portalUser->sendPasswordResetNotification('test-token');

        Notification::assertSentTo($portalUser, ClientPortalResetPasswordNotification::class);
        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(fn (string $message) => $message === 'client_portal_user_password_reset_platform_correlation_unavailable');
        $this->assertSame(0, PlatformNotificationCorrelation::query()->count());
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

    public function test_client_portal_user_with_no_resolvable_firm_gets_a_platform_correlation(): void
    {
        Notification::fake();

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

        $portalUser->sendPasswordResetNotification('test-token');

        $correlation = PlatformNotificationCorrelation::query()->sole();

        $this->assertSame(ClientPortalUser::class, $correlation->account_type);
        $this->assertSame($portalUser->id, $correlation->account_id);
        $this->assertSame('client_portal_password_reset', $correlation->notification_type);

        Notification::assertSentTo($portalUser, ClientPortalResetPasswordNotification::class);
    }

    public function test_client_portal_user_with_no_resolvable_firm_emits_an_operational_alert(): void
    {
        Notification::fake();
        Log::spy();

        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create(['portal_status' => ClientPortalStatus::Active]));
        $portalUser = $this->runWithFirmContext($firm, fn () => ClientPortalUser::query()->create([
            'client_id' => $client->id,
            'email' => $client->email,
            'password' => Hash::make('Sup3rSecret!Pass'),
            'is_active' => true,
        ]));

        $fakeTenantContext = \Mockery::mock(TenantContextService::class);
        $fakeTenantContext->shouldReceive('withClientSelfLookupContext')->once()->andReturn(null);
        $this->app->instance(TenantContextService::class, $fakeTenantContext);

        $portalUser->sendPasswordResetNotification('test-token');

        Log::shouldHaveReceived('alert')
            ->once()
            ->withArgs(fn (string $message) => $message === 'client_portal_user_password_reset_no_firm_correlation');
    }
}
