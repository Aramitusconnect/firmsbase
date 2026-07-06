<?php

namespace Tests\Feature\Ai\Approval;

use App\Models\AiApprovalEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Ai\Concerns\SetsUpAiEntitledFirm;
use Tests\TestCase;

/**
 * Project rule 9: ai_approval_events is append-only.
 */
class AiApprovalEventAppendOnlyTest extends TestCase
{
    use RefreshDatabase, SetsUpAiEntitledFirm;

    public function test_ai_approval_events_has_no_updated_at_column_behavior(): void
    {
        $this->assertNull(AiApprovalEvent::UPDATED_AT);
    }

    public function test_updating_an_existing_ai_approval_event_throws(): void
    {
        $firm = $this->makeAiEntitledFirm();
        $event = \App\Models\AiApprovalEvent::factory()->create(['firm_id' => $firm->id]);

        $this->expectException(\LogicException::class);
        $event->update(['reason' => 'changed']);
    }

    public function test_deleting_an_existing_ai_approval_event_throws(): void
    {
        $firm = $this->makeAiEntitledFirm();
        $event = \App\Models\AiApprovalEvent::factory()->create(['firm_id' => $firm->id]);

        $this->expectException(\LogicException::class);
        $event->delete();
    }
}
