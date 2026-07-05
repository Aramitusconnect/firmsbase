<?php

namespace Tests\Feature\Signature;

use App\Models\SignatureEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Required correctness test: signature_events rows are fully immutable
 * from creation — the strictest reading of "signature evidence must be
 * immutable or append-only after completion" (here: from the moment
 * created, not just after the request completes).
 */
class SignatureEventsAreAppendOnlyTest extends TestCase
{
    use RefreshDatabase;

    public function test_updating_an_existing_signature_event_throws(): void
    {
        $event = SignatureEvent::factory()->create();

        $this->expectException(\LogicException::class);
        $event->update(['ip_address' => '10.0.0.1']);
    }

    public function test_deleting_an_existing_signature_event_throws(): void
    {
        $event = SignatureEvent::factory()->create();

        $this->expectException(\LogicException::class);
        $event->delete();
    }

    public function test_creating_new_signature_events_still_works(): void
    {
        $event = SignatureEvent::factory()->create();

        $this->assertDatabaseHas('signature_events', ['id' => $event->id]);
    }
}
