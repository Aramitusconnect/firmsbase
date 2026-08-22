<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Admin;

use App\Enums\PlatformRoleCode;
use App\Filament\Pages\PlatformFirmIntegrationDetailPage;
use App\Integrations\Data\SanitizedHealthDiagnostic;
use App\Integrations\Enums\HealthSummaryState;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Services\HealthStateService;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Services\IntegrationPlatformOversightReadService;
use App\Services\PlatformRoleService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PlatformIntegrationHealthViewTest — Checkpoint 11 (frozen-design-post-
 * security-review.md §10 item 2). Proves the health section renders the
 * connection's real, governed health signal
 * (sanitized_diagnostic_summary/last_failure_category/consecutive_failures/
 * next_retry_at, all sourced from HealthStateService/
 * integration_connection_health) — never a raw, unsanitized value.
 */
final class PlatformIntegrationHealthViewTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_healthy_connection_shows_the_healthy_summary_state(): void
    {
        $firm = Firm::factory()->activated()->create();
        $connection = $this->runWithFirmContext($firm, function () use ($firm) {
            $c = FirmIntegration::factory()->forFirm($firm)->create();
            app(HealthStateService::class)->recordSuccess($c->id, $firm->id);

            return $c;
        });

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $detail = app(IntegrationPlatformOversightReadService::class)->connectionDetail($admin, $firm, $connection->uuid);

        $this->assertSame(HealthSummaryState::Healthy, $detail->healthSummaryState);
        $this->assertSame(0, $detail->consecutiveFailures);
    }

    public function test_a_failing_connection_shows_the_sanitized_diagnostic_and_failure_category(): void
    {
        $firm = Firm::factory()->activated()->create();
        $connection = $this->runWithFirmContext($firm, function () use ($firm) {
            $c = FirmIntegration::factory()->forFirm($firm)->create();
            app(HealthStateService::class)->recordCredentialError(
                $c->id,
                $firm->id,
                new SanitizedHealthDiagnostic(
                    SanitizedHealthDiagnostic::CATEGORY_CREDENTIAL_ERROR,
                    SanitizedHealthDiagnostic::OPERATION_TOKEN_REFRESH,
                    401,
                )
            );

            return $c;
        });

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $detail = app(IntegrationPlatformOversightReadService::class)->connectionDetail($admin, $firm, $connection->uuid);

        $this->assertSame(HealthSummaryState::ActionRequired, $detail->healthSummaryState);
        $this->assertSame('credential_error', $detail->lastFailureCategory);
        $this->assertNotNull($detail->sanitizedDiagnosticSummary);
        $this->assertSame(1, $detail->consecutiveFailures);
    }

    public function test_the_detail_page_renders_the_health_section_content(): void
    {
        $firm = Firm::factory()->activated()->create();
        $connection = $this->runWithFirmContext($firm, function () use ($firm) {
            $c = FirmIntegration::factory()->forFirm($firm)->create();
            app(HealthStateService::class)->recordProviderError(
                $c->id,
                $firm->id,
                new SanitizedHealthDiagnostic(
                    SanitizedHealthDiagnostic::CATEGORY_PROVIDER_ERROR,
                    SanitizedHealthDiagnostic::OPERATION_HEALTH_CHECK,
                    503,
                )
            );

            return $c;
        });

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(PlatformFirmIntegrationDetailPage::class, [
            'firmUuid' => $firm->uuid,
            'connectionUuid' => $connection->uuid,
        ]);

        $test->assertOk();
        $test->assertSee('provider_error');
    }

    public function test_a_connection_with_no_health_row_yet_defaults_to_healthy_with_null_diagnostic(): void
    {
        $firm = Firm::factory()->activated()->create();
        $connection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $detail = app(IntegrationPlatformOversightReadService::class)->connectionDetail($admin, $firm, $connection->uuid);

        $this->assertSame(HealthSummaryState::Healthy, $detail->healthSummaryState);
        $this->assertNull($detail->sanitizedDiagnosticSummary);
        $this->assertNull($detail->lastFailureCategory);
    }

    private function adminWithRole(PlatformRoleCode $role): PlatformAdmin
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, $role);

        return $admin;
    }
}
