<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformAdmin;

use App\Enums\PlatformRoleCode;
use App\Filament\Pages\PlatformSecurityDashboardPage;
use App\Models\PlatformAdmin;
use App\Models\SecurityEvent;
use App\Services\PlatformRoleService;
use App\Services\PlatformSecurityDashboardService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * CoreSuperAdminPhase3SecurityDashboardTest — CORE SuperAdmin mission
 * (admin/core-superadmin-security), Phase 3. Proves the new REAL
 * metrics (platformAdminFailedLoginCount, recentPrivilegedPlatformActivity),
 * that the stale "MFA... not-yet-built" copy is gone, and that the new
 * dashboard sections render for an authorized admin.
 */
final class CoreSuperAdminPhase3SecurityDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Cache::flush();
    }

    private function securityAuditor(): PlatformAdmin
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, PlatformRoleCode::SecurityAuditor);

        return $admin;
    }

    private function service(): PlatformSecurityDashboardService
    {
        return app(PlatformSecurityDashboardService::class);
    }

    private function createPlatformAdminEventAt(PlatformAdmin $actor, string $eventType, Carbon $createdAt, array $metadata = []): SecurityEvent
    {
        // security_events is append-only (throws on UPDATE — see that
        // model's own docblock), so the deliberate created_at override
        // must happen on the FIRST save (an insert), not a second save
        // against an already-created row. ->make() (never ->create())
        // + forceFill() + one ->save() matches
        // PlatformSecurityDashboardServiceTest::createSecurityEventAt()'s
        // own established pattern exactly.
        $event = SecurityEvent::factory()->make([
            'firm_id' => null,
            'actor_type' => PlatformAdmin::class,
            'actor_id' => $actor->id,
            'event_type' => $eventType,
            'category' => 'platform_admin_management',
            'metadata' => $metadata,
        ]);
        $event->forceFill(['created_at' => $createdAt]);
        $event->save();

        return $event->fresh();
    }

    // --- platformAdminFailedLoginCount() ---

    public function test_failed_login_count_only_counts_platform_admin_login_failures_within_the_window(): void
    {
        $target = PlatformAdmin::factory()->create();

        $this->createPlatformAdminEventAt($target, 'login_failed', now()->subHours(1));
        $this->createPlatformAdminEventAt($target, 'login_failed', now()->subHours(2));
        // Outside the 24h window — must not count.
        $this->createPlatformAdminEventAt($target, 'login_failed', now()->subHours(30));
        // A different event_type — must not count.
        $this->createPlatformAdminEventAt($target, 'login_succeeded', now()->subHours(1));

        $this->assertSame(2, $this->service()->platformAdminFailedLoginCount(24));
    }

    public function test_failed_login_count_is_zero_when_no_failures_exist(): void
    {
        $this->assertSame(0, $this->service()->platformAdminFailedLoginCount(24));
    }

    // --- recentPrivilegedPlatformActivity() ---

    public function test_privileged_activity_includes_role_grants_and_extracts_whitelisted_metadata(): void
    {
        $actor = PlatformAdmin::factory()->create();
        $target = PlatformAdmin::factory()->create();

        $this->createPlatformAdminEventAt($actor, 'platform_admin_role_granted', now(), [
            'target_platform_admin_id' => $target->id,
            'role_code' => 'billing_admin',
        ]);

        $rows = $this->service()->recentPrivilegedPlatformActivity();

        $this->assertCount(1, $rows);
        $this->assertSame('platform_admin_role_granted', $rows->first()['event_type']);
        $this->assertSame($target->id, $rows->first()['target_platform_admin_id']);
        $this->assertSame('billing_admin', $rows->first()['role_code']);
    }

    public function test_privileged_activity_excludes_unrelated_event_types(): void
    {
        $actor = PlatformAdmin::factory()->create();
        $this->createPlatformAdminEventAt($actor, 'login_succeeded', now());
        $this->createPlatformAdminEventAt($actor, 'login_failed', now());

        $this->assertCount(0, $this->service()->recentPrivilegedPlatformActivity());
    }

    public function test_privileged_activity_covers_every_documented_event_type(): void
    {
        $actor = PlatformAdmin::factory()->create();

        foreach ([
            'platform_admin_role_granted',
            'platform_admin_role_revoked',
            'platform_admin_activated',
            'platform_admin_deactivated',
            'platform_admin_sessions_revoked',
            'mfa_reset_by_admin',
        ] as $eventType) {
            $this->createPlatformAdminEventAt($actor, $eventType, now());
        }

        $this->assertCount(6, $this->service()->recentPrivilegedPlatformActivity());
    }

    // --- Page rendering / MFA wording honesty ---

    public function test_the_dashboard_no_longer_claims_mfa_is_not_yet_built(): void
    {
        $admin = $this->securityAuditor();

        $response = $this->actingAs($admin, 'platform_admin')->get(PlatformSecurityDashboardPage::getUrl());

        $response->assertOk();
        $response->assertDontSee('not-yet-built');
        $response->assertSee('MFA enforcement is active platform-wide');
    }

    public function test_the_dashboard_shows_the_new_security_metrics_and_privileged_activity_sections(): void
    {
        $admin = $this->securityAuditor();

        $response = $this->actingAs($admin, 'platform_admin')->get(PlatformSecurityDashboardPage::getUrl());

        $response->assertOk();
        $response->assertSee('Security Metrics');
        $response->assertSee('Severity classification: Not classified');
        $response->assertSee('Privileged Platform Activity');
        $response->assertSee('Incident Console');
    }
}
