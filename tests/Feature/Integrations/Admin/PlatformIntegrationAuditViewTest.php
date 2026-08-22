<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Admin;

use App\Enums\PlatformRoleCode;
use App\Filament\Pages\PlatformFirmIntegrationDetailPage;
use App\Integrations\Models\FirmIntegration;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Models\TimelineEvent;
use App\Services\IntegrationPlatformOversightReadService;
use App\Services\PlatformRoleService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PlatformIntegrationAuditViewTest — Checkpoint 11 (frozen-design-post-
 * security-review.md §4). Proves
 * IntegrationPlatformOversightReadService::sanitizedAuditHistoryForFirm()
 * correctly combines a firm's own integration-related `timeline_events`
 * rows with its own governance `security_events` rows, sorted correctly,
 * and — the load-bearing property — only curated fields
 * (event_type/actor_type/occurred_at) are rendered, never raw metadata
 * JSON, even when planted with a real marker value.
 */
final class PlatformIntegrationAuditViewTest extends TestCase
{
    use RefreshDatabase;

    private const METADATA_MARKER = 'SECRET-MARKER-audit-metadata-2a6c8e1f4b7d9053';

    public function test_audit_history_combines_timeline_and_security_events(): void
    {
        $firm = Firm::factory()->activated()->create();

        $this->runWithFirmContext($firm, function () use ($firm) {
            TimelineEvent::create([
                'firm_id' => $firm->id,
                'subject_type' => 'App\\Integrations\\Models\\FirmIntegration',
                'subject_id' => 1,
                'event_type' => 'integration.connected',
                'actor_type' => 'App\\Models\\FirmUser',
                'actor_id' => null,
                'occurred_at' => now()->subHour(),
                'metadata_json' => [],
            ]);

            DB::table('security_events')->insert([
                'firm_id' => $firm->id,
                'actor_type' => PlatformAdmin::class,
                'actor_id' => 1,
                'event_type' => 'platform_integration_oversight.queue_nudged',
                'category' => 'platform_integration_oversight',
                'metadata' => json_encode([]),
                'created_at' => now(),
            ]);
        });

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $history = app(IntegrationPlatformOversightReadService::class)->sanitizedAuditHistoryForFirm($admin, $firm);

        $this->assertCount(2, $history);
        $sources = $history->pluck('source')->all();
        $this->assertContains('timeline', $sources);
        $this->assertContains('security', $sources);
    }

    public function test_audit_history_only_includes_integration_prefixed_timeline_events(): void
    {
        $firm = Firm::factory()->activated()->create();

        $this->runWithFirmContext($firm, function () use ($firm) {
            TimelineEvent::create([
                'firm_id' => $firm->id,
                'subject_type' => 'App\\Models\\Client',
                'subject_id' => 1,
                'event_type' => 'client.created',
                'actor_type' => 'App\\Models\\FirmUser',
                'actor_id' => null,
                'occurred_at' => now(),
                'metadata_json' => [],
            ]);
            TimelineEvent::create([
                'firm_id' => $firm->id,
                'subject_type' => 'App\\Integrations\\Models\\FirmIntegration',
                'subject_id' => 1,
                'event_type' => 'integration_health.state_changed',
                'actor_type' => null,
                'actor_id' => null,
                'occurred_at' => now(),
                'metadata_json' => [],
            ]);
        });

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $history = app(IntegrationPlatformOversightReadService::class)->sanitizedAuditHistoryForFirm($admin, $firm);

        $this->assertCount(1, $history);
        $this->assertSame('integration_health.state_changed', $history->first()['event_type']);
    }

    public function test_audit_history_only_includes_governance_categories_of_security_events(): void
    {
        $firm = Firm::factory()->activated()->create();

        $this->runWithFirmContext($firm, function () use ($firm) {
            DB::table('security_events')->insert([
                'firm_id' => $firm->id,
                'actor_type' => PlatformAdmin::class,
                'actor_id' => 1,
                'event_type' => 'login_succeeded',
                'category' => 'authentication', // NOT support_access/platform_integration_oversight
                'metadata' => json_encode([]),
                'created_at' => now(),
            ]);
        });

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $history = app(IntegrationPlatformOversightReadService::class)->sanitizedAuditHistoryForFirm($admin, $firm);

        $this->assertCount(0, $history);
    }

