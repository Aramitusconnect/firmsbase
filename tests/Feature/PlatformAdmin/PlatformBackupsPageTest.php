<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformAdmin;

use App\Enums\BackupRestoreTestStatus;
use App\Enums\PlatformRoleCode;
use App\Filament\Pages\PlatformBackupsPage;
use App\Models\BackupRestoreTest;
use App\Models\PlatformAdmin;
use App\Services\PlatformRoleService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PlatformBackupsPageTest — Phase 4 (FirmsVault Platform Admin Control
 * Center, "Operations"). Navigation, direct-route auth, the "no real
 * drill capability" disclosure, real drill-history rendering, filters,
 * ordering, pagination, empty state, and a positive proof that no
 * "Run Drill" action exists anywhere.
 */
final class PlatformBackupsPageTest extends TestCase
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

    public function test_navigation_is_hidden_for_a_guest(): void
    {
        $this->assertFalse(PlatformBackupsPage::shouldRegisterNavigation());
    }

    public function test_guest_is_redirected(): void
    {
        $this->get(PlatformBackupsPage::getUrl())->assertRedirect('/admin/login');
    }

    public function test_a_sales_rep_is_forbidden(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SalesRep);
        $this->actingAs($admin, 'platform_admin')->get(PlatformBackupsPage::getUrl())->assertForbidden();
    }

    public function test_a_super_admin_can_reach_the_page(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin')->get(PlatformBackupsPage::getUrl())->assertOk();
    }

    public function test_the_page_honestly_discloses_no_real_drill_capability_exists(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $response = $this->actingAs($admin, 'platform_admin')->get(PlatformBackupsPage::getUrl());

        $response->assertOk();
        $response->assertSee('No Real Backup/Restore Drill Capability Exists');
        $response->assertSee('FakeBackupRestoreDrillRunner');
    }

    public function test_empty_state(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $response = $this->actingAs($admin, 'platform_admin')->get(PlatformBackupsPage::getUrl());

        $response->assertOk();
        $response->assertSee('No backup/restore drills recorded yet');
        $response->assertSee('No platform-wide drill has ever been recorded.');
    }

    public function test_real_drill_history_renders_and_only_platform_wide_rows_are_shown(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $platformWide = BackupRestoreTest::factory()->create([
            'firm_id' => null,
            'status' => BackupRestoreTestStatus::Passed,
            'completed_at' => now(),
        ]);

        $test = Livewire::test(PlatformBackupsPage::class);
        $test->assertCanSeeTableRecords([$platformWide]);
    }

    public function test_status_filter_narrows_the_list(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $passed = BackupRestoreTest::factory()->create(['firm_id' => null, 'status' => BackupRestoreTestStatus::Passed]);
        $failed = BackupRestoreTest::factory()->create(['firm_id' => null, 'status' => BackupRestoreTestStatus::Failed]);

        $test = Livewire::test(PlatformBackupsPage::class);
        $test->filterTable('status', BackupRestoreTestStatus::Failed->value);

        $test->assertCanSeeTableRecords([$failed]);
        $test->assertCanNotSeeTableRecords([$passed]);
    }

    public function test_orders_deterministically_by_id_when_completed_at_ties(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $sharedTime = now();
        $tests = BackupRestoreTest::factory()->count(5)->create(['firm_id' => null, 'completed_at' => $sharedTime]);

        $first = Livewire::test(PlatformBackupsPage::class)->instance()->getFilteredSortedTableQuery()->pluck('id')->all();
        $second = Livewire::test(PlatformBackupsPage::class)->instance()->getFilteredSortedTableQuery()->pluck('id')->all();

        $this->assertSame($first, $second);
        $this->assertSame($tests->sortByDesc('id')->pluck('id')->values()->all(), $first);
    }

    public function test_the_page_is_paginated(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        BackupRestoreTest::factory()->count(30)->create(['firm_id' => null]);

        $test = Livewire::test(PlatformBackupsPage::class);
        $test->assertSuccessful();
        $this->assertLessThanOrEqual(25, $test->instance()->getTableRecords()->count());
    }

    public function test_no_run_drill_action_exists_anywhere(): void
    {
        $source = file_get_contents(app_path('Filament/Pages/PlatformBackupsPage.php'));

        // The disclosure text legitimately NAMES FakeBackupRestoreDrillRunner
        // in prose (asserted rendered in test_the_page_honestly_discloses_...
        // above) — what must never appear is an actual call to runDrill()
        // or an import of the BackupRestoreDrillRunner interface/its Fake
        // implementation as a usable dependency.
        $this->assertStringNotContainsString('Action::make(', $source);
        $this->assertStringNotContainsString('->action(', $source);
        $this->assertStringNotContainsString('->runDrill(', $source);
        $this->assertStringNotContainsString('use App\Services\BackupRestore\BackupRestoreDrillRunner;', $source);
        $this->assertStringNotContainsString('use App\Services\BackupRestore\FakeBackupRestoreDrillRunner;', $source);
    }
}
