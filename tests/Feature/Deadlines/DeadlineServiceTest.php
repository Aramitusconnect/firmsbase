<?php

namespace Tests\Feature\Deadlines;

use App\Enums\CalendarEventType;
use App\Enums\DeadlineStatus;
use App\Models\Firm;
use App\Services\CalendarEventService;
use App\Services\DeadlineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeadlineServiceTest extends TestCase
{
    use RefreshDatabase;

    private DeadlineService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DeadlineService(new CalendarEventService());
    }

    public function test_create_also_creates_a_linked_calendar_event(): void
    {
        $firm = Firm::factory()->create();

        $deadline = $this->service->create(
            $firm,
            'Response deadline',
            'response_deadline',
            now()->addDays(30),
            reminderOffsetsDays: [7, 3, 1],
        );

        $calendarEvent = \App\Models\CalendarEvent::query()->where('subject_id', $deadline->id)->first();

        $this->assertNotNull($calendarEvent);
        $this->assertSame(\App\Models\Deadline::class, $calendarEvent->subject_type);
        $this->assertSame(CalendarEventType::Deadline, $calendarEvent->event_type);
    }

    public function test_refresh_missed_status_derives_missed_from_due_at(): void
    {
        $firm = Firm::factory()->create();
        $deadline = $this->service->create($firm, 'Filing deadline', 'filing_deadline', now()->subDay());

        $refreshed = $this->service->refreshMissedStatus($deadline);

        $this->assertSame(DeadlineStatus::Missed, $refreshed->status);
    }

    public function test_refresh_missed_status_never_overrides_a_completed_deadline(): void
    {
        $firm = Firm::factory()->create();
        $deadline = $this->service->create($firm, 'Filing deadline', 'filing_deadline', now()->subDay());
        $completed = $this->service->complete($deadline);

        $refreshed = $this->service->refreshMissedStatus($completed);

        $this->assertSame(DeadlineStatus::Completed, $refreshed->status);
    }

    public function test_reminder_dates_are_computed_from_offsets(): void
    {
        $firm = Firm::factory()->create();
        $dueAt = now()->addDays(10)->startOfDay();

        $deadline = $this->service->create($firm, 'Filing deadline', 'filing_deadline', $dueAt, reminderOffsetsDays: [7, 3, 1]);

        $dates = $this->service->reminderDates($deadline);

        $this->assertCount(3, $dates);
        $this->assertTrue($dates[0]->equalTo($dueAt->copy()->subDays(7)));
        $this->assertTrue($dates[2]->equalTo($dueAt->copy()->subDays(1)));
    }

    public function test_no_reminder_policy_id_column_exists_reminder_offsets_are_stored_directly(): void
    {
        $firm = Firm::factory()->create();
        $deadline = $this->service->create($firm, 'Filing deadline', 'filing_deadline', now()->addDays(5), reminderOffsetsDays: [7, 3, 1]);

        $this->assertArrayNotHasKey('reminder_policy_id', $deadline->getAttributes());
        $this->assertSame([7, 3, 1], $deadline->reminder_offsets_days);
    }
}
