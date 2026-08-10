<?php

namespace Tests\Feature\Leverage;

use App\Enums\DeadlineStatus;
use App\Enums\DocumentRequestItemStatus;
use App\Enums\FirmUserRole;
use App\Enums\FirmUserStatus;
use App\Enums\TaskStatus;
use App\Models\Deadline;
use App\Models\DocumentRequest;
use App\Models\DocumentRequestItem;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Matter;
use App\Models\Task;
use App\Services\Leverage\BottleneckDetectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BottleneckDetectionServiceTest extends TestCase
{
    use RefreshDatabase;

    private BottleneckDetectionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new BottleneckDetectionService;
    }

    public function test_staff_with_overdue_task_backlog_requires_the_bottleneck_floor(): void
    {
        $firm = Firm::factory()->create();
        $attorney = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->forFirm($firm)->create(['role' => FirmUserRole::Attorney]));
        $other = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->forFirm($firm)->create(['role' => FirmUserRole::Attorney]));

        $this->runWithFirmContext($firm, function () use ($firm, $attorney, $other) {
            for ($i = 0; $i < 5; $i++) {
                Task::factory()->create(['firm_id' => $firm->id, 'assigned_to' => $attorney->user_id, 'status' => TaskStatus::Overdue]);
            }
            Task::factory()->create(['firm_id' => $firm->id, 'assigned_to' => $other->user_id, 'status' => TaskStatus::Overdue]);
        });

        $result = $this->runWithFirmContext($firm, fn () => $this->service->staffWithOverdueTaskBacklog($firm));

        $this->assertCount(1, $result);
        $this->assertSame($attorney->user_id, $result[0]['user_id']);
        $this->assertSame(5, $result[0]['overdue_task_count']);
    }

    public function test_deadline_concentration_attributes_load_via_the_matters_assigned_attorney(): void
    {
        $firm = Firm::factory()->create();
        $attorney = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->forFirm($firm)->create(['role' => FirmUserRole::Attorney]));

        $this->runWithFirmContext($firm, function () use ($firm, $attorney) {
            $matter = Matter::factory()->forFirm($firm)->create(['assigned_attorney_id' => $attorney->user_id]);

            for ($i = 0; $i < 5; $i++) {
                Deadline::factory()->create(['firm_id' => $firm->id, 'matter_id' => $matter->id, 'status' => DeadlineStatus::Upcoming]);
            }
        });

        $result = $this->runWithFirmContext($firm, fn () => $this->service->deadlineConcentration($firm));

        $this->assertCount(1, $result);
        $this->assertSame($attorney->user_id, $result[0]['user_id']);
        $this->assertSame(5, $result[0]['deadline_count']);
    }

    public function test_deadline_concentration_excludes_matters_below_the_floor(): void
    {
        $firm = Firm::factory()->create();
        $attorney = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->forFirm($firm)->create(['role' => FirmUserRole::Attorney]));

        $this->runWithFirmContext($firm, function () use ($firm, $attorney) {
            $matter = Matter::factory()->forFirm($firm)->create(['assigned_attorney_id' => $attorney->user_id]);
            Deadline::factory()->create(['firm_id' => $firm->id, 'matter_id' => $matter->id, 'status' => DeadlineStatus::Upcoming]);
        });

        $result = $this->runWithFirmContext($firm, fn () => $this->service->deadlineConcentration($firm));

        $this->assertCount(0, $result);
    }

    public function test_stalled_document_request_items_reports_items_past_the_staleness_floor(): void
    {
        $firm = Firm::factory()->create();

        [$stalledItemId, $matterId] = $this->runWithFirmContext($firm, function () use ($firm) {
            $matter = Matter::factory()->forFirm($firm)->create();
            $request = DocumentRequest::factory()->create(['firm_id' => $firm->id, 'matter_id' => $matter->id]);

            $item = DocumentRequestItem::factory()->forRequest($request)->create(['status' => DocumentRequestItemStatus::Requested]);
            $item->forceFill(['updated_at' => now()->subDays(20)])->saveQuietly();

            DocumentRequestItem::factory()->forRequest($request)->create(['status' => DocumentRequestItemStatus::Requested]);

            $approvedButOld = DocumentRequestItem::factory()->forRequest($request)->create(['status' => DocumentRequestItemStatus::Approved]);
            $approvedButOld->forceFill(['updated_at' => now()->subDays(20)])->saveQuietly();

            return [$item->id, $matter->id];
        });

        $result = $this->runWithFirmContext($firm, fn () => $this->service->stalledDocumentRequestItems($firm));

        $this->assertCount(1, $result);
        $this->assertSame($stalledItemId, $result[0]['document_request_item_id']);
        $this->assertSame($matterId, $result[0]['matter_id']);
        $this->assertGreaterThanOrEqual(14, $result[0]['days_stalled']);
    }

    public function test_unassigned_task_count_excludes_completed_and_cancelled_tasks(): void
    {
        $firm = Firm::factory()->create();

        $this->runWithFirmContext($firm, function () use ($firm) {
            Task::factory()->create(['firm_id' => $firm->id, 'assigned_to' => null, 'status' => TaskStatus::Open]);
            Task::factory()->create(['firm_id' => $firm->id, 'assigned_to' => null, 'status' => TaskStatus::Open]);
            Task::factory()->create(['firm_id' => $firm->id, 'assigned_to' => null, 'status' => TaskStatus::Completed]);
            Task::factory()->create(['firm_id' => $firm->id, 'assigned_to' => null, 'status' => TaskStatus::Cancelled]);
        });

        $this->assertSame(2, $this->runWithFirmContext($firm, fn () => $this->service->unassignedTaskCount($firm)));
    }

    public function test_active_firm_users_excludes_non_active_membership_statuses(): void
    {
        $firm = Firm::factory()->create();

        $this->runWithFirmContext($firm, function () use ($firm) {
            FirmUser::factory()->forFirm($firm)->create(['status' => FirmUserStatus::Active]);
            FirmUser::factory()->forFirm($firm)->create(['status' => FirmUserStatus::Invited]);
            FirmUser::factory()->forFirm($firm)->create(['status' => FirmUserStatus::Suspended]);
        });

        $this->assertCount(1, $this->runWithFirmContext($firm, fn () => $this->service->activeFirmUsers($firm)));
    }

    public function test_cross_firm_signals_are_isolated(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $attorneyA = $this->runWithFirmContext($firmA, fn () => FirmUser::factory()->forFirm($firmA)->create(['role' => FirmUserRole::Attorney]));
        $attorneyB = $this->runWithFirmContext($firmB, fn () => FirmUser::factory()->forFirm($firmB)->create(['role' => FirmUserRole::Attorney]));

        $this->runWithFirmContext($firmA, function () use ($firmA, $attorneyA) {
            for ($i = 0; $i < 5; $i++) {
                Task::factory()->create(['firm_id' => $firmA->id, 'assigned_to' => $attorneyA->user_id, 'status' => TaskStatus::Overdue]);
            }
        });

        $this->runWithFirmContext($firmB, function () use ($firmB, $attorneyB) {
            for ($i = 0; $i < 5; $i++) {
                Task::factory()->create(['firm_id' => $firmB->id, 'assigned_to' => $attorneyB->user_id, 'status' => TaskStatus::Overdue]);
            }
        });

        $resultA = $this->runWithFirmContext($firmA, fn () => $this->service->staffWithOverdueTaskBacklog($firmA));

        $this->assertCount(1, $resultA);
        $this->assertSame($attorneyA->user_id, $resultA[0]['user_id']);
    }
}
