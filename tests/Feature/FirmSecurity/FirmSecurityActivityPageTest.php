<?php

declare(strict_types=1);

namespace Tests\Feature\FirmSecurity;

use App\Enums\FirmUserRole;
use App\Filament\Firm\Pages\FirmSecurityActivityPage;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\SecurityEvent;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * FirmSecurityActivityPageTest — Firm Feature Manifest §10/§11 UI proof:
 * (1) FirmOwner-only role ceiling; (2) authentication events shown
 * plainly; (3) support_access/high_risk_change heavily summarized, no
 * actor identity, no metadata; (4) raw metadata is never rendered
 * anywhere on the page, for any category; (5) an unrecognized category
 * is excluded entirely (conservative default); (6) the small RLS
 * regression checklist — a firm's own security activity view only ever
 * shows its own firm's events, never a foreign firm's.
 */
final class FirmSecurityActivityPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('firm'));
    }

    // ------------------------------------------------------------
    // 1. canAccess() role ceiling — FirmOwner only.
    // ------------------------------------------------------------

    public function test_only_firm_owner_can_access_the_security_activity_page(): void
    {
        foreach (FirmUserRole::cases() as $role) {
            $firm = Firm::factory()->create();
            $this->actingAsRole($firm, $role);

            $expected = $role === FirmUserRole::FirmOwner;
            $this->assertSame($expected, FirmSecurityActivityPage::canAccess(), "canAccess() mismatch for role {$role->value}");
        }
    }

    public function test_guest_cannot_access_the_security_activity_page(): void
    {
        $this->assertFalse(FirmSecurityActivityPage::canAccess());
    }

    public function test_a_non_owner_mounting_the_page_directly_is_blocked_with_a_403(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::Attorney);

        $test = $this->runWithFirmContext($firm, fn () => Livewire::test(FirmSecurityActivityPage::class));
        $test->assertForbidden();
    }

    // ------------------------------------------------------------
    // 2. authentication events — shown plainly.
    // ------------------------------------------------------------

    public function test_authentication_events_are_shown_plainly(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        SecurityEvent::factory()->forFirm($firm)->create([
            'event_type' => 'login_succeeded',
            'category' => 'authentication',
            'actor_type' => 'User',
            'actor_id' => 4242,
        ]);
        SecurityEvent::factory()->forFirm($firm)->create([
            'event_type' => 'login_failed',
            'category' => 'authentication',
            'actor_type' => 'User',
            'actor_id' => 4242,
        ]);

        $test = $this->runWithFirmContext($firm, fn () => Livewire::test(FirmSecurityActivityPage::class));

        $test->assertSee('Login succeeded');
        $test->assertSee('Login failed');
        $test->assertSee('User #4242');
    }

    // ------------------------------------------------------------
    // 3. support_access / high_risk_change — heavily summarized.
    // ------------------------------------------------------------

    public function test_support_access_events_are_heavily_summarized_with_no_actor_or_metadata(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        SecurityEvent::factory()->forFirm($firm)->create([
            'event_type' => 'support_session_started',
            'category' => 'support_access',
            'actor_type' => 'PlatformAdmin',
            'actor_id' => 9999,
            'metadata' => ['reason' => 'TOP_SECRET_SUPPORT_REASON_TEXT'],
        ]);

        $test = $this->runWithFirmContext($firm, fn () => Livewire::test(FirmSecurityActivityPage::class));

        $test->assertSee("Platform support accessed this firm's data");
        $test->assertDontSee('PlatformAdmin #9999');
        $test->assertDontSee('9999');
        $test->assertDontSee('TOP_SECRET_SUPPORT_REASON_TEXT');
    }

    public function test_high_risk_change_events_are_heavily_summarized_with_no_actor_or_metadata(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        SecurityEvent::factory()->forFirm($firm)->create([
            'event_type' => 'firm_forced_off_trust_mode',
            'category' => 'high_risk_change',
            'actor_type' => 'PlatformAdmin',
            'actor_id' => 7777,
            'metadata' => ['approval_reason' => 'TOP_SECRET_APPROVAL_REASON_TEXT'],
        ]);

        $test = $this->runWithFirmContext($firm, fn () => Livewire::test(FirmSecurityActivityPage::class));

        $test->assertSee('A high-risk platform-level change affecting this firm was made');
        $test->assertDontSee('PlatformAdmin #7777');
        $test->assertDontSee('7777');
        $test->assertDontSee('TOP_SECRET_APPROVAL_REASON_TEXT');
    }

    // ------------------------------------------------------------
    // 4. Unrecognized categories are excluded entirely.
    // ------------------------------------------------------------

    public function test_an_unrecognized_category_is_excluded_entirely(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        SecurityEvent::factory()->forFirm($firm)->create([
            'event_type' => 'webhook_replayed',
            'category' => 'webhook_replay',
            'actor_type' => 'PlatformAdmin',
            'actor_id' => 5555,
        ]);

        $test = $this->runWithFirmContext($firm, fn () => Livewire::test(FirmSecurityActivityPage::class));

        $test->assertSee('No security events recorded yet');
        $test->assertDontSee('5555');
    }

    // ------------------------------------------------------------
    // 5. RLS regression checklist.
    // ------------------------------------------------------------

    public function test_a_firms_security_activity_view_only_shows_its_own_firm_events(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->actingAsRole($firmA, FirmUserRole::FirmOwner);

        SecurityEvent::factory()->forFirm($firmA)->create([
            'event_type' => 'login_succeeded',
            'category' => 'authentication',
            'actor_type' => 'User',
            'actor_id' => 1001,
        ]);
        SecurityEvent::factory()->forFirm($firmB)->create([
            'event_type' => 'login_succeeded',
            'category' => 'authentication',
            'actor_type' => 'User',
            'actor_id' => 2002,
        ]);

        $test = $this->runWithFirmContext($firmA, fn () => Livewire::test(FirmSecurityActivityPage::class));

        $test->assertSee('User #1001');
        $test->assertDontSee('User #2002');
    }

    public function test_a_foreign_firms_support_access_and_high_risk_events_never_leak(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->actingAsRole($firmA, FirmUserRole::FirmOwner);

        SecurityEvent::factory()->forFirm($firmB)->create([
            'event_type' => 'support_session_started',
            'category' => 'support_access',
            'metadata' => ['reason' => 'FIRM_B_ONLY_REASON'],
        ]);
        SecurityEvent::factory()->forFirm($firmB)->create([
            'event_type' => 'firm_forced_off_trust_mode',
            'category' => 'high_risk_change',
        ]);

        $test = $this->runWithFirmContext($firmA, fn () => Livewire::test(FirmSecurityActivityPage::class));

        $test->assertSee('No security events recorded yet');
        $test->assertDontSee('FIRM_B_ONLY_REASON');
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    private function actingAsRole(Firm $firm, FirmUserRole $role): FirmUser
    {
        $firmUser = $this->runWithFirmContext(
            $firm,
            fn () => FirmUser::factory()->forFirm($firm)->forUser(User::factory()->create())->role($role)->create()
        );

        $this->actingAs($firmUser->user);

        return $firmUser;
    }
}
