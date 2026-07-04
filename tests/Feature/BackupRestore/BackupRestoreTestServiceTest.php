<?php

namespace Tests\Feature\BackupRestore;

use App\Enums\BackupRestoreTestStatus;
use App\Models\Firm;
use App\Services\BackupRestore\FakeBackupRestoreDrillRunner;
use App\Services\BackupRestoreTestService;
use App\ValueObjects\BackupRestoreDrillResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackupRestoreTestServiceTest extends TestCase
{
    use RefreshDatabase;

    private BackupRestoreTestService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BackupRestoreTestService();
    }

    public function test_run_drill_never_touches_real_infrastructure_it_only_records_the_runners_result(): void
    {
        $runner = new FakeBackupRestoreDrillRunner();

        $test = $this->service->runDrill($runner);

        $this->assertSame(BackupRestoreTestStatus::Passed, $test->status);
        $this->assertNull($test->firm_id);
        $this->assertStringContainsString('Simulated', $test->notes);
    }

    public function test_run_drill_can_be_scoped_to_a_specific_firm(): void
    {
        $firm = Firm::factory()->create();
        $runner = new FakeBackupRestoreDrillRunner();

        $test = $this->service->runDrill($runner, $firm);

        $this->assertSame($firm->id, $test->firm_id);
    }

    public function test_meets_targets_true_when_actuals_are_within_the_24h_8h_defaults(): void
    {
        $runner = new FakeBackupRestoreDrillRunner(rpoActualSeconds: 3600, rtoActualSeconds: 7200);

        $test = $this->service->runDrill($runner);

        $this->assertTrue($test->meetsTargets());
    }

    public function test_meets_targets_false_when_rpo_exceeds_the_24_hour_target(): void
    {
        $runner = new FakeBackupRestoreDrillRunner(rpoActualSeconds: 90000, rtoActualSeconds: 3600);

        $test = $this->service->runDrill($runner);

        $this->assertFalse($test->meetsTargets());
    }

    public function test_meets_targets_false_when_rto_exceeds_the_8_hour_target(): void
    {
        $runner = new FakeBackupRestoreDrillRunner(rpoActualSeconds: 3600, rtoActualSeconds: 40000);

        $test = $this->service->runDrill($runner);

        $this->assertFalse($test->meetsTargets());
    }

    public function test_meets_targets_false_when_the_drill_failed_even_if_actuals_look_fine(): void
    {
        $runner = new FakeBackupRestoreDrillRunner(status: BackupRestoreTestStatus::Failed);

        $test = $this->service->runDrill($runner);

        $this->assertFalse($test->meetsTargets());
    }

    public function test_fully_verified_requires_all_six_required_components(): void
    {
        $runner = new class implements \App\Services\BackupRestore\BackupRestoreDrillRunner {
            public function run(?Firm $firm): BackupRestoreDrillResult
            {
                return new BackupRestoreDrillResult(
                    status: BackupRestoreTestStatus::Passed,
                    componentsVerified: ['database_records', 'documents'], // missing 4 of 6
                    rpoActualSeconds: 3600,
                    rtoActualSeconds: 7200,
                );
            }
        };

        $test = $this->service->runDrill($runner);

        $this->assertFalse($this->service->fullyVerified($test));
    }

    public function test_fully_verified_true_when_all_six_components_and_targets_are_met(): void
    {
        $runner = new FakeBackupRestoreDrillRunner();

        $test = $this->service->runDrill($runner);

        $this->assertTrue($this->service->fullyVerified($test));
    }

    public function test_latest_for_returns_the_most_recent_test_scoped_correctly(): void
    {
        $firm = Firm::factory()->create();
        $runner = new FakeBackupRestoreDrillRunner();

        $this->service->runDrill($runner, $firm);
        $second = $this->service->runDrill($runner, $firm);

        $latest = $this->service->latestFor($firm);

        $this->assertTrue($latest->is($second));
    }

    public function test_a_stricter_approved_target_can_override_the_defaults(): void
    {
        $runner = new FakeBackupRestoreDrillRunner(rpoActualSeconds: 3600, rtoActualSeconds: 7200);

        $test = $this->service->runDrill($runner, rpoTargetSeconds: 1800, rtoTargetSeconds: 3600);

        $this->assertFalse($test->meetsTargets());
    }
}
