<?php

declare(strict_types=1);

namespace Tests\Feature\Firm;

use App\Enums\FirmUserRole;
use App\Filament\Firm\Pages\PracticeAreasSettingsPage;
use App\Models\Firm;
use App\Models\FirmPracticeArea;
use App\Models\FirmUser;
use App\Models\PracticeArea;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PracticeAreasSettingsPageTest — PRAC-003. Mirrors
 * AccountingOverviewPageTest's real Livewire boot/registration smoke-test
 * style (no live browser in this environment) and
 * FirmSettingsPageAccessTest's "view-tier-for-everyone,
 * manage-tier-FirmOwner-only, defense-in-depth 403 on a forced call"
 * access shape — this page reuses FirmSettingsAccessPolicyService
 * exactly, so its own access proof follows the same pattern.
 *
 * Note: this table's records() closure maps rows into plain arrays
 * rather than Eloquent models/a query builder, so Filament's array-
 * record table keys each row by its (unconfigured) ArrayRecord key,
 * which defaults to the row's own collection index ("0", "1", ...) —
 * see PlatformOperationalActionsLivewireTest's own docblock for the
 * same precedent. `practice_areas` ships with real seed-migration data
 * (the "core" catalog from 2026_07_05_600001_create_practice_areas_table
 * plus the marketplace catalog from
 * 2026_11_10_100011_seed_marketplace_practice_area_catalog) — this
 * baseline data is inserted by a migration, not by RefreshDatabase's
 * per-test transaction, so it is present in every test. Each test that
 * needs a deterministic row index deactivates that pre-existing catalog
 * first (deactivateExistingCatalog()), then creates exactly one active
 * PracticeArea of its own, so the row under test is reliably index "0".
 */
