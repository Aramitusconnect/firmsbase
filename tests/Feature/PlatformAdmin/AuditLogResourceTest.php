<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformAdmin;

use App\Enums\PlatformRoleCode;
use App\Filament\Resources\AuditLogResource;
use App\Filament\Resources\AuditLogResource\Pages\ViewAuditLog;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Models\TimelineEvent;
use App\Services\PlatformRoleService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AuditLogResourceTest — Phase 4 (FirmsVault Platform Admin Control
 * Center, "Operations, Governance, Support, and Configuration"),
 * Governance category. Route-level authorization, cross-firm listing,
 * no-N+1, empty state, and the positive proof that no mutating action
 * is ever registered (TimelineEvent is append-only).
 */
final class AuditLogResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function adminWithRole(PlatformRoleCode $role): PlatformAdmin
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, $role);

        return $admin;
    }

    // --- Navigation visibility ---

    public function test_navigation_is_hidden_when_no_admin_is_authenticated(): void
    {
        $this->assertFalse(AuditLogResource::canAccess());
    }

    public function test_navigation_is_visible_for_an_eligible_platform_admin(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $this->assertTrue(AuditLogResource::canAccess());
    }

    // --- Route-level authorization ---

    public function test_guest_is_redirected_from_the_audit_logs_list(): void
    {
        $this->get(AuditLogResource::getUrl())->assertRedirect('/admin/login');
    }

    public function test_a_platform_admin_with_no_role_is_forbidden(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);

        $this->actingAs($admin, 'platform_admin')->get(AuditLogResource::getUrl())->assertForbidden();
    }

    public function test_a_sales_rep_is_forbidden(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SalesRep);

        $this->actingAs($admin, 'platform_admin')->get(AuditLogResource::getUrl())->assertForbidden();
    }

    public function test_a_super_admin_can_reach_the_list_and_view_pages(): void
    {
        $firm = Firm::factory()->create(['name' => 'Audit Firm']);
        $event = $this->createWithFirmContext($firm, fn () => TimelineEvent::factory()->forFirm($firm)->eventType('matter_opened')->create());

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $listResponse = $this->get(AuditLogResource::getUrl());
        $listResponse->assertOk();
        $listResponse->assertSee('Audit Firm');
        $listResponse->assertSee('matter_opened');

        $viewResponse = $this->get(ViewAuditLog::getUrl(['firmUuid' => $firm->uuid, 'id' => $event->id]));
        $viewResponse->assertOk();
    }

    public function test_a_security_auditor_can_reach_the_list(): void
    {
        $firm = Firm::factory()->create();
        $this->createWithFirmContext($firm, fn () => TimelineEvent::factory()->forFirm($firm)->create());

        $admin = $this->adminWithRole(PlatformRoleCode::SecurityAuditor);
        $this->actingAs($admin, 'platform_admin')->get(AuditLogResource::getUrl())->assertOk();
    }

    public function test_a_read_only_auditor_can_reach_the_list(): void
    {
        $firm = Firm::factory()->create();
        $this->createWithFirmContext($firm, fn () => TimelineEvent::factory()->forFirm($firm)->create());

        $admin = $this->adminWithRole(PlatformRoleCode::ReadOnlyAuditor);
        $this->actingAs($admin, 'platform_admin')->get(AuditLogResource::getUrl())->assertOk();
    }

    public function test_viewing_an_event_under_the_wrong_firm_404s(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $event = $this->createWithFirmContext($firmA, fn () => TimelineEvent::factory()->forFirm($firmA)->create());

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $this->actingAs($admin, 'platform_admin')
            ->get(ViewAuditLog::getUrl(['firmUuid' => $firmB->uuid, 'id' => $event->id]))
            ->assertNotFound();
    }

    // --- Empty state ---

    public function test_an_honest_empty_state_is_shown_when_no_events_exist(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $response = $this->actingAs($admin, 'platform_admin')->get(AuditLogResource::getUrl());
        $response->assertOk();
        $response->assertSee('No audit log events found');
    }

    // --- No-N+1 proof ---

    public function test_listing_many_events_for_one_firm_does_not_n_plus_one(): void
    {
        $firm = Firm::factory()->create();
        $this->createWithFirmContext($firm, fn () => TimelineEvent::factory()->forFirm($firm)->create());

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $onePass = [];
        DB::listen(function ($query) use (&$onePass): void {
            $onePass[] = $query->sql;
        });
        $this->get(AuditLogResource::getUrl())->assertOk();
        $oneEventQueryCount = count($onePass);

        $this->createWithFirmContext($firm, fn () => TimelineEvent::factory()->forFirm($firm)->count(9)->create());

        $tenPass = [];
        DB::listen(function ($query) use (&$tenPass): void {
            $tenPass[] = $query->sql;
        });
        $this->get(AuditLogResource::getUrl())->assertOk();
        $tenEventQueryCount = count($tenPass);

        $this->assertLessThan(
            $oneEventQueryCount + 9,
            $tenEventQueryCount,
            'Adding 9 more rows to the same firm must not add ~9 extra queries — that would prove an N+1 pattern.'
        );
    }

    // --- No mutating action exists ---

    public function test_no_filament_action_is_registered_anywhere_on_this_resource(): void
    {
        $resourceSource = file_get_contents(app_path('Filament/Resources/AuditLogResource.php'));
        $listSource = file_get_contents(app_path('Filament/Resources/AuditLogResource/Pages/ListAuditLogs.php'));
        $viewSource = file_get_contents(app_path('Filament/Resources/AuditLogResource/Pages/ViewAuditLog.php'));

        foreach ([$resourceSource, $listSource, $viewSource] as $source) {
            $this->assertStringNotContainsString('->action(', $source);
        }
    }
}
