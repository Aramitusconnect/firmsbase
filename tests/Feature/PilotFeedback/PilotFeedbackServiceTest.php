<?php

namespace Tests\Feature\PilotFeedback;

use App\Enums\PilotFeedbackCategory;
use App\Enums\PilotFeedbackPriority;
use App\Enums\PilotFeedbackSource;
use App\Enums\PilotFeedbackStatus;
use App\Models\Firm;
use App\Services\PilotFeedbackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PilotFeedbackServiceTest extends TestCase
{
    use RefreshDatabase;

    private PilotFeedbackService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PilotFeedbackService();
    }

    public function test_submit_creates_a_new_item(): void
    {
        $firm = Firm::factory()->create();

        $item = $this->service->submit(
            PilotFeedbackSource::Firm,
            PilotFeedbackCategory::Bug,
            'Upload fails on large PDFs',
            'Uploads over 20MB time out.',
            firm: $firm,
        );

        $this->assertSame(PilotFeedbackStatus::New, $item->status);
        $this->assertSame($firm->id, $item->firm_id);
    }

    public function test_internal_source_feedback_can_have_no_firm_client_or_matter(): void
    {
        $item = $this->service->submit(
            PilotFeedbackSource::Internal,
            PilotFeedbackCategory::FeatureRequest,
            'Add bulk export',
            'Ops would like a bulk CSV export.',
        );

        $this->assertNull($item->firm_id);
        $this->assertNull($item->client_id);
        $this->assertNull($item->matter_id);
    }

    public function test_triage_updates_status_and_priority(): void
    {
        $item = $this->service->submit(PilotFeedbackSource::Client, PilotFeedbackCategory::UsabilityIssue, 'Confusing button', 'Description');

        $triaged = $this->service->triage($item, PilotFeedbackPriority::High);

        $this->assertSame(PilotFeedbackStatus::Triaged, $triaged->status);
        $this->assertSame(PilotFeedbackPriority::High, $triaged->priority);
    }

    public function test_resolve_records_resolution_notes_and_timestamp(): void
    {
        $item = $this->service->submit(PilotFeedbackSource::Firm, PilotFeedbackCategory::Bug, 'Bug', 'Description');

        $resolved = $this->service->resolve($item, 'Fixed in the next release.');

        $this->assertSame(PilotFeedbackStatus::Resolved, $resolved->status);
        $this->assertNotNull($resolved->resolved_at);
        $this->assertTrue($resolved->isResolved());
    }

    public function test_schedule_follow_up_sets_the_flag_and_date(): void
    {
        $item = $this->service->submit(PilotFeedbackSource::Firm, PilotFeedbackCategory::FeatureRequest, 'Feature', 'Description');
        $followUpAt = now()->addWeek();

        $followedUp = $this->service->scheduleFollowUp($item, $followUpAt);

        $this->assertTrue($followedUp->follow_up_required);
        $this->assertSame($followUpAt->copy()->startOfSecond()->timestamp, $followedUp->follow_up_at->timestamp);
    }

    public function test_mark_wont_fix_and_duplicate(): void
    {
        $item1 = $this->service->submit(PilotFeedbackSource::Firm, PilotFeedbackCategory::Other, 'A', 'A');
        $item2 = $this->service->submit(PilotFeedbackSource::Firm, PilotFeedbackCategory::Other, 'B', 'B');

        $this->assertSame(PilotFeedbackStatus::WontFix, $this->service->markWontFix($item1, 'Out of scope')->status);
        $this->assertSame(PilotFeedbackStatus::Duplicate, $this->service->markDuplicate($item2)->status);
    }
}
