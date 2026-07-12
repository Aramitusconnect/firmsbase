<?php

namespace Tests\Feature\Invoicing;

use App\Enums\InvoiceLineType;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\TimeEntryStatus;
use App\Models\Client;
use App\Models\Firm;
use App\Models\TimeEntry;
use App\Services\EmployeeRateService;
use App\Services\InvoiceDraftingService;
use App\Services\TimeEntryApprovalService;
use App\Services\TimelineEventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceDraftingServiceTest extends TestCase
{
    use RefreshDatabase;

    private InvoiceDraftingService $service;
    private TimeEntryApprovalService $approvals;

    protected function setUp(): void
    {
        parent::setUp();
        $this->approvals = new TimeEntryApprovalService(new EmployeeRateService());
        $this->service = new InvoiceDraftingService($this->approvals, new TimelineEventRecorder());
    }

    public function test_draft_from_time_entries_creates_lines_and_marks_entries_invoiced(): void
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();
        $entry = TimeEntry::factory()->forFirm($firm)->create([
            'client_id' => $client->id,
            'status' => TimeEntryStatus::Approved,
            'is_billable' => true,
            'seconds' => 3600,
            'billing_rate_cents_snapshot' => 20000,
        ]);

        $invoice = $this->service->draftFromTimeEntries($firm, $client, [$entry]);

        $this->assertSame(InvoiceType::TimeAndExpense, $invoice->invoice_type);
        $this->assertSame(1, $invoice->lines()->count());
        $this->assertSame(InvoiceLineType::TimeEntry, $invoice->lines->first()->line_type);
        $this->assertSame(20000, $invoice->total_cents);

        // Section 39A-3L, Checkpoint 21 — time_entries is now FORCE RLS
        // protected, so $entry->fresh() would return null here with no
        // ambient tenant context active (this test never establishes
        // one of its own). Re-read under the firm's own context instead
        // — this is also a stronger proof than the original in-memory
        // re-fetch, since it confirms the status genuinely persisted to
        // the database rather than merely being visible to a query that
        // happens to share the same unscoped connection.
        $persisted = $this->runWithFirmContext($firm, fn () => TimeEntry::query()->find($entry->id));
        $this->assertSame(TimeEntryStatus::Invoiced, $persisted->status);
    }

    public function test_draft_from_time_entries_throws_when_an_entry_is_not_approved(): void
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();
        $entry = TimeEntry::factory()->forFirm($firm)->create(['client_id' => $client->id]); // draft

        $this->expectException(\RuntimeException::class);

        $this->service->draftFromTimeEntries($firm, $client, [$entry]);
    }

    public function test_draft_from_time_entries_throws_when_an_entry_is_non_billable(): void
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();
        $entry = TimeEntry::factory()->forFirm($firm)->approved()->nonBillable()->create(['client_id' => $client->id]);

        $this->expectException(\RuntimeException::class);

        $this->service->draftFromTimeEntries($firm, $client, [$entry]);
    }

    public function test_draft_from_time_entries_throws_when_entry_belongs_to_a_different_client(): void
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();
        $otherClient = Client::factory()->forFirm($firm)->create();
        $entry = TimeEntry::factory()->forFirm($firm)->approved()->create(['client_id' => $otherClient->id]);

        $this->expectException(\RuntimeException::class);

        $this->service->draftFromTimeEntries($firm, $client, [$entry]);
    }

    public function test_create_flat_fee_creates_a_single_flat_fee_line(): void
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();

        $invoice = $this->service->createFlatFee($firm, $client, 'Immigration filing — flat fee', 150000);

        $this->assertSame(InvoiceType::FlatFee, $invoice->invoice_type);
        $this->assertSame(1, $invoice->lines()->count());
        $this->assertSame(InvoiceLineType::FlatFee, $invoice->lines->first()->line_type);
        $this->assertSame(150000, $invoice->total_cents);
    }

    public function test_add_manual_charge_only_allowed_while_draft(): void
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();
        $invoice = $this->service->createFlatFee($firm, $client, 'Flat fee', 100000);

        $this->service->addManualCharge($invoice, 'Filing fee reimbursement', 6000);
        $this->assertSame(106000, $this->runWithFirmContext($firm, fn () => $invoice->fresh())->total_cents);

        $this->service->submitForReview($this->runWithFirmContext($firm, fn () => $invoice->fresh()));

        $this->expectException(\RuntimeException::class);
        $this->service->addManualCharge($this->runWithFirmContext($firm, fn () => $invoice->fresh()), 'Too late', 1000);
    }

    public function test_full_status_lifecycle_draft_to_sent(): void
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();
        $invoice = $this->service->createFlatFee($firm, $client, 'Flat fee', 100000);

        $submitted = $this->service->submitForReview($invoice);
        $this->assertSame(InvoiceStatus::PendingReview, $submitted->status);

        $approved = $this->service->approve($submitted);
        $this->assertSame(InvoiceStatus::Approved, $approved->status);
        $this->assertNotNull($approved->issued_at);

        $sent = $this->service->send($approved);
        $this->assertSame(InvoiceStatus::Sent, $sent->status);
        $this->assertNotNull($sent->sent_at);
    }

    public function test_void_is_blocked_once_paid(): void
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();
        $invoice = $this->service->createFlatFee($firm, $client, 'Flat fee', 100000);
        $invoice->update(['status' => InvoiceStatus::Paid]);

        $this->expectException(\RuntimeException::class);

        $this->service->void($invoice);
    }
}
