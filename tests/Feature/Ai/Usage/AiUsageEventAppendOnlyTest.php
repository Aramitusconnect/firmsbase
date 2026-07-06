<?php

namespace Tests\Feature\Ai\Usage;

use App\Models\AiUsageEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Ai\Concerns\SetsUpAiEntitledFirm;
use Tests\TestCase;

/**
 * Project rule 8: ai_usage_events is append-only.
 */
class AiUsageEventAppendOnlyTest extends TestCase
{
    use RefreshDatabase, SetsUpAiEntitledFirm;

    public function test_ai_usage_events_has_no_updated_at_column_behavior(): void
    {
        $this->assertNull(AiUsageEvent::UPDATED_AT);
    }

    public function test_updating_an_existing_ai_usage_event_throws(): void
    {
        $firm = $this->makeAiEntitledFirm();
        $event = \App\Models\AiUsageEvent::factory()->forFirm($firm)->create();

        $this->expectException(\LogicException::class);
        $event->update(['tokens_in' => 999]);
    }

    public function test_deleting_an_existing_ai_usage_event_throws(): void
    {
        $firm = $this->makeAiEntitledFirm();
        $event = \App\Models\AiUsageEvent::factory()->forFirm($firm)->create();

        $this->expectException(\LogicException::class);
        $event->delete();
    }
}
