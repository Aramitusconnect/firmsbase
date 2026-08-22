<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Admin;

use App\Enums\PlatformRoleCode;
use App\Enums\SupportAccessSessionStatus;
use App\Filament\Pages\PlatformFirmIntegrationsPage;
use App\Integrations\Models\FirmIntegration;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Models\SupportAccessRequest;
use App\Models\SupportAccessSession;
use App\Services\PlatformRoleService;
use App\Services\TenantContextService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * CrossRequestTenantContextIsolationTest — Checkpoint 11 (frozen-design-
 * post-security-review.md §2, §6). Two-sequential-request proof that
 * Firm A's tenant context never leaks into a subsequent request that
 * should only ever see Firm B — both at the PHP-memory/database-session
 * level (TenantContextService::hasFirmContext()/app.current_firm_id) and
 * at the rendered-output level (Firm A's connection never appears on
 * Firm B's page and vice versa).
 */
final class CrossRequestTenantContextIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_sequential_page_mounts_for_different_firms_never_leak_context_between_them(): void
    {
        $firmA = Firm::factory()->activated()->create();
        $firmB = Firm::factory()->activated()->create();

        $connectionA = $this->runWithFirmContext($firmA, fn () => FirmIntegration::factory()->forFirm($firmA)->create(['display_label' => 'Connection Alpha Only']));
        $connectionB = $this->runWithFirmContext($firmB, fn () => FirmIntegration::factory()->forFirm($firmB)->create(['display_label' => 'Connection Bravo Only']));

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin, 'platform_admin');

        // Sanity: no context active before we start.
        $this->assertFalse(app(TenantContextService::class)->hasFirmContext());

        // "Request" 1: mount the firm-scoped page for Firm A.
        $testA = Livewire::test(PlatformFirmIntegrationsPage::class, ['firmUuid' => $firmA->uuid]);
        $testA->assertOk();
        $testA->assertSee('Connection Alpha Only');
        $testA->assertDontSee('Connection Bravo Only');

        // Context must be fully torn down between "requests" — nothing
        // Livewire::test() does here leaves a residual context active.
        $this->assertFalse(app(TenantContextService::class)->hasFirmContext(), 'Firm A context must not survive past its own request.');
        $this->assertNoDatabaseTenantContext();

        // "Request" 2: mount the SAME page class for a DIFFERENT firm —
        // must see ONLY Firm B's own data.
        $testB = Livewire::test(PlatformFirmIntegrationsPage::class, ['firmUuid' => $firmB->uuid]);
        $testB->assertOk();
        $testB->assertSee('Connection Bravo Only');
        $testB->assertDontSee('Connection Alpha Only');

        $this->assertFalse(app(TenantContextService::class)->hasFirmContext());
        $this->assertNoDatabaseTenantContext();
    }

    public function test_two_sequential_real_http_requests_for_different_firms_never_leak_context_between_them(): void
    {
        $firmA = Firm::factory()->activated()->create();
        $firmB = Firm::factory()->activated()->create();

        $this->runWithFirmContext($firmA, fn () => FirmIntegration::factory()->forFirm($firmA)->create(['display_label' => 'HTTP Alpha Connection']));
        $this->runWithFirmContext($firmB, fn () => FirmIntegration::factory()->forFirm($firmB)->create(['display_label' => 'HTTP Bravo Connection']));

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $responseA = $this->get(PlatformFirmIntegrationsPage::getUrl(['firmUuid' => $firmA->uuid]));
        $responseA->assertOk();
        $responseA->assertSee('HTTP Alpha Connection', false);
        $responseA->assertDontSee('HTTP Bravo Connection', false);

        $this->assertFalse(app(TenantContextService::class)->hasFirmContext());
        $this->assertNoDatabaseTenantContext();

        $responseB = $this->get(PlatformFirmIntegrationsPage::getUrl(['firmUuid' => $firmB->uuid]));
        $responseB->assertOk();
        $responseB->assertSee('HTTP Bravo Connection', false);
        $responseB->assertDontSee('HTTP Alpha Connection', false);

        $this->assertFalse(app(TenantContextService::class)->hasFirmContext());
        $this->assertNoDatabaseTenantContext();
    }

    public function test_a_denied_second_firm_request_does_not_inherit_context_from_a_successful_first_request(): void
    {
        $firmA = Firm::factory()->activated()->create();
        $firmB = Firm::factory()->activated()->create();

        $admin = $this->adminWithRole(PlatformRoleCode::SupportAgent);
        $this->activeSessionFor($admin, $firmA);
        // Deliberately NO active session for firmB.

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin, 'platform_admin');

        $testA = Livewire::test(PlatformFirmIntegrationsPage::class, ['firmUuid' => $firmA->uuid]);
        $testA->assertOk();

        // If Firm A's context somehow leaked/was reused, this could
        // wrongly appear to pass the per-firm session check for Firm B
        // too. It must not.
        $testB = Livewire::test(PlatformFirmIntegrationsPage::class, ['firmUuid' => $firmB->uuid]);
        $testB->assertForbidden();
    }

    private function adminWithRole(PlatformRoleCode $role): PlatformAdmin
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, $role);

        return $admin;
    }

    private function activeSessionFor(PlatformAdmin $admin, Firm $firm): void
    {
        $request = $this->runWithFirmContext(
            $firm,
            fn () => SupportAccessRequest::factory()->forFirm($firm)->create(['requested_by' => $admin->id])
        );

        $this->runWithFirmContext($firm, fn () => SupportAccessSession::factory()->create([
            'firm_id' => $firm->id,
            'support_access_request_id' => $request->id,
            'platform_admin_id' => $admin->id,
            'status' => SupportAccessSessionStatus::Active->value,
            'started_at' => now(),
            'expires_at' => now()->addHour(),
        ]));
    }
}
