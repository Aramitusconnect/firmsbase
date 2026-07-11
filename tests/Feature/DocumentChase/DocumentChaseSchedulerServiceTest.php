<?php

namespace Tests\Feature\DocumentChase;

use App\Models\Client;
use App\Models\DocumentChaseRule;
use App\Models\DocumentRequest;
use App\Models\DocumentRequestItem;
use App\Models\Firm;
use App\Services\DocumentChaseSchedulerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentChaseSchedulerServiceTest extends TestCase
{
    use RefreshDatabase;

    private DocumentChaseSchedulerService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DocumentChaseSchedulerService();
    }

    public function test_applicable_rule_prefers_a_scope_specific_rule_over_the_firm_wide_rule(): void
    {
        // Section 39A-3K follow-up: document_chase_rules is now FORCE
        // RLS enabled. DocumentChaseSchedulerService::applicableRule()
        // was deliberately left un-wrapped (see the migration's own
        // docblock — this read path was traced and confirmed
        // unreachable in production today), so a caller must establish
        // context itself, exactly as this test now does with a scoped
        // runWithFirmContext() around just the read.
        $firm = Firm::factory()->create();
        $wide = DocumentChaseRule::factory()->forFirm($firm)->create(['applies_to' => null]);
        $specific = DocumentChaseRule::factory()->forFirm($firm)->create(['applies_to' => 'immigration']);
        $item = DocumentRequestItem::factory()->create();

        $resolved = $this->runWithFirmContext($firm, fn () => $this->service->applicableRule($firm, $item, 'immigration'));

        $this->assertTrue($resolved->is($specific));
    }

    public function test_applicable_rule_falls_back_to_the_firm_wide_rule(): void
    {
        // Same reasoning as the test above.
        $firm = Firm::factory()->create();
        $wide = DocumentChaseRule::factory()->forFirm($firm)->create(['applies_to' => null]);
        $item = DocumentRequestItem::factory()->create();

        $resolved = $this->runWithFirmContext($firm, fn () => $this->service->applicableRule($firm, $item, 'family_law'));

        $this->assertTrue($resolved->is($wide));
    }

    public function test_is_reminder_due_respects_max_reminders_already_sent(): void
    {
        // Section 39A-3L, Checkpoint 17: document_chase_events is now
        // FORCE RLS. remindersSentCount() (called inside
        // isReminderDue()) is deliberately left unwrapped in
        // production, same established precedent as applicableRule()
        // above, so this test wraps it explicitly. DocumentChaseEvent
        // Factory::forItem() now takes $firm directly (see the
        // factory's own docblock) rather than deriving it via a
        // relation load, so no context needs to be active for that
        // call specifically — but the item must still genuinely belong
        // to the given $firm for remindersSentCount()'s later read to
        // see the row.
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();
        $request = DocumentRequest::factory()->create(['firm_id' => $firm->id, 'client_id' => $client->id]);
        $item = DocumentRequestItem::factory()->create(['document_request_id' => $request->id]);
        $rule = DocumentChaseRule::factory()->forFirm($firm)->create(['reminder_offsets_days' => [7, 3, 1], 'max_reminders' => 1]);

        \App\Models\DocumentChaseEvent::factory()->forItem($item, $firm, $rule)->create(['event_type' => 'reminder_queued']);

        $isDue = $this->runWithFirmContext($firm, fn () => $this->service->isReminderDue($rule, $item, 3));

        $this->assertFalse($isDue);
    }

    public function test_is_reminder_due_true_when_days_since_requested_matches_an_offset(): void
    {
        $rule = DocumentChaseRule::factory()->create(['reminder_offsets_days' => [7, 3, 1], 'max_reminders' => 3]);
        $item = DocumentRequestItem::factory()->create();

        $this->assertTrue($this->service->isReminderDue($rule, $item, 7));
        $this->assertFalse($this->service->isReminderDue($rule, $item, 5));
    }

    public function test_is_escalation_due_when_days_meet_or_exceed_the_threshold(): void
    {
        $rule = DocumentChaseRule::factory()->create(['escalate_after_days' => 14]);

        $this->assertTrue($this->service->isEscalationDue($rule, 14));
        $this->assertTrue($this->service->isEscalationDue($rule, 20));
        $this->assertFalse($this->service->isEscalationDue($rule, 10));
    }

    public function test_is_escalation_due_is_false_when_no_escalation_configured(): void
    {
        $rule = DocumentChaseRule::factory()->create(['escalate_after_days' => null]);

        $this->assertFalse($this->service->isEscalationDue($rule, 999));
    }
}