final class PracticeAreasSettingsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('firm'));
    }

    // ------------------------------------------------------------
    // Rendering / listing.
    // ------------------------------------------------------------

    public function test_the_page_lists_active_practice_areas_for_an_authorized_firm_user(): void
    {
        $this->deactivateExistingCatalog();

        $firm = Firm::factory()->create();
        $practiceArea = PracticeArea::factory()->create(['name' => 'Immigration Consulting', 'is_active' => true]);
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $this->runWithFirmContext($firm, function () use ($practiceArea): void {
            $test = Livewire::test(PracticeAreasSettingsPage::class);
            $test->assertOk();
            $test->call('loadTable');
            $test->assertSee($practiceArea->name);
            $test->assertSee('Disabled');
        });
    }

    public function test_inactive_practice_areas_are_not_listed(): void
    {
        $this->deactivateExistingCatalog();

        $firm = Firm::factory()->create();
        $active = PracticeArea::factory()->create(['name' => 'Active Area', 'is_active' => true]);
        $inactive = PracticeArea::factory()->create(['name' => 'Retired Area', 'is_active' => false]);
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $this->runWithFirmContext($firm, function () use ($active, $inactive): void {
            $test = Livewire::test(PracticeAreasSettingsPage::class);
            $test->assertOk();
            $test->call('loadTable');
            $test->assertSee($active->name);
            $test->assertDontSee($inactive->name);
        });
    }

    // ------------------------------------------------------------
    // Toggling — enable/disable, timestamp stamping.
    // ------------------------------------------------------------

    public function test_enabling_a_practice_area_creates_a_firm_practice_area_row_and_stamps_enabled_at(): void
    {
        $this->deactivateExistingCatalog();

        $firm = Firm::factory()->create();
        $practiceArea = PracticeArea::factory()->create(['is_active' => true]);
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $this->runWithFirmContext($firm, function () use ($firm, $practiceArea): void {
            $test = Livewire::test(PracticeAreasSettingsPage::class);
            $test->assertOk();
            $test->call('loadTable');
            $test->mountTableAction('toggle', '0');
            $test->callMountedTableAction();
            $test->assertHasNoTableActionErrors();
            $test->assertNotified('Practice area enabled');

            $row = FirmPracticeArea::query()
                ->where('firm_id', $firm->id)
                ->where('practice_area_id', $practiceArea->id)
                ->first();

            $this->assertNotNull($row);
            $this->assertTrue($row->is_enabled);
            $this->assertNotNull($row->enabled_at);
        });
    }

    public function test_disabling_an_enabled_practice_area_stamps_disabled_at(): void
    {
        $this->deactivateExistingCatalog();

        $firm = Firm::factory()->create();
        $practiceArea = PracticeArea::factory()->create(['is_active' => true]);
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $this->runWithFirmContext($firm, fn () => FirmPracticeArea::factory()
            ->forFirm($firm)
            ->forPracticeArea($practiceArea)
            ->create(['is_enabled' => true, 'enabled_at' => now()->subDay(), 'disabled_at' => null]));

        $this->runWithFirmContext($firm, function () use ($firm, $practiceArea): void {
            $test = Livewire::test(PracticeAreasSettingsPage::class);
            $test->assertOk();
            $test->call('loadTable');
            $test->mountTableAction('toggle', '0');
            $test->callMountedTableAction();
            $test->assertHasNoTableActionErrors();
            $test->assertNotified('Practice area disabled');

            $row = FirmPracticeArea::query()
                ->where('firm_id', $firm->id)
                ->where('practice_area_id', $practiceArea->id)
                ->first();

            $this->assertNotNull($row);
            $this->assertFalse($row->is_enabled);
            $this->assertNotNull($row->disabled_at);
        });
    }

    // ------------------------------------------------------------
    // Tenant scoping — toggling firm A never touches firm B's row.
    // ------------------------------------------------------------

    public function test_toggling_is_firm_scoped_and_does_not_affect_another_firms_row(): void
    {
        $this->deactivateExistingCatalog();

        $practiceArea = PracticeArea::factory()->create(['is_active' => true]);

        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $rowB = $this->runWithFirmContext($firmB, fn () => FirmPracticeArea::factory()
            ->forFirm($firmB)
            ->forPracticeArea($practiceArea)
            ->create(['is_enabled' => true, 'enabled_at' => now()->subDay(), 'disabled_at' => null]));

        $this->actingAsRole($firmA, FirmUserRole::FirmOwner);

        $this->runWithFirmContext($firmA, function (): void {
            $test = Livewire::test(PracticeAreasSettingsPage::class);
            $test->assertOk();
            $test->call('loadTable');
            $test->mountTableAction('toggle', '0');
            $test->callMountedTableAction();
            $test->assertHasNoTableActionErrors();
            $test->assertNotified('Practice area enabled');
        });

        $rowA = $this->runWithFirmContext($firmA, fn () => FirmPracticeArea::query()
            ->where('firm_id', $firmA->id)
            ->where('practice_area_id', $practiceArea->id)
            ->first());
        $this->assertNotNull($rowA);
        $this->assertTrue($rowA->is_enabled, "Firm A's own row must have been enabled.");

        $freshRowB = $this->runWithFirmContext($firmB, fn () => FirmPracticeArea::query()->find($rowB->id));
        $this->assertTrue($freshRowB->is_enabled, "Firm B's row must remain untouched by firm A's toggle.");
        $this->assertNull($freshRowB->disabled_at, "Firm B's row must remain untouched by firm A's toggle.");
        $this->assertSame(
            $rowB->enabled_at->toDateTimeString(),
            $freshRowB->enabled_at->toDateTimeString(),
            "Firm B's own enabled_at must not change from firm A's action."
        );
    }

    // ------------------------------------------------------------
    // Authorization.
    // ------------------------------------------------------------

    public function test_a_guest_cannot_access_the_page_at_all(): void
    {
        $this->assertFalse(PracticeAreasSettingsPage::canAccess());
    }

    public function test_a_guest_is_redirected_away_from_the_page_route(): void
    {
        $response = $this->get(PracticeAreasSettingsPage::getUrl());

        $response->assertRedirect();
    }

    public function test_a_non_owner_role_cannot_toggle_a_practice_area_and_a_forced_call_has_no_effect(): void
    {
        $this->deactivateExistingCatalog();

        $firm = Firm::factory()->create();
        $practiceArea = PracticeArea::factory()->create(['is_active' => true]);
        $this->actingAsRole($firm, FirmUserRole::Attorney);

        $this->runWithFirmContext($firm, function () use ($firm, $practiceArea): void {
            $test = Livewire::test(PracticeAreasSettingsPage::class);
            $test->assertOk();
            $test->call('loadTable');

            $test->assertTableActionHidden('toggle', '0');

            // Defense-in-depth: even a forced mount/call of the hidden
            // action must have no effect. Filament's own mountAction()
            // lifecycle already refuses to resolve a ->visible()-hidden
            // action at all (it silently unmounts, per
            // InteractsWithActions::mountAction()), so the underlying
            // toggle() closure — and its own abort_unless(canManage(),
            // 403) check — never even runs here; this asserts the
            // observable outcome (no notification, no row written)
            // rather than a specific HTTP status, since a hidden action
            // is refused before it is ever invoked.
            $test->mountTableAction('toggle', '0');
            $test->callMountedTableAction();
            $test->assertNotNotified();

            $row = FirmPracticeArea::query()
                ->where('firm_id', $firm->id)
                ->where('practice_area_id', $practiceArea->id)
                ->first();

            $this->assertNull($row, 'A blocked toggle must never create a FirmPracticeArea row.');
        });
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    /**
     * Deactivates every pre-existing (migration-seeded) PracticeArea row
     * so a test's own factory-created row is the only active one — and
     * therefore reliably lands at table row index "0". Safe: PracticeArea
     * is a global catalog with no RLS, and RefreshDatabase rolls back
     * this whole test's transaction afterward, so real seed data is
     * never actually lost.
     */
    private function deactivateExistingCatalog(): void
    {
        PracticeArea::query()->update(['is_active' => false]);
    }

    private function actingAsRole(Firm $firm, FirmUserRole $role): FirmUser
    {
        $firmUser = $this->runWithFirmContext(
            $firm,
            fn () => FirmUser::factory()->forFirm($firm)->forUser(User::factory()->create())->role($role)->create()
        );

        $this->actingAs($firmUser->user);

        return $firmUser;
    }
}
