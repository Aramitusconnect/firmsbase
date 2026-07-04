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
        $firm = Firm::factory()->create();

        $event = $this->service->createStandalone($firm, 'Client meeting', now()->addDay());

        $this->assertNull($event->subject_type);
        $this->assertNull($event->subject_id);
        $this->assertSame(CalendarEventType::Standalone, $event->event_type);
    }
}
