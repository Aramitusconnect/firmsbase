<?php

namespace Tests\Feature\Operations;

use App\Enums\BackupRestoreTestStatus;
use App\Enums\PlatformRoleCode;
use App\Filament\Pages\PlatformBackupsPage;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Services\BackupRestore\BackupRestoreDrillRunner;
use App\Services\BackupRestore\FakeBackupRestoreDrillRunner;
use App\Services\BackupRestoreCapabilityService;
use App\Services\BackupRestoreTestService;
use App\Services\PlatformRoleService;
use App\ValueObjects\BackupRestoreDrillResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Operations Control Plane — backup and restore truth.
 *
 * The failure mode guarded against here is subtle and dangerous: a
 * fixture returns 3600s, that number lands in a column called
 * `rpo_actual_seconds`, and a console renders it as "Actual RPO:
 * 3600s". Everything downstream — the readiness review, the customer
 * security questionnaire, the board slide — then inherits a
 * measurement that was never taken.
 */
class BackupRestoreTruthTest extends TestCase
{
    use RefreshDatabase;

    private function capability(): BackupRestoreCapabilityService
    {
        return app(BackupRestoreCapabilityService::class);
    }

    private function adminWithRole(PlatformRoleCode $role): PlatformAdmin
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, $role);

        return $admin;
    }

    private function recordSimulatedDrill(): void
    {
        app(BackupRestoreTestService::class)->runDrill(new FakeBackupRestoreDrillRunner);
    }

    public function test_only_a_fake_restore_runner_exists(): void
    {
        $this->assertFalse(
            $this->capability()->hasRealDrillRunner(),
            'a real BackupRestoreDrillRunner is now wired in — this mission\'s REAL_RESTORE finding must be revisited',
        );
    }

    public function test_no_backup_inventory_or_verified_pitr_is_claimed(): void
    {
        $this->assertFalse($this->capability()->hasBackupInventory());
        $this->assertFalse($this->capability()->hasVerifiedPitr());
    }

    public function test_a_simulated_drill_does_not_count_as_a_verified_restore(): void
    {
        $this->recordSimulatedDrill();

        $this->assertFalse(
            $this->capability()->hasVerifiedRestore(),
            'a recorded row produced by the fake runner is not evidence that anything was restored',
        );
    }

    public function test_actual_rpo_and_rto_read_not_yet_measured_despite_recorded_figures(): void
    {
        $this->recordSimulatedDrill();

        // The fixture wrote 3600/7200 into the actual columns.
        $this->assertDatabaseHas('backup_restore_tests', [
            'rpo_actual_seconds' => 3600,
            'rto_actual_seconds' => 7200,
        ]);

        // They must still not be presented as measurements.
        $this->assertNull($this->capability()->measuredActualRpoSeconds());
        $this->assertNull($this->capability()->measuredActualRtoSeconds());
        $this->assertSame('Not Yet Measured', $this->capability()->actualRpoLabel());
        $this->assertSame('Not Yet Measured', $this->capability()->actualRtoLabel());
    }

    public function test_recorded_figures_are_qualified_as_simulated(): void
    {
        $this->assertSame('simulated', $this->capability()->recordedFigureQualifier());
    }

    public function test_a_real_runner_flips_the_capability_and_the_labels(): void
    {
        // Proves the capability is derived from the binding, not
        // hardcoded — so wiring in a genuine runner later changes what
        // the console claims without anyone remembering to edit prose.
        $this->app->bind(BackupRestoreDrillRunner::class, fn (): BackupRestoreDrillRunner => new class implements BackupRestoreDrillRunner
        {
            public function run(?Firm $firm): BackupRestoreDrillResult
            {
                return new BackupRestoreDrillResult(
                    status: BackupRestoreTestStatus::Passed,
                    componentsVerified: ['database_records'],
                    rpoActualSeconds: 120,
                    rtoActualSeconds: 240,
                    notes: 'real',
                );
            }
        });

        $this->assertTrue($this->capability()->hasRealDrillRunner());
        $this->assertSame('measured', $this->capability()->recordedFigureQualifier());

        app(BackupRestoreTestService::class)->runDrill(app(BackupRestoreDrillRunner::class));

        $this->assertTrue($this->capability()->hasVerifiedRestore());
        $this->assertSame(120, $this->capability()->measuredActualRpoSeconds());
        $this->assertSame('120s (measured)', $this->capability()->actualRpoLabel());
    }

    public function test_the_disclosure_states_no_real_restore_has_happened(): void
    {
        $disclosure = $this->capability()->disclosure();

        $this->assertStringContainsString('NO REAL RESTORE HAS EVER BEEN PERFORMED', $disclosure);
        $this->assertStringContainsString('Not Yet Measured', $disclosure);
        $this->assertStringContainsString('NOT production-ready', $disclosure);
    }

    // --- Page rendering ---

    public function test_the_page_separates_target_from_actual(): void
    {
        $this->recordSimulatedDrill();

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $response = $this->actingAs($admin, 'platform_admin')->get(PlatformBackupsPage::getUrl());

        $response->assertOk();
        $response->assertSee('Target RPO (policy)');
        $response->assertSee('Actual RPO (measured in a real recovery): Not Yet Measured');
        $response->assertSee('Target RTO (policy)');
        $response->assertSee('Actual RTO (measured in a real recovery): Not Yet Measured');
    }

    public function test_the_page_labels_recorded_drill_rows_as_simulated(): void
    {
        $this->recordSimulatedDrill();

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $response = $this->actingAs($admin, 'platform_admin')->get(PlatformBackupsPage::getUrl());

        $response->assertOk();
        $response->assertSee('Simulated');
        $response->assertSee('Recorded RPO');
    }

    public function test_the_page_reports_inventory_and_pitr_as_unavailable(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $response = $this->actingAs($admin, 'platform_admin')->get(PlatformBackupsPage::getUrl());

        $response->assertOk();
        $response->assertSee('Backup inventory: Not Available');
        $response->assertSee('Point-in-time recovery: Unknown');
        $response->assertSee('Last verified real restore: Never');
    }

    /**
     * No executable drill action exists. Asserted structurally (no
     * Filament action of any kind is registered on the page) rather
     * than by scanning rendered text — the page deliberately mentions
     * the phrase "Run Drill" while explaining why no such button is
     * offered, so a text assertion would be checking the wrong thing.
     */
    public function test_no_run_drill_action_is_offered_anywhere_on_the_page(): void
    {
        $source = file_get_contents(app_path('Filament/Pages/PlatformBackupsPage.php'));

        $this->assertStringNotContainsString('Action::make(', $source);
        $this->assertStringNotContainsString('getHeaderActions', $source);
        $this->assertStringNotContainsString('recordActions', $source);
        $this->assertStringNotContainsString('runDrill(', $source);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $response = $this->actingAs($admin, 'platform_admin')->get(PlatformBackupsPage::getUrl());

        $response->assertOk();
        $response->assertSee('deliberately NOT provided');
    }
}
