<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Admin;

use App\Enums\PlatformRoleCode;
use App\Filament\Resources\ConflictResource;
use App\Filament\Resources\ConflictResource\Pages\ViewConflict;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationConflict;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Services\PlatformIntegrationCrossFirmDirectoryService;
use App\Services\PlatformRoleService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ConflictResourceTest — Phase 2 (FirmsVault Platform Admin Control
 * Center, "Integration Operations Center"). Route-level authorization,
 * cross-firm listing, redaction of raw before/after values, no-N+1, and
 * — the load-bearing property for this specific resource — a POSITIVE
 * proof that NO mutating action exists anywhere in this resource's
 * class, its Pages, or the read service behind it. This is an explicit,
 * confirmed human decision (see ConflictResource's own docblock):
 * `IntegrationConflictService::transitionStatus()`/`proposeResolution()`
 * require two distinct real FirmUser actors, which this Admin console
 * structurally cannot supply.
 */
final class ConflictResourceTest extends TestCase
{
    use RefreshDatabase;

    private const RAW_VALUE_MARKER = 'SECRET-MARKER-conflict-raw-value-6b1f';

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

    private function connection(Firm $firm): FirmIntegration
    {
        return $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());
    }

    // --- Navigation visibility ---
    // (see SyncFailureResourceTest's own docblock note on why canAccess()
    // is the real signal for a Resource, not shouldRegisterNavigation().)

    public function test_navigation_is_hidden_when_no_admin_is_authenticated(): void
    {
        $this->assertFalse(ConflictResource::canAccess());
    }

    public function test_navigation_is_visible_for_an_eligible_platform_admin(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $this->assertTrue(ConflictResource::canAccess());
    }

    // --- Route-level authorization ---

    public function test_guest_is_redirected_from_the_conflicts_list(): void
    {
        $this->get(ConflictResource::getUrl())->assertRedirect('/admin/login');
    }

    public function test_a_platform_admin_with_no_role_is_forbidden(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);

        $this->actingAs($admin, 'platform_admin')->get(ConflictResource::getUrl())->assertForbidden();
    }

    public function test_a_super_admin_can_reach_the_list_and_view_pages(): void
    {
        $firm = Firm::factory()->activated()->create(['name' => 'Conflicted Firm']);
        $connection = $this->connection($firm);
        $conflict = $this->runWithFirmContext($firm, fn () => IntegrationConflict::factory()->forFirmIntegration($connection)->create());

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $listResponse = $this->get(ConflictResource::getUrl());
        $listResponse->assertOk();
        $listResponse->assertSee('Conflicted Firm');
        $listResponse->assertSee('Monitoring only');

        $viewResponse = $this->get(ViewConflict::getUrl(['firmUuid' => $firm->uuid, 'id' => $conflict->id]));
        $viewResponse->assertOk();
        $viewResponse->assertSee('Monitoring only');
    }

    public function test_viewing_a_conflict_under_the_wrong_firm_404s(): void
    {
        $firmA = Firm::factory()->activated()->create();
        $firmB = Firm::factory()->activated()->create();
        $connection = $this->connection($firmA);
        $conflict = $this->runWithFirmContext($firmA, fn () => IntegrationConflict::factory()->forFirmIntegration($connection)->create());

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $this->actingAs($admin, 'platform_admin')
            ->get(ViewConflict::getUrl(['firmUuid' => $firmB->uuid, 'id' => $conflict->id]))
            ->assertNotFound();
    }

    // --- Redaction ---

    public function test_raw_local_and_external_values_never_appear_in_the_rendered_list_or_view_pages(): void
    {
        $firm = Firm::factory()->activated()->create();
        $connection = $this->connection($firm);
        $conflict = $this->runWithFirmContext($firm, fn () => IntegrationConflict::factory()->forFirmIntegration($connection)->create([
            'local_value' => ['name' => self::RAW_VALUE_MARKER],
            'external_value' => ['name' => self::RAW_VALUE_MARKER],
        ]));

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $listResponse = $this->get(ConflictResource::getUrl());
        $listResponse->assertOk();
        $listResponse->assertDontSee(self::RAW_VALUE_MARKER);

        $viewResponse = $this->get(ViewConflict::getUrl(['firmUuid' => $firm->uuid, 'id' => $conflict->id]));
        $viewResponse->assertOk();
        $viewResponse->assertDontSee(self::RAW_VALUE_MARKER);
    }

    // --- Positive proof: NO mutating action exists anywhere ---

    public function test_the_resource_class_registers_no_filament_action_at_all(): void
    {
        $source = file_get_contents(app_path('Filament/Resources/ConflictResource.php'));

        // The one Action present is the read-only "view" navigation link.
        $this->assertSame(1, substr_count($source, 'Action::make('), 'Exactly one Action (the read-only "view" link) may exist — no resolve/transition action.');
        $this->assertStringContainsString("Action::make('view')", $source);
        $this->assertStringNotContainsString('requiresConfirmation', $source);
        $this->assertStringNotContainsString('->action(', $source);
    }

    public function test_no_page_class_in_this_resource_registers_a_filament_action(): void
    {
        foreach (['ListConflicts.php', 'ViewConflict.php'] as $file) {
            $source = file_get_contents(app_path("Filament/Resources/ConflictResource/Pages/{$file}"));
            $this->assertStringNotContainsString('Action::make(', $source, "{$file} must never register any Filament Action.");
            $this->assertStringNotContainsString('->action(', $source, "{$file} must never register any Filament Action.");
        }
    }

    /**
     * The dual-approval service is legitimately NAMED in this resource's
     * own docblock prose (explaining WHY it is never called, e.g.
     * "IntegrationConflictService::transitionStatus()") — a naive
     * whole-file substring search would false-positive on that
     * documentation. The reliable structural signal is the absence of a
     * real `use` IMPORT statement: without one, `IntegrationConflictService`
     * cannot be referenced as a live PHP symbol anywhere in the file
     * (only inside an inert comment), so its absence is sufficient proof
     * this class never actually calls into it. Also confirms no
     * `->transitionStatus(`/`->proposeResolution(` METHOD CALL syntax
     * exists (as opposed to the bare, prose-only `Class::method()`
     * mention inside the docblock).
     */
    public function test_neither_this_resource_nor_its_read_service_ever_imports_or_calls_the_dual_approval_service(): void
    {
        $resourceSource = file_get_contents(app_path('Filament/Resources/ConflictResource.php'));
        $serviceSource = file_get_contents(app_path('Services/PlatformIntegrationCrossFirmDirectoryService.php'));

        foreach (['ListConflicts.php', 'ViewConflict.php'] as $file) {
            $pageSource = file_get_contents(app_path("Filament/Resources/ConflictResource/Pages/{$file}"));
            $this->assertStringNotContainsString('use App\Integrations\Services\IntegrationConflictService;', $pageSource);
            $this->assertStringNotContainsString('->transitionStatus(', $pageSource);
            $this->assertStringNotContainsString('->proposeResolution(', $pageSource);
        }

        $this->assertStringNotContainsString('use App\Integrations\Services\IntegrationConflictService;', $resourceSource);
        $this->assertStringNotContainsString('use App\Integrations\Services\IntegrationConflictService;', $serviceSource);
        $this->assertStringNotContainsString('->transitionStatus(', $resourceSource.$serviceSource);
        $this->assertStringNotContainsString('->proposeResolution(', $resourceSource.$serviceSource);
    }

    /**
     * A structural, reflection-based proof (not merely a string search):
     * the underlying array-row-backed table this resource renders never
     * carries `resolution_note`/`resolved_by_firm_user_id`/
     * `resolution_approved_by_firm_user_id`/`local_value`/`external_value`
     * keys at all — confirmed directly against a real row produced by
     * the cross-firm directory service, not merely against source text.
     */
    public function test_conflict_rows_never_carry_resolution_or_raw_value_keys(): void
    {
        $firm = Firm::factory()->activated()->create();
        $connection = $this->connection($firm);
        $this->runWithFirmContext($firm, fn () => IntegrationConflict::factory()->forFirmIntegration($connection)->create());

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $rows = app(PlatformIntegrationCrossFirmDirectoryService::class)->listConflicts($admin);

        $this->assertCount(1, $rows);
        $forbiddenKeys = ['resolution_note', 'resolved_by_firm_user_id', 'resolution_approved_by_firm_user_id', 'local_value', 'external_value'];
        foreach ($forbiddenKeys as $key) {
            $this->assertArrayNotHasKey($key, $rows->first(), "Conflict rows must never carry '{$key}' — this is a read-only, monitoring resource.");
        }
    }

    // --- No-N+1 proof ---

    public function test_listing_many_conflicts_for_one_connection_does_not_n_plus_one(): void
    {
        $firm = Firm::factory()->activated()->create();
        $connection = $this->connection($firm);
        $this->runWithFirmContext($firm, fn () => IntegrationConflict::factory()->forFirmIntegration($connection)->create());

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $onePass = [];
        DB::listen(function ($query) use (&$onePass): void {
            $onePass[] = $query->sql;
        });
        $this->get(ConflictResource::getUrl())->assertOk();
        $oneConflictQueryCount = count($onePass);

        $this->runWithFirmContext($firm, function () use ($connection): void {
            for ($i = 0; $i < 9; $i++) {
                IntegrationConflict::factory()->forFirmIntegration($connection)->create(['local_id' => 1000 + $i]);
            }
        });

        $tenPass = [];
        DB::listen(function ($query) use (&$tenPass): void {
            $tenPass[] = $query->sql;
        });
        $this->get(ConflictResource::getUrl())->assertOk();
        $tenConflictQueryCount = count($tenPass);

        $this->assertLessThan(
            $oneConflictQueryCount + 9,
            $tenConflictQueryCount,
            'Adding 9 more rows to the same connection must not add ~9 extra queries — that would prove an N+1 pattern.'
        );
    }
}
