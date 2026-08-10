<?php

namespace Tests\Feature\Leverage;

use App\Enums\DeadlineStatus;
use App\Enums\FirmUserRole;
use App\Enums\FirmUserStatus;
use App\Enums\MatterStatus;
use App\Enums\TaskStatus;
use App\Models\Deadline;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Matter;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Services\Leverage\StaffUtilizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffUtilizationServiceTest extends TestCase
{
    use RefreshDatabase;

    private StaffUtilizationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new StaffUtilizationService;
    }

    public function test_workload_for_reports_billable_and_non_billable_hours(): void
    {
        $firm = Firm::factory()->create();
        $paralegal = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->forFirm($firm)->create(['role' => FirmUserRole::Paralegal]));
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());

        $this->runWithFirmContext($firm, function () use ($firm, $paralegal, $matter) {
            TimeEntry::factory()->create([
                'firm_id' => $firm->id,
                'matter_id' => $matter->id,
                'user_id' => $paralegal->user_id,
                'worked_on' => now()->subDays(2)->toDateString(),
                'seconds' => 3600 * 3,
                'is_billable' => true,
            ]);
            TimeEntry::factory()->create([
                'firm_id' => $firm->id,
                'matter_id' => $matter->id,
                'user_id' => $paralegal->user_id,
                'worked_on' => now()->subDays(1)->toDateString(),
                'seconds' => 3600,
                'is_billable' => false,
            ]);
        });

        $workload = $this->runWithFirmContext($firm, fn () => $this->service->workloadFor($firm, $paralegal));

        $this->assertSame(3.0, $workload['billable_hours']);
        $this->assertSame(1.0, $workload['non_billable_hours']);
        $this->assertSame(4.0, $workload['recorded_hours']);
    }

    public function test_workload_for_excludes_time_entries_before_the_since_window(): void
    {
        $firm = Firm::factory()->create();
        $attorney = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->forFirm($firm)->create(['role' => FirmUserRole::Attorney]));
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());

        $this->runWithFirmContext($firm, function () use ($firm, $attorney, $matter) {
            TimeEntry::factory()->create([
                'firm_id' => $firm->id,
                'matter_id' => $matter->id,
                'user_id' => $attorney->user_id,
                'worked_on' => now()->subDays(60)->toDateString(),
                'seconds' => 3600 * 5,
                'is_billable' => true,
            ]);
        });

        $workload = $this->runWithFirmContext($firm, fn () => $this->service->workloadFor($firm, $attorney));

        $this->assertSame(0.0, $workload['recorded_hours']);
    }

    public function test_workload_for_counts_active_matters_open_and_overdue_tasks(): void
    {
        $firm = Firm::factory()->create();
        $attorney = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->forFirm($firm)->create(['role' => FirmUserRole::Attorney]));

        $this->runWithFirmContext($firm, function () use ($firm, $attorney) {
            Matter::factory()->forFirm($firm)->create(['assigned_attorney_id' => $attorney->user_id, 'status' => MatterStatus::Open]);
            Matter::factory()->forFirm($firm)->create(['assigned_attorney_id' => $attorney->user_id, 'status' => MatterStatus::Closed]);

            Task::factory()->create(['firm_id' => $firm->id, 'assigned_to' => $attorney->user_id, 'status' => TaskStatus::Open]);
            Task::factory()->create(['firm_id' => $firm->id, 'assigned_to' => $attorney->user_id, 'status' => TaskStatus::Overdue]);
            Task::factory()->create(['firm_id' => $firm->id, 'assigned_to' => $attorney->user_id, 'status' => TaskStatus::Completed]);
        });

        $workload = $this->runWithFirmContext($firm, fn () => $this->service->workloadFor($firm, $attorney));

        $this->assertSame(1, $workload['active_matter_count']);
        $this->assertSame(2, $workload['open_task_count']);
        $this->assertSame(1, $workload['overdue_task_count']);
    }

    public function test_workload_for_reports_deadline_load_via_the_assigned_attorneys_matters(): void
    {
        $firm = Firm::factory()->create();
        $attorney = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->forFirm($firm)->create(['role' => FirmUserRole::Attorney]));

        $this->runWithFirmContext($firm, function () use ($firm, $attorney) {
            $matter = Matter::factory()->forFirm($firm)->create(['assigned_attorney_id' => $attorney->user_id]);
            $otherMatter = Matter::factory()->forFirm($firm)->create();

            Deadline::factory()->create(['firm_id' => $firm->id, 'matter_id' => $matter->id, 'status' => DeadlineStatus::Upcoming]);
            Deadline::factory()->create(['firm_id' => $firm->id, 'matter_id' => $matter->id, 'status' => DeadlineStatus::Due]);
            Deadline::factory()->create(['firm_id' => $firm->id, 'matter_id' => $matter->id, 'status' => DeadlineStatus::Missed]);
            Deadline::factory()->create(['firm_id' => $firm->id, 'matter_id' => $otherMatter->id, 'status' => DeadlineStatus::Upcoming]);
        });

        $workload = $this->runWithFirmContext($firm, fn () => $this->service->workloadFor($firm, $attorney));

        $this->assertSame(2, $workload['deadline_load']);
    }

    public function test_workload_for_firm_maps_over_every_active_firm_user(): void
    {
        $firm = Firm::factory()->create();

        $this->runWithFirmContext($firm, function () use ($firm) {
            FirmUser::factory()->forFirm($firm)->create(['role' => FirmUserRole::Attorney, 'status' => FirmUserStatus::Active]);
            FirmUser::factory()->forFirm($firm)->create(['role' => FirmUserRole::Paralegal, 'status' => FirmUserStatus::Active]);
            FirmUser::factory()->forFirm($firm)->create(['role' => FirmUserRole::Attorney, 'status' => FirmUserStatus::Suspended]);
        });

        $workloads = $this->runWithFirmContext($firm, fn () => $this->service->workloadForFirm($firm));

        $this->assertCount(2, $workloads);
    }
}
