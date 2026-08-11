<?php

namespace Tests\Feature\Security\LoginThrottling;

use App\Filament\Auth\Pages\PlatformAdminLogin;
use App\Filament\Auth\Pages\PlatformAdminRequestPasswordReset;
use App\Filament\Auth\Pages\PlatformAdminResetPassword;
use App\Filament\ClientPortal\Pages\Auth\Login as ClientPortalLogin;
use App\Filament\ClientPortal\Pages\Auth\RequestPasswordReset as ClientPortalRequestPasswordReset;
use App\Filament\ClientPortal\Pages\Auth\ResetPassword as ClientPortalResetPassword;
use App\Filament\Firm\Pages\Auth\Login as FirmLogin;
use App\Filament\Firm\Pages\Auth\RequestPasswordReset as FirmRequestPasswordReset;
use App\Filament\Firm\Pages\Auth\ResetPassword as FirmResetPassword;
use App\Models\PlatformAdmin;
use App\Models\User;
use App\Services\Security\AccountLoginThrottleService;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Facades\Filament;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login as LoginEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LoginThrottlingWiringTest — Mission 1B (Extreme Security Hardening),
 * section 13. Proves two things end-to-end: (1) each panel is wired
 * to its own Login/RequestPasswordReset/ResetPassword subclass, so
 * none of the three share Filament's base auth-page rate-limit
 * buckets any more; (2) the real Failed/Login event listeners
 * registered in AppServiceProvider actually hit/clear the account
 * throttle (not a mock of the listener — the genuine wiring).
 */
class LoginThrottlingWiringTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_panel_has_a_distinct_login_page_class(): void
    {
        $admin = Filament::getPanel('admin')->getLoginRouteAction();
        $firm = Filament::getPanel('firm')->getLoginRouteAction();
        $clientPortal = Filament::getPanel('client-portal')->getLoginRouteAction();

        $this->assertSame(PlatformAdminLogin::class, $admin);
        $this->assertSame(FirmLogin::class, $firm);
        $this->assertSame(ClientPortalLogin::class, $clientPortal);

        $distinct = array_unique([$admin, $firm, $clientPortal]);
        $this->assertCount(3, $distinct);
        $this->assertNotContains(BaseLogin::class, $distinct);
    }

    public function test_every_panel_has_a_distinct_password_reset_request_page_class(): void
    {
        $admin = Filament::getPanel('admin')->getRequestPasswordResetRouteAction();
        $firm = Filament::getPanel('firm')->getRequestPasswordResetRouteAction();
        $clientPortal = Filament::getPanel('client-portal')->getRequestPasswordResetRouteAction();

        $this->assertSame(PlatformAdminRequestPasswordReset::class, $admin);
        $this->assertSame(FirmRequestPasswordReset::class, $firm);
        $this->assertSame(ClientPortalRequestPasswordReset::class, $clientPortal);

        $this->assertCount(3, array_unique([$admin, $firm, $clientPortal]));
    }

    public function test_every_panel_has_a_distinct_reset_password_page_class(): void
    {
        $admin = Filament::getPanel('admin')->getResetPasswordRouteAction();
        $firm = Filament::getPanel('firm')->getResetPasswordRouteAction();
        $clientPortal = Filament::getPanel('client-portal')->getResetPasswordRouteAction();

        $this->assertSame(PlatformAdminResetPassword::class, $admin);
        $this->assertSame(FirmResetPassword::class, $firm);
        $this->assertSame(ClientPortalResetPassword::class, $clientPortal);

        $this->assertCount(3, array_unique([$admin, $firm, $clientPortal]));
    }

    public function test_a_real_failed_event_hits_the_account_throttle(): void
    {
        $service = app(AccountLoginThrottleService::class);

        for ($i = 0; $i < AccountLoginThrottleService::MAX_ATTEMPTS; $i++) {
            event(new Failed('platform_admin', null, ['email' => 'attacker-target@example.com', 'password' => 'irrelevant']));
        }

        $this->assertTrue($service->tooManyAttempts('platform_admin', 'attacker-target@example.com'));
    }

    public function test_a_real_login_event_clears_the_account_throttle(): void
    {
        $service = app(AccountLoginThrottleService::class);
        $admin = PlatformAdmin::factory()->create();

        for ($i = 0; $i < AccountLoginThrottleService::MAX_ATTEMPTS; $i++) {
            event(new Failed('platform_admin', null, ['email' => $admin->email, 'password' => 'irrelevant']));
        }

        $this->assertTrue($service->tooManyAttempts('platform_admin', $admin->email));

        event(new LoginEvent('platform_admin', $admin, false));

        $this->assertFalse($service->tooManyAttempts('platform_admin', $admin->email));
    }

    public function test_failed_attempts_on_different_guards_do_not_cross_contaminate(): void
    {
        $service = app(AccountLoginThrottleService::class);
        $user = User::factory()->create();

        for ($i = 0; $i < AccountLoginThrottleService::MAX_ATTEMPTS; $i++) {
            event(new Failed('web', null, ['email' => $user->email, 'password' => 'irrelevant']));
        }

        $this->assertTrue($service->tooManyAttempts('web', $user->email));
        $this->assertFalse($service->tooManyAttempts('client', $user->email));
        $this->assertFalse($service->tooManyAttempts('platform_admin', $user->email));
    }
}
