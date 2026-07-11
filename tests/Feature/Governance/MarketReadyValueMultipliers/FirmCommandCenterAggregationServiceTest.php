<?php

namespace Tests\Feature\Governance\MarketReadyValueMultipliers;

use App\Enums\DeadlineStatus;
use App\Enums\DocumentStatus;
use App\Enums\FirmLeadStatus;
use App\Enums\InvoiceStatus;
use App\Enums\MatterStatus;
use App\Enums\PaymentPlanInstallmentStatus;
use App\Enums\PaymentStatus;
use App\Enums\TaskStatus;
use App\Models\Client;
use App\Models\Deadline;
use App\Models\Document;
use App\Models\DocumentChaseEvent;
use App\Models\DocumentRequest;
use App\Models\DocumentRequestItem;
use App\Models\Firm;
use App\Models\FirmLead;
use App\Models\Invoice;
use App\Models\Matter;
use App\Models\Payment;
use App\Models\PaymentPlan;
use App\Models\PaymentPlanInstallment;
use App\Models\Task;
use App\Services\FirmCommandCenterAggregationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FirmCommandCenterAggregationServiceTest extends TestCase
{
    use RefreshDatabase;

    private FirmCommandCenterAggregationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new FirmCommandCenterAggregationService();
    }

    public function test_new_leads_and_matter_widgets_are_scoped_to_the_given_firm_only(): void
    {
        $firm = Firm::factory()->create();
        $otherFirm = Firm::factory()->create();

        FirmLead::factory()->count(2)->forFirm($firm)->status(FirmLeadStatus::New)->create();
        FirmLead::factory()->forFirm($firm)->status(FirmLeadStatus::Contacted)->create();
        FirmLead::factory()->count(5)->forFirm($otherFirm)->status(FirmLeadStatus::New)->create();

        Matter::factory()->forFirm($firm)->status(MatterStatus::WaitingOnClient)->create();
        Matter::factory()->forFirm($firm)->status(MatterStatus::ReadyForReview)->count(2)->create();
        Matter::factory()->forFirm($otherFirm)->status(MatterStatus::WaitingOnClient)->count(9)->create();

        $snapshot = $this->service->snapshot($firm);

        $this->assertSame(2, $snapshot->newLeadsCount);
        $this->assertSame(1, $snapshot->mattersWaitingOnClientCount);
        $this->assertSame(2, $snapshot->mattersReadyForReviewCount);
    }

    public function test_consultations_count_only_includes_upcoming_not_yet_held_consultations_for_the_firm(): void
    {
        $firm = Firm::factory()->create();
        $asOf = now();

        \App\Models\Consultation::factory()->forFirm($firm)->create(['scheduled_at' => $asOf->copy()->addDays(2), 'held_at' => null]);
        \App\Models\Consultation::factory()->forFirm($firm)->held()->create(['scheduled_at' => $asOf->copy()->subDay()]);
        \App\Models\Consultation::factory()->forFirm($firm)->create(['scheduled_at' => $asOf->copy()->subDays(3), 'held_at' => null]);

        $snapshot = $this->service->snapshot($firm, $asOf);

        $this->assertSame(1, $snapshot->consultationsCount);
    }

    public function test_documents_needing_approval_count_uses_pending_review_status(): void
    {
        $firm = Firm::factory()->create();

        Document::factory()->create(['firm_id' => $firm->id, 'status' => DocumentStatus::PendingReview]);
        Document::factory()->create(['firm_id' => $firm->id, 'status' => DocumentStatus::Approved]);

        $snapshot = $this->service->snapshot($firm);

        $this->assertSame(1, $snapshot->documentsNeedingApprovalCount);
    }

    public function test_deadlines_this_week_count_uses_due_at_within_the_next_seven_days(): void
    {
        $firm = Firm::factory()->create();
        $asOf = now()->startOfDay();

        Deadline::factory()->create(['firm_id' => $firm->id, 'due_at' => $asOf->copy()->addDays(3), 'status' => DeadlineStatus::Upcoming]);
        Deadline::factory()->create(['firm_id' => $firm->id, 'due_at' => $asOf->copy()->addDays(20), 'status' => DeadlineStatus::Upcoming]);
        Deadline::factory()->create(['firm_id' => $firm->id, 'due_at' => $asOf->copy()->addDays(2), 'status' => DeadlineStatus::Cancelled]);

        $snapshot = $this->service->snapshot($firm, $asOf);

        $this->assertSame(1, $snapshot->deadlinesThisWeekCount);
    }

    public function test_unpaid_invoices_count_uses_sent_and_partially_paid_statuses(): void
    {
        $firm = Firm::factory()->create();

        Invoice::factory()->forFirm($firm)->status(InvoiceStatus::Sent)->create();
        Invoice::factory()->forFirm($firm)->status(InvoiceStatus::PartiallyPaid)->create();
        Invoice::factory()->forFirm($firm)->status(InvoiceStatus::Paid)->create();
        Invoice::factory()->forFirm($firm)->status(InvoiceStatus::Draft)->create();

        $snapshot = $this->service->snapshot($firm);

        $this->assertSame(2, $snapshot->unpaidInvoicesCount);
    }

    public function test_installments_due_and_missed_counts_use_existing_installment_status_field(): void
    {
        $firm = Firm::factory()->create();
        $plan = PaymentPlan::factory()->forFirm($firm)->create();
        $otherFirmPlan = PaymentPlan::factory()->create();

        PaymentPlanInstallment::factory()->forPlan($plan)->status(PaymentPlanInstallmentStatus::Due)->create(['sequence' => 1]);
        PaymentPlanInstallment::factory()->forPlan($plan)->status(PaymentPlanInstallmentStatus::Missed)->create(['sequence' => 2]);
        PaymentPlanInstallment::factory()->forPlan($plan)->status(PaymentPlanInstallmentStatus::Missed)->create(['sequence' => 3]);
        PaymentPlanInstallment::factory()->forPlan($plan)->status(PaymentPlanInstallmentStatus::Paid)->create(['sequence' => 4]);
        PaymentPlanInstallment::factory()->forPlan($otherFirmPlan)->status(PaymentPlanInstallmentStatus::Due)->create(['sequence' => 1]);

        $snapshot = $this->service->snapshot($firm);

        $this->assertSame(1, $snapshot->installmentsDueCount);
        $this->assertSame(2, $snapshot->installmentsMissedCount);
    }

    public function test_failed_payments_count_uses_existing_payment_status_field(): void
    {
        $firm = Firm::factory()->create();

        Payment::factory()->forFirm($firm)->create(['status' => PaymentStatus::Failed]);
        Payment::factory()->forFirm($firm)->create(['status' => PaymentStatus::Succeeded]);

        $snapshot = $this->service->snapshot($firm);

        $this->assertSame(1, $snapshot->failedPaymentsCount);
    }

    public function test_inactive_clients_count_honors_the_inactive_client_days_argument(): void
    {
        $firm = Firm::factory()->create();
        $asOf = now();

        $stale = Client::factory()->forFirm($firm)->create();
        $stale->forceFill(['updated_at' => $asOf->copy()->subDays(45)])->save();

        $recent = Client::factory()->forFirm($firm)->create();
        $recent->forceFill(['updated_at' => $asOf->copy()->subDays(10)])->save();

        $snapshotDefault = $this->service->snapshot($firm, $asOf);
        $this->assertSame(1, $snapshotDefault->inactiveClientsCount);

        $snapshotShorterWindow = $this->service->snapshot($firm, $asOf, inactiveClientDays: 5);
        $this->assertSame(2, $snapshotShorterWindow->inactiveClientsCount);
    }

    public function test_overdue_and_blocked_tasks_counts_use_existing_task_status_field(): void
    {
        $firm = Firm::factory()->create();

        Task::factory()->create(['firm_id' => $firm->id, 'status' => TaskStatus::Overdue]);
        Task::factory()->create(['firm_id' => $firm->id, 'status' => TaskStatus::Blocked]);
        Task::factory()->create(['firm_id' => $firm->id, 'status' => TaskStatus::Open]);

        $snapshot = $this->service->snapshot($firm);

        $this->assertSame(1, $snapshot->overdueTasksCount);
        $this->assertSame(1, $snapshot->blockedTasksCount);
    }

    public function test_forms_ready_for_review_count_uses_existing_form_draft_status_field(): void
    {
        $firm = Firm::factory()->create();
        $matter = Matter::factory()->forFirm($firm)->create();

        \App\Models\FormDraft::factory()->forFirmAndMatter($firm, $matter)->create(['status' => \App\Enums\FormDraftStatus::ReadyForReview->value]);
        \App\Models\FormDraft::factory()->forFirmAndMatter($firm, $matter)->create(['status' => \App\Enums\FormDraftStatus::Draft->value]);

        $snapshot = $this->service->snapshot($firm);

        $this->assertSame(1, $snapshot->formsReadyForReviewCount);
    }

    public function test_document_chase_escalations_count_uses_existing_escalated_event_type(): void
    {
        $firm = Firm::factory()->create();
        // Section 39A-3L, Checkpoint 10: document_requests is now FORCE
        // RLS. A bare DocumentRequestItem::factory()->create() derives
        // its parent DocumentRequest via its own unrelated
        // firm/client pair (via the context-hold factory, which
        // deliberately leaves that OTHER firm's context active
        // afterward, not $firm's). A raw
        // $item->documentRequest()->update(['firm_id' => $firm->id])
        // then genuinely violates the FORCE RLS policy's WITH CHECK
        // clause (the new firm_id would not match whatever context is
        // active), rather than merely "having no wrap" — so the correct
        // fix is to create the DocumentRequest already owned by $firm
        // from the start, not to reassign its ownership afterward.
        $client = Client::factory()->forFirm($firm)->create();
        $request = DocumentRequest::factory()->forClient($client)->create();
        $item = DocumentRequestItem::factory()->forRequest($request)->create();

        DocumentChaseEvent::factory()->forItem($item)->create(['firm_id' => $firm->id, 'event_type' => 'escalated']);
        DocumentChaseEvent::factory()->forItem($item)->create(['firm_id' => $firm->id, 'event_type' => 'reminder_queued']);

        $snapshot = $this->service->snapshot($firm);

        $this->assertSame(1, $snapshot->documentChaseEscalationsCount);
    }

    public function test_generated_at_is_deterministic_with_a_provided_as_of(): void
    {
        $firm = Firm::factory()->create();
        $asOf = \Carbon\CarbonImmutable::parse('2026-03-01 12:00:00');

        $snapshot = $this->service->snapshot($firm, $asOf);

        $this->assertTrue($asOf->equalTo($snapshot->generatedAt));
    }

    public function test_service_writes_nothing_and_no_command_center_table_exists(): void
    {
        $firm = Firm::factory()->create();

        $countsBefore = [
            Firm::count(), FirmLead::count(), Matter::count(), Document::count(),
            Deadline::count(), Invoice::count(), PaymentPlanInstallment::count(),
            Payment::count(), Client::count(), Task::count(),
        ];

        $this->service->snapshot($firm);

        $countsAfter = [
            Firm::count(), FirmLead::count(), Matter::count(), Document::count(),
            Deadline::count(), Invoice::count(), PaymentPlanInstallment::count(),
            Payment::count(), Client::count(), Task::count(),
        ];

        $this->assertSame($countsBefore, $countsAfter);
        $this->assertEmpty(glob(database_path('schema/*.sql')) ?: []);
    }
}
