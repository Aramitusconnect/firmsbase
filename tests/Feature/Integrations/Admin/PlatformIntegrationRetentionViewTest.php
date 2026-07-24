<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Admin;

use App\Enums\PlatformRoleCode;
use App\Filament\Pages\PlatformFirmIntegrationDetailPage;
use App\Integrations\Models\FirmIntegration;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Services\IntegrationPlatformOversightReadService;
use App\Services\PlatformRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PlatformIntegrationRetentionViewTest — Checkpoint 11 (frozen-design-
 * post-security-review.md §7, §14). Proves
 * IntegrationPlatformOversightReadService::retentionConfigSummary()
 * renders the real, currently-configured retention windows (never a
 * fabricated value) and that the UI never claims integration retention
 * is legal-hold-safe (frozen design §14:
 * LEGAL_HOLD_COVERAGE_UNRESOLVED remains unresolved).
 */
final class PlatformIntegrationRetentionViewTest extends TestCase
{
    use RefreshDatabase;

    public function test_retention_config_summary_reflects_real_configured_values(): void
    {
        config([
            'integrations.outbox.completed_retention_days' => 14,
            'integrations.outbox.dead_lettered_retention_days' => 90,
            'integrations.sync_runs.retention_days' => 60,
            'integrations.conflicts.retention_days' => 180,
        ]);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $summary = app(IntegrationPlatformOversightReadService::class)->retentionConfigSummary($admin);

        $this->assertSame(14, $summary['outbox_completed_retention_days']);
        $this->assertSame(90, $summary['outbox_dead_lettered_retention_days']);
        $this->assertSame(60, $summary['sync_runs_retention_days']);
        $this->assertSame(180, $summary['conflicts_retention_days']);
    }

    public function test_retention_config_summary_is_not_firm_specific(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $service = app(IntegrationPlatformOversightReadService::class);

        // No Firm argument at all — global config only.
        $summaryOne = $service->retentionConfigSummary($admin);
        $summaryTwo = $service->retentionConfigSummary($admin);

        $this->assertSame($summaryOne, $summaryTwo);
    }

    public function test_the_detail_page_never_claims_legal_hold_safety(): void
    {
        $firm = Firm::factory()->activated()->create();
        $connection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(PlatformFirmIntegrationDetailPage::class, [
            'firmUuid' => $firm->uuid,
            'connectionUuid' => $connection->uuid,
        ]);

        $test->assertOk();
        // The page must carry the explicit disclaimer, not an
        // affirmative "this is legal-hold-safe" claim.
        $test->assertSee('not a claim that integration retention is legal-hold-safe');
    }

    public function test_retention_gated_by_the_same_coarse_oversight_gate_as_every_other_surface(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        // No role grant.

        $this->expectException(\RuntimeException::class);
        app(IntegrationPlatformOversightReadService::class)->retentionConfigSummary($admin);
    }

    /**
     * Security review Finding 3 (CHECKPOINT_11_SECURITY_IMPLEMENTATION_REJECTED):
     * canAccessSecurityLogs() used to be checked ONLY inside
     * PlatformFirmIntegrationDetailPage's Filament closure, never inside
     * IntegrationPlatformOversightReadService::retentionConfigSummary()
     * itself. This proves the gate is now enforced at the SERVICE layer:
     * ImplementationSpecialist passes the coarse
     * assertCanAccessOversight() gate (it is one of
     * PlatformFirmIntegrationBoundedAccessService::UNCONDITIONALLY_TRUSTED_ROLES)
     * but is in NEITHER PlatformStaffAccessPolicyService::SECURITY_LOG_ROLES,
     * so calling retentionConfigSummary() directly must still be denied.
     */
    public function test_retention_config_summary_is_denied_at_the_service_layer_for_a_role_without_security_log_access(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::ImplementationSpecialist);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no active role grants access to security logs');

        app(IntegrationPlatformOversightReadService::class)->retentionConfigSummary($admin);
    }

    private function adminWithRole(PlatformRoleCode $role): PlatformAdmin
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, $role);

        return $admin;
    }
}
