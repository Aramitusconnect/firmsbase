<?php

namespace Tests\Feature\Deadlines;

use App\Enums\CalendarEventType;
use App\Models\Firm;
use App\Models\Task;
use App\Services\CalendarEventService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CalendarEventServiceTest extends TestCase
{
    use RefreshDatabase;

    private CalendarEventService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CalendarEventService();
    }

    public function test_create_for_sets_a_polymorphic_subject_reference(): void
    {
        $firm = Firm::factory()->create();
        $task = Task::factory()->create(['firm_id' => $firm->id]);

        $event = $this->service->createFor($firm, $task, CalendarEventType::Task, 'Task due', now()->addDay());

        $this->assertSame(Task::class, $event->subject_type);
        $this->assertSame($task->id, $event->subject_id);
        $this->assertSame(CalendarEventType::Task, $event->event_type);
        $this->assertTrue($event->subject()->is($task));
    }

    public function test_create_standalone_has_no_subject(): void
    {
        // Section 39A-3K follow-up: calendar_events is now FORCE RLS
        // enabled, and CalendarEventService deliberately does NOT
        // self-wrap in runWithFirmContext() (see the service's own
        // docblock) — its one production call site
        // (DeadlineService::create()) already establishes context for
        // the whole operation, but createStandalone() has no
        // production caller today, so a caller must establish context
        // itself, exactly as this test now does. A scoped
        // runWithFirmContext() around just this call (rather than
        // context for the whole test class) mirrors what a real future
        // caller would have to do.
        $firm = Firm::factory()->create();

        $event = $this->runWithFirmContext(
            $firm,
            fn () => $this->service->createStandalone($firm, 'Client meeting', now()->addDay()),
        );

        $this->assertNull($event->subject_type);
        $this->assertNull($event->subject_id);
        $this->assertSame(CalendarEventType::Standalone, $event->event_type);
    }
}
