<?php

namespace Tests\Feature\TimeTracking;

use App\Enums\TimeEntryStatus;
use App\Models\Firm;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\EmployeeRateService;
use App\Services\TimeEntryApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TimeEntryApprovalServiceTest extends TestCase
{
    use RefreshDatabase;

    private TimeEntryApprovalService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TimeEntryApprovalService(new EmployeeRateService());
    }

    public function test_create_manual_entry_starts_as_draft(): void
    {
        $firm = Firm::factory()->create();
        $user = User::factory()->create();

        $entry = $this->service->createManualEntry($firm, $user, 1800, now());

        $this->assertSame(TimeEntryStatus::Draft, $entry->status);
        $this->assertSame(1800, $entry->seconds);
    }

    public function test_create_manual_entry_throws_on_negative_seconds(): void
    {
        $firm = Firm::factory()->create();
        $user = User::factory()->create();

        $this->expectException(\InvalidArgumentException::class);

        $this->service->createManualEntry($firm, $user, -10, now());
    }

    public function test_submit_transitions_draft_to_submitted(): void
    {
        $entry = TimeEntry::factory()->create();

        $submitted = $this->service->submit($entry);

        $this->assertSame(TimeEntryStatus::Submitted, $submitted->status);
    }

    public function test_approve_snapshots_the_employees_current_billing_rate(): void
    {
        $firm = Firm::factory()->create();
        $user = User::factory()->create();
        $approver = User::factory()->create();
        (new EmployeeRateService())->setRate($firm, $user, billingRateCents: 30000, costRateCents: 15000);

        $entry = TimeEntry::factory()->forFirm($firm)->forUser($user)->create(['status' => TimeEntryStatus::Draft]);
        // Section 39A-3L, Checkpoint 21 — time_entries is now FORCE RLS
        // protected, so $entry->fresh() would return null here with no
        // ambient tenant context active (the exact fail-closed behavior
        // this checkpoint activates). submit() already returns a fresh,
        // persisted TimeEntry (built inside its own internal context
        // wrap) — use that directly instead of re-fetching with no
        // context.
        $submitted = $this->service->submit($entry);

        $approved = $this->service->approve($submitted, $approver);

        $this->assertSame(TimeEntryStatus::Approved, $approved->status);
        $this->assertSame(30000, $approved->billing_rate_cents_snapshot);
        $this->assertSame($approver->id, $approved->approved_by);
    }

    public function test_approve_throws_unless_submitted(): void
    {
        $entry = TimeEntry::factory()->create(); // draft
        $approver = User::factory()->create();

        $this->expectException(\RuntimeException::class);

        $this->service->approve($entry, $approver);
    }

    public function test_reject_records_reason_and_allows_resubmission(): void
    {
        $entry = TimeEntry::factory()->create();
        $approver = User::factory()->create();
        // Section 39A-3L, Checkpoint 21 — same reasoning as
        // test_approve_snapshots_the_employees_current_billing_rate()
        // above: use submit()'s own returned (already-fresh) TimeEntry
        // rather than re-fetching via $entry->fresh() with no ambient
        // context active, which would return null now that time_entries
        // is FORCE RLS protected.
        $submitted = $this->service->submit($entry);

        $rejected = $this->service->reject($submitted, $approver, 'Missing matter reference');

        $this->assertSame(TimeEntryStatus::Rejected, $rejected->status);
        $this->assertSame('Missing matter reference', $rejected->rejected_reason);

        $resubmitted = $this->service->submit($rejected);
        $this->assertSame(TimeEntryStatus::Submitted, $resubmitted->status);
        $this->assertNull($resubmitted->rejected_reason);
    }

    public function test_mark_invoiced_requires_approved_and_billable(): void
    {
        $entry = TimeEntry::factory()->approved()->create();

        $invoiced = $this->service->markInvoiced($entry);

        $this->assertSame(TimeEntryStatus::Invoiced, $invoiced->status);
    }

    public function test_mark_invoiced_throws_when_not_eligible(): void
    {
        $entry = TimeEntry::factory()->create(); // draft, not eligible

        $this->expectException(\RuntimeException::class);

        $this->service->markInvoiced($entry);
    }
}
