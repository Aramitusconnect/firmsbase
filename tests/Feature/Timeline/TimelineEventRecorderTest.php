<?php

namespace Tests\Feature\Timeline;

use App\Models\Firm;
use App\Models\Matter;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Services\TimelineEventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Section 39A-3L Phase B6 (Checkpoint 33) — timeline_events now has
 * permanent FORCE ROW LEVEL SECURITY active, and TimelineEventRecorder::
 * record() is deliberately NOT self-wrapping (see the design dossier and
 * this table's own migration docblock for why: several already-correct
 * callers already wrap their own containing method, and a self-wrap
 * inside an already-active context would reproduce the "decoy wrap" bug
 * class this mission has already found and fixed twice). Every one of
 * these five tests therefore establishes its own ambient
 * runWithFirmContext() around its direct record() call — matching the
 * design dossier's own explicit call-out that this is the first
 * precedent test file in this table's own history where every single
 * existing test needed updating.
 */
class TimelineEventRecorderTest extends TestCase
{
    use RefreshDatabase;

    private TimelineEventRecorder $recorder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->recorder = new TimelineEventRecorder();
    }

    public function test_record_writes_event_with_subject_and_actor(): void
    {
        $firm = Firm::factory()->create();
        $matter = Matter::factory()->forFirm($firm)->create();
        $actor = User::factory()->create();

        $event = $this->runWithFirmContext($firm, fn () => $this->recorder->record($firm, 'matter_opened', $matter, $actor, ['note' => 'test']));

        $this->runWithFirmContext($firm, function () use ($event, $firm, $matter, $actor) {
            $this->assertDatabaseHas('timeline_events', [
                'id' => $event->id,
                'firm_id' => $firm->id,
                'subject_type' => Matter::class,
                'subject_id' => $matter->id,
                'event_type' => 'matter_opened',
                'actor_type' => User::class,
                'actor_id' => $actor->id,
            ]);
        });
    }

    public function test_record_allows_no_subject_and_no_actor(): void
    {
        $firm = Firm::factory()->create();

        $event = $this->runWithFirmContext($firm, fn () => $this->recorder->record($firm, 'system_event'));

        $this->assertNull($event->subject_type);
        $this->assertNull($event->actor_type);
    }

    public function test_no_updated_at_column_exists(): void
    {
        $firm = Firm::factory()->create();
        $event = $this->runWithFirmContext($firm, fn () => $this->recorder->record($firm, 'test_event'));

        $this->assertArrayNotHasKey('updated_at', $event->getAttributes());
    }

    public function test_event_type_accepts_arbitrary_future_phase_strings(): void
    {
        $firm = Firm::factory()->create();

        // Proves event_type is a plain string, not a closed enum —
        // an event type no Phase 2 code defines still saves fine.
        $event = $this->runWithFirmContext($firm, fn () => $this->recorder->record($firm, 'invoice_created_by_a_future_phase'));

        $reFetched = $this->runWithFirmContext($firm, fn () => $event->fresh());
        $this->assertSame('invoice_created_by_a_future_phase', $reFetched->event_type);
    }

    public function test_recorded_event_receives_a_public_uuidv7(): void
    {
        $firm = Firm::factory()->create();

        // TimelineEvent carries HasPublicUuid because individual events
        // are expected to be exposed later in matter activity feeds,
        // portal activity, notifications, APIs, and admin review
        // screens — the internal bigint id must never be the public
        // identifier for those surfaces.
        $event = $this->runWithFirmContext($firm, fn () => $this->recorder->record($firm, 'matter_opened'));

        $this->assertNotNull($event->uuid);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $event->uuid
        );
    }
}
