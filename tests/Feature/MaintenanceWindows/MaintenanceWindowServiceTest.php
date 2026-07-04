<?php

namespace Tests\Feature\MaintenanceWindows;

use App\Enums\MaintenanceWindowStatus;
use App\Models\MaintenanceWindow;
use App\Services\MaintenanceWindowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaintenanceWindowServiceTest extends TestCase
{
    use RefreshDatabase;

    private MaintenanceWindowService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new MaintenanceWindowService();
    }

    public function test_schedule_creates_a_scheduled_window(): void
    {
        $window = $this->service->schedule(null, 'Database upgrade', now()->addDays(3), now()->addDays(3)->addHours(2));

        $this->assertSame(MaintenanceWindowStatus::Scheduled, $window->status);
        $this->assertNotEmpty($window->uuid);
    }

    public function test_start_requires_scheduled_status(): void
    {
        $window = MaintenanceWindow::factory()->create(['status' => MaintenanceWindowStatus::Completed]);

        $this->expectException(\RuntimeException::class);
        $this->service->start($window);
    }

    public function test_full_lifecycle_schedule_start_complete(): void
    {
        $window = $this->service->schedule(null, 'API upgrade', now()->addHour(), now()->addHours(2));

        $started = $this->service->start($window);
        $this->assertSame(MaintenanceWindowStatus::InProgress, $started->status);
        $this->assertNotNull($started->actual_starts_at);

        $completed = $this->service->complete($started);
        $this->assertSame(MaintenanceWindowStatus::Completed, $completed->status);
        $this->assertNotNull($completed->actual_ends_at);
    }

    public function test_cancel_records_a_reason(): void
    {
        $window = $this->service->schedule(null, 'Planned migration', now()->addDay(), now()->addDay()->addHour());

        $cancelled = $this->service->cancel($window, 'No longer needed');

        $this->assertSame(MaintenanceWindowStatus::Cancelled, $cancelled->status);
        $this->assertSame('No longer needed', $cancelled->cancellation_reason);
    }

    public function test_reschedule_creates_a_new_row_and_marks_the_original_rescheduled_never_mutating_the_original_dates(): void
    {
        $original = $this->service->schedule(null, 'Storage migration', now()->addDay(), now()->addDay()->addHours(3));
        $originalScheduledStart = $original->scheduled_starts_at;

        $newStart = now()->addWeek();
        $newEnd = now()->addWeek()->addHours(3);

        $rescheduled = $this->service->reschedule($original, $newStart, $newEnd);

        $this->assertSame(MaintenanceWindowStatus::Rescheduled, $original->fresh()->status);
        $this->assertTrue($original->fresh()->scheduled_starts_at->equalTo($originalScheduledStart));
        $this->assertSame(MaintenanceWindowStatus::Scheduled, $rescheduled->status);
        $this->assertSame($original->id, $rescheduled->rescheduled_from_id);
        $this->assertSame($newStart->copy()->startOfSecond()->timestamp, $rescheduled->scheduled_starts_at->timestamp);
    }

    public function test_mark_customer_notification_sent(): void
    {
        $window = $this->service->schedule(null, 'Upgrade', now()->addDay(), now()->addDay()->addHour());

        $this->assertFalse($window->customerNotificationSent());

        $notified = $this->service->markCustomerNotificationSent($window);

        $this->assertTrue($notified->customerNotificationSent());
    }
}
