<?php

namespace Tests\Feature\Security\Login;

use App\Enums\FirmUserRole;
use App\Enums\FirmUserStatus;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FirmUserLoginPanelAccessTest — internal login/panel access wiring.
 * Proves the `web` guard + firm panel: firm owners and non-owner active
 * firm users can reach the firm dashboard, a guest cannot, an inactive
 * User/suspended FirmUser/no-membership User is denied, and a
 * successful firm-user login is recorded with the correct firm_id.
 */
class FirmUserLoginPanelAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_firm_owner_can_reach_firm_dashboard(): void
    {
        $firm = Firm::factory()->create();
        $user = User::factory()->create(['is_active' => true]);
        FirmUser::factory()->forFirm($firm)->forUser($user)->create([
            'role' => FirmUserRole::FirmOwner,
            'status' => FirmUserStatus::Active,
        ]);

        $response = $this->actingAs($user, 'web')->get('/firm');

        $response->assertOk();
    }

    public function test_guest_cannot_reach_firm_dashboard(): void
    {
        $response = $this->get('/firm');

        $response->assertRedirect('/firm/login');
    }

    public function test_non_owner_active_firm_user_can_reach_firm_dashboard(): void
    {
        $firm = Firm::factory()->create();
        $user = User::factory()->create(['is_active' => true]);
        FirmUser::factory()->forFirm($firm)->forUser($user)->create([
            'role' => FirmUserRole::Paralegal,
            'status' => FirmUserStatus::Active,
        ]);

        $response = $this->actingAs($user, 'web')->get('/firm');

        $response->assertOk();
    }

    public function test_inactive_user_cannot_reach_firm_dashboard(): void
    {
        $firm = Firm::factory()->create();
        $user = User::factory()->create(['is_active' => false]);
        FirmUser::factory()->forFirm($firm)->forUser($user)->create(['status' => FirmUserStatus::Active]);

        $response = $this->actingAs($user, 'web')->get('/firm');

        $response->assertForbidden();
    }

    public function test_suspended_firm_user_cannot_reach_firm_dashboard(): void
    {
        $firm = Firm::factory()->create();
        $user = User::factory()->create(['is_active' => true]);
        FirmUser::factory()->forFirm($firm)->forUser($user)->create(['status' => FirmUserStatus::Suspended]);

        $response = $this->actingAs($user, 'web')->get('/firm');

        $response->assertForbidden();
    }

    public function test_user_with_no_firm_membership_cannot_reach_firm_dashboard(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $response = $this->actingAs($user, 'web')->get('/firm');

        $response->assertForbidden();
    }

    public function test_successful_firm_user_login_is_recorded_as_a_security_event_with_firm_id(): void
    {
        $firm = Firm::factory()->create();
        $user = User::factory()->create(['is_active' => true]);
        FirmUser::factory()->forFirm($firm)->forUser($user)->create(['status' => FirmUserStatus::Active]);

        auth('web')->login($user);

        // security_events has permanent FORCE ROW LEVEL SECURITY (Section
        // 39A-3L, Phase B6, Checkpoint 34) — a real, non-null firm_id row
        // is only visible under that same firm's own context, so this
        // read-time assertion needs a context wrap to see the row the
        // Login listener just wrote.
        $event = $this->runWithFirmContext($firm, fn () => SecurityEvent::query()
            ->where('actor_type', User::class)
            ->where('actor_id', $user->id)
            ->where('event_type', 'login_succeeded')
            ->first());

        $this->assertNotNull($event, 'Expected a login_succeeded SecurityEvent row for the firm user.');
        $this->assertSame($firm->id, $event->firm_id);
    }
}
