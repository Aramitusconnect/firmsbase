<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Admin;

use App\Enums\PlatformRoleCode;
use App\Filament\Pages\PlatformFirmIntegrationDetailPage;
use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Providers\TestProvider\TestProvider;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Services\IntegrationPlatformOversightReadService;
use App\Services\PlatformRoleService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PlatformIntegrationConnectionDetailRenderingTest — Checkpoint 11
 * (frozen-design-post-security-review.md §7, §10, §12). Proves
 * IntegrationPlatformOversightReadService::connectionDetail() and
 * PlatformFirmIntegrationDetailPage render a real connection's data
 * correctly, 404 for a connection belonging to a DIFFERENT firm (never
 * silently cross-firm-leaked), and correctly mask/omit the fields
 * frozen design §10 requires.
 */
final class PlatformIntegrationConnectionDetailRenderingTest extends TestCase
{
    use RefreshDatabase;

    public function test_connection_detail_returns_correct_fields_for_a_real_connection(): void
    {
        $firm = Firm::factory()->activated()->create();
        $connection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create([
            'display_label' => 'Real Detail Connection',
            'status' => ConnectionStatus::Active->value,
            'external_account_id' => 'acct_1234567890',
        ]));

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $detail = app(IntegrationPlatformOversightReadService::class)->connectionDetail($admin, $firm, $connection->uuid);

        $this->assertNotNull($detail);
        $this->assertSame('Real Detail Connection', $detail->displayLabel);
        $this->assertSame(ConnectionStatus::Active, $detail->status);
        $this->assertSame(str_repeat('•', strlen('acct_1234567890') - 4).'7890', $detail->maskedExternalAccountId);
        $this->assertSame($firm->uuid, $detail->firmUuid);
    }

    public function test_connection_detail_returns_null_for_a_connection_belonging_to_a_different_firm(): void
    {
        $firm = Firm::factory()->activated()->create();
        $otherFirm = Firm::factory()->activated()->create();
        $otherConnection = $this->runWithFirmContext($otherFirm, fn () => FirmIntegration::factory()->forFirm($otherFirm)->create());

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $detail = app(IntegrationPlatformOversightReadService::class)->connectionDetail($admin, $firm, $otherConnection->uuid);

        $this->assertNull($detail, 'A connection uuid from a different firm must never resolve under the target firm.');
    }

    public function test_the_detail_page_renders_successfully_for_a_real_connection(): void
    {
        $firm = Firm::factory()->activated()->create();
        $connection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create([
            'display_label' => 'Rendered Detail Connection',
        ]));

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(PlatformFirmIntegrationDetailPage::class, [
            'firmUuid' => $firm->uuid,
            'connectionUuid' => $connection->uuid,
        ]);

        $test->assertOk();
        $test->assertSee('Rendered Detail Connection');
    }

    /**
     * CHECKPOINT 12 addition (frozen-design-post-security-review.md §5
     * N2, §8): mirrors
     * FirmIntegrationConnectionLifecycleActionsTest::test_a_real_test_provider_connections_seeded_display_name_is_visible_on_the_view_page()
     * at the SuperAdmin layer. FirmIntegrationFactory's default
     * `integration_provider_id` resolves the real, migration-seeded
     * `integration_providers` row (code='test', display_name='Internal
     * Test Provider (non-production)' — byte-for-byte
     * TestProvider::displayName()) — this proves that real, seeded copy
     * (never TestProvider::displayName() called live, per N2) is
     * genuinely visible on PlatformFirmIntegrationDetailPage's
     * "Provider: {$connection->providerDisplayName}" line.
     */
    public function test_a_real_test_provider_connections_seeded_display_name_is_visible_on_the_superadmin_detail_page(): void
    {
        $firm = Firm::factory()->activated()->create();
        $connection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create([
            'display_label' => 'SuperAdmin Provider Name Visibility Fixture',
        ]));

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(PlatformFirmIntegrationDetailPage::class, [
            'firmUuid' => $firm->uuid,
            'connectionUuid' => $connection->uuid,
        ]);

        $test->assertOk();
        $test->assertSee((new TestProvider)->displayName());
    }

    public function test_the_detail_page_404s_for_a_connection_uuid_belonging_to_a_different_firm(): void
    {
        $firm = Firm::factory()->activated()->create();
        $otherFirm = Firm::factory()->activated()->create();
        $otherConnection = $this->runWithFirmContext($otherFirm, fn () => FirmIntegration::factory()->forFirm($otherFirm)->create());

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(PlatformFirmIntegrationDetailPage::class, [
            'firmUuid' => $firm->uuid,
            'connectionUuid' => $otherConnection->uuid,
        ]);

        $test->assertNotFound();
    }

    public function test_the_detail_page_404s_for_a_nonexistent_connection_uuid(): void
    {
        $firm = Firm::factory()->activated()->create();
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(PlatformFirmIntegrationDetailPage::class, [
            'firmUuid' => $firm->uuid,
            'connectionUuid' => (string) Str::uuid(),
        ]);

        $test->assertNotFound();
    }

    private function adminWithRole(PlatformRoleCode $role): PlatformAdmin
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, $role);

        return $admin;
    }
}