    public function test_audit_history_is_sorted_most_recent_first(): void
    {
        $firm = Firm::factory()->activated()->create();

        $this->runWithFirmContext($firm, function () use ($firm) {
            TimelineEvent::create([
                'firm_id' => $firm->id,
                'subject_type' => 'App\\Integrations\\Models\\FirmIntegration',
                'subject_id' => 1,
                'event_type' => 'integration.older',
                'actor_type' => null,
                'actor_id' => null,
                'occurred_at' => now()->subDays(2),
                'metadata_json' => [],
            ]);
            TimelineEvent::create([
                'firm_id' => $firm->id,
                'subject_type' => 'App\\Integrations\\Models\\FirmIntegration',
                'subject_id' => 1,
                'event_type' => 'integration.newer',
                'actor_type' => null,
                'actor_id' => null,
                'occurred_at' => now(),
                'metadata_json' => [],
            ]);
        });

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $history = app(IntegrationPlatformOversightReadService::class)->sanitizedAuditHistoryForFirm($admin, $firm)->values();

        $this->assertSame('integration.newer', $history[0]['event_type']);
        $this->assertSame('integration.older', $history[1]['event_type']);
    }

    public function test_raw_metadata_json_never_appears_in_the_curated_audit_output(): void
    {
        $firm = Firm::factory()->activated()->create();

        $this->runWithFirmContext($firm, function () use ($firm) {
            DB::table('security_events')->insert([
                'firm_id' => $firm->id,
                'actor_type' => PlatformAdmin::class,
                'actor_id' => 1,
                'event_type' => 'support_access.requested',
                'category' => 'support_access',
                'metadata' => json_encode(['reason' => self::METADATA_MARKER]),
                'created_at' => now(),
            ]);
        });

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $history = app(IntegrationPlatformOversightReadService::class)->sanitizedAuditHistoryForFirm($admin, $firm);

        $encoded = json_encode($history->all());
        $this->assertStringNotContainsString(self::METADATA_MARKER, $encoded);

        foreach ($history as $event) {
            $this->assertArrayNotHasKey('metadata', $event);
        }
    }

    public function test_the_detail_page_never_renders_the_planted_metadata_marker(): void
    {
        $firm = Firm::factory()->activated()->create();
        $connection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());

        $this->runWithFirmContext($firm, function () use ($firm) {
            DB::table('security_events')->insert([
                'firm_id' => $firm->id,
                'actor_type' => PlatformAdmin::class,
                'actor_id' => 1,
                'event_type' => 'support_access.requested',
                'category' => 'support_access',
                'metadata' => json_encode(['reason' => self::METADATA_MARKER]),
                'created_at' => now(),
            ]);
        });

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(PlatformFirmIntegrationDetailPage::class, [
            'firmUuid' => $firm->uuid,
            'connectionUuid' => $connection->uuid,
        ]);

        $test->assertOk();
        $test->assertDontSee(self::METADATA_MARKER);
        $test->assertSee('support_access.requested');
    }

    /**
     * Security review Finding 3 (CHECKPOINT_11_SECURITY_IMPLEMENTATION_REJECTED):
     * canAccessSecurityLogs() used to be checked ONLY inside
     * PlatformFirmIntegrationDetailPage's Filament closure, never inside
     * IntegrationPlatformOversightReadService::sanitizedAuditHistoryForFirm()
     * itself. This proves the gate is now enforced at the SERVICE layer,
     * by calling sanitizedAuditHistoryForFirm() directly (not via the UI
     * page) with a role that passes the coarse assertCanAccessFirm()
     * oversight/session gate (ImplementationSpecialist is one of
     * PlatformFirmIntegrationBoundedAccessService::UNCONDITIONALLY_TRUSTED_ROLES,
     * so it needs no support-access session) but is in NEITHER
     * PlatformStaffAccessPolicyService::SECURITY_LOG_ROLES.
     */
    public function test_sanitized_audit_history_is_denied_at_the_service_layer_for_a_role_without_security_log_access(): void
    {
        $firm = Firm::factory()->activated()->create();
        $admin = $this->adminWithRole(PlatformRoleCode::ImplementationSpecialist);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no active role grants access to security logs');

        app(IntegrationPlatformOversightReadService::class)->sanitizedAuditHistoryForFirm($admin, $firm);
    }

    private function adminWithRole(PlatformRoleCode $role): PlatformAdmin
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, $role);

        return $admin;
    }
}
