<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Admin;

use App\Enums\PlatformRoleCode;
use App\Filament\Pages\PlatformFirmIntegrationDetailPage;
use App\Filament\Pages\PlatformFirmIntegrationsPage;
use App\Integrations\Models\FirmIntegration;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Services\IntegrationPlatformOversightReadService;
use App\Services\PlatformRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PlatformIntegrationWebhookViewTest — Checkpoint 11 (frozen-design-
 * post-security-review.md §10 item 1). Proves webhook routing status
 * (configured yes/no, derived from integration_webhook_routing_index's
 * own existence — never from FirmIntegration.webhook_routing_token) is
 * rendered correctly, and — the load-bearing property — that the raw
 * webhook_routing_token is NEVER present anywhere: not in the DTO, not
 * in any rendered page, not even when planted with a real marker value.
 */
final class PlatformIntegrationWebhookViewTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN_MARKER = 'SECRET-MARKER-webhook-routing-token-4b7e9a2f1d8c3650';

    public function test_webhook_routing_configured_is_true_when_a_routing_index_row_exists(): void
    {
        $firm = Firm::factory()->activated()->create();
        $connection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create([
            'webhook_routing_token' => self::TOKEN_MARKER,
        ]));

        $this->runWithFirmContext($firm, fn () => DB::table('integration_webhook_routing_index')->insert([
            'firm_integration_id' => $connection->id,
            'firm_id' => $firm->id,
            'integration_provider_id' => $connection->integration_provider_id,
            'webhook_routing_token_hash' => hash('sha256', self::TOKEN_MARKER.Str::random(8)),
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $detail = app(IntegrationPlatformOversightReadService::class)->connectionDetail($admin, $firm, $connection->uuid);

        $this->assertTrue($detail->webhookRoutingConfigured);
    }

    public function test_webhook_routing_configured_is_false_when_no_routing_index_row_exists(): void
    {
        $firm = Firm::factory()->activated()->create();
        $connection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $detail = app(IntegrationPlatformOversightReadService::class)->connectionDetail($admin, $firm, $connection->uuid);

        $this->assertFalse($detail->webhookRoutingConfigured);
    }

    public function test_the_raw_webhook_routing_token_never_appears_on_the_connection_summary_dto(): void
    {
        $firm = Firm::factory()->activated()->create();
        $connection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create([
            'webhook_routing_token' => self::TOKEN_MARKER,
        ]));

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $detail = app(IntegrationPlatformOversightReadService::class)->connectionDetail($admin, $firm, $connection->uuid);

        $encoded = json_encode(get_object_vars($detail));
        $this->assertStringNotContainsString(self::TOKEN_MARKER, $encoded);
    }

    public function test_the_firm_integrations_list_page_never_renders_the_token_marker(): void
    {
        $firm = Firm::factory()->activated()->create();
        $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create([
            'webhook_routing_token' => self::TOKEN_MARKER,
        ]));

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(PlatformFirmIntegrationsPage::class, ['firmUuid' => $firm->uuid]);
        $test->assertOk();
        $test->assertDontSee(self::TOKEN_MARKER);
    }

    public function test_the_connection_detail_page_never_renders_the_token_marker_but_shows_routing_configured_status(): void
    {
        $firm = Firm::factory()->activated()->create();
        $connection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create([
            'webhook_routing_token' => self::TOKEN_MARKER,
        ]));

        $this->runWithFirmContext($firm, fn () => DB::table('integration_webhook_routing_index')->insert([
            'firm_integration_id' => $connection->id,
            'firm_id' => $firm->id,
            'integration_provider_id' => $connection->integration_provider_id,
            'webhook_routing_token_hash' => hash('sha256', self::TOKEN_MARKER.Str::random(8)),
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(PlatformFirmIntegrationDetailPage::class, [
            'firmUuid' => $firm->uuid,
            'connectionUuid' => $connection->uuid,
        ]);

        $test->assertOk();
        $test->assertDontSee(self::TOKEN_MARKER);
        $test->assertSee('Webhook routing configured: Yes');
    }

    public function test_the_read_service_never_selects_the_webhook_routing_token_column_at_all(): void
    {
        // The docblock legitimately DISCUSSES the column by name (in
        // backticks, as prose) — this checks the column is never
        // actually referenced as executable code: neither as a quoted
        // array-literal/column-selection string, nor as a direct model
        // attribute access. Mirrors
        // FirmIntegrationSuperAdminBoundaryStructuralTest's own
        // ->webhook_routing_token attribute-access-shape convention.
        $source = file_get_contents(app_path('Services/IntegrationPlatformOversightReadService.php'));
        $this->assertIsString($source);

        $this->assertStringNotContainsString("'webhook_routing_token'", $source);
        $this->assertDoesNotMatchRegularExpression('/->webhook_routing_token\b/', $source);
    }

    private function adminWithRole(PlatformRoleCode $role): PlatformAdmin
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, $role);

        return $admin;
    }
}
