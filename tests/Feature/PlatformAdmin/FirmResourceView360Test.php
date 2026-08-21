<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformAdmin;

use App\Enums\FirmUserRole;
use App\Enums\FirmUserStatus;
use App\Enums\PlatformRoleCode;
use App\Filament\Resources\FirmResource;
use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationProvider;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\PlatformAdmin;
use App\Models\SecurityEvent;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Services\PlatformRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FirmResourceView360Test — Mission 7 ("Super Admin Operational
 * Completion"), item 7.1. Proves the three new ViewFirm infolist
 * sections (Users, Integrations, Recent Audit Activity) each render
 * this ONE firm's own real, per-firm data — and, just as importantly,
 * never leak a second firm's rows onto the page. Every new section
 * reads under its own independent
 * TenantContextService::runWithFirmContext($record, ...) call (see
 * ViewFirm's own docblock) rather than any cross-firm directory-service
 * loop, so a cross-firm leak here would indicate that discipline broke.
 */
final class FirmResourceView360Test extends TestCase
{
    use RefreshDatabase;

    private function adminWithRole(PlatformRoleCode $role): PlatformAdmin
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, $role);

        return $admin;
    }

    // ------------------------------------------------------------
    // Users section
    // ------------------------------------------------------------

    public function test_users_section_shows_this_firms_users_and_never_another_firms(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $userA = User::factory()->create(['name' => 'Firm A Member', 'email' => 'firm-a-member@example.test']);
        $userB = User::factory()->create(['name' => 'Firm B Member', 'email' => 'firm-b-member@example.test']);

        FirmUser::factory()->forFirm($firmA)->forUser($userA)->create([
            'role' => FirmUserRole::Attorney,
            'status' => FirmUserStatus::Active,
        ]);
        FirmUser::factory()->forFirm($firmB)->forUser($userB)->create([
            'role' => FirmUserRole::Paralegal,
            'status' => FirmUserStatus::Active,
        ]);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $response = $this->actingAs($admin, 'platform_admin')
            ->get(FirmResource::getUrl('view', ['record' => $firmA]));

        $response->assertOk();
        $response->assertSee('Firm A Member');
        $response->assertSee('firm-a-member@example.test');
        $response->assertDontSee('Firm B Member');
        $response->assertDontSee('firm-b-member@example.test');
    }

    public function test_users_section_shows_real_last_login_signal_and_never_for_a_user_who_never_logged_in(): void
    {
        $firm = Firm::factory()->create();

        $loggedInUser = User::factory()->create(['name' => 'Logged In Person']);
        $neverLoggedInUser = User::factory()->create(['name' => 'Never Logged In Person']);

        FirmUser::factory()->forFirm($firm)->forUser($loggedInUser)->create();
        FirmUser::factory()->forFirm($firm)->forUser($neverLoggedInUser)->create();

        SecurityEvent::factory()->forFirm($firm)->create([
            'actor_type' => User::class,
            'actor_id' => $loggedInUser->id,
            'event_type' => 'login_succeeded',
        ]);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $response = $this->actingAs($admin, 'platform_admin')
            ->get(FirmResource::getUrl('view', ['record' => $firm]));

        $response->assertOk();
        $response->assertSee('Logged In Person');
        $response->assertSee('Never Logged In Person');
        // "Never" appears at least once — for the user with no
        // login_succeeded security_events row.
        $response->assertSee('Never');
    }

    // ------------------------------------------------------------
    // Integrations section
    // ------------------------------------------------------------

    public function test_integrations_section_shows_this_firms_connections_and_never_another_firms(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $providerA = IntegrationProvider::factory()->create(['display_name' => 'Firm A Provider']);
        $providerB = IntegrationProvider::factory()->create(['display_name' => 'Firm B Provider']);

        FirmIntegration::factory()->forFirm($firmA)->create([
            'integration_provider_id' => $providerA->id,
            'status' => ConnectionStatus::Active->value,
        ]);
        FirmIntegration::factory()->forFirm($firmB)->create([
            'integration_provider_id' => $providerB->id,
            'status' => ConnectionStatus::Active->value,
        ]);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $response = $this->actingAs($admin, 'platform_admin')
            ->get(FirmResource::getUrl('view', ['record' => $firmA]));

        $response->assertOk();
        $response->assertSee('Firm A Provider');
        $response->assertDontSee('Firm B Provider');
    }

    public function test_firm_with_no_integrations_shows_an_empty_integrations_section(): void
    {
        $firm = Firm::factory()->create();

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $response = $this->actingAs($admin, 'platform_admin')
            ->get(FirmResource::getUrl('view', ['record' => $firm]));

        $response->assertOk();
        $response->assertSee('Integrations');
    }

    // ------------------------------------------------------------
    // Recent Audit Activity section
    // ------------------------------------------------------------

    public function test_recent_audit_activity_shows_this_firms_events_and_never_another_firms(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        TimelineEvent::factory()->forFirm($firmA)->eventType('firm_a_only_event')->create(['occurred_at' => now()]);
        TimelineEvent::factory()->forFirm($firmB)->eventType('firm_b_only_event')->create(['occurred_at' => now()]);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $response = $this->actingAs($admin, 'platform_admin')
            ->get(FirmResource::getUrl('view', ['record' => $firmA]));

        $response->assertOk();
        $response->assertSee('Firm A Only Event');
        $response->assertDontSee('Firm B Only Event');
    }

    public function test_recent_audit_activity_is_capped_at_ten_most_recent_rows(): void
    {
        $firm = Firm::factory()->create();

        // 15 events, most-recent-first by construction (event_0 is the
        // newest) — only the 10 most recent (event_0..event_9) should
        // ever render.
        for ($i = 0; $i < 15; $i++) {
            TimelineEvent::factory()->forFirm($firm)
                ->eventType("custom_event_{$i}")
                ->create(['occurred_at' => now()->subMinutes($i)]);
        }

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $response = $this->actingAs($admin, 'platform_admin')
            ->get(FirmResource::getUrl('view', ['record' => $firm]));

        $response->assertOk();
        $response->assertSee('Custom Event 9');
        $response->assertDontSee('Custom Event 10');
        $response->assertDontSee('Custom Event 14');
    }
}
