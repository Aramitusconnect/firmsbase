<?php

namespace Tests\Feature\PilotWorkflow;

use App\Enums\ConflictCheckRunStatus;
use App\Enums\DocumentScanStatus;
use App\Enums\DocumentStatus;
use App\Enums\FirmUserStatus;
use App\Enums\IntakeSubmissionStatus;
use App\Enums\InvoiceStatus;
use App\Enums\MatterStatus;
use App\Enums\PaymentPlanStatus;
use App\Enums\PaymentStatus;
use App\Enums\ReadinessComponentStatus;
use App\Models\Firm;
use App\Models\FirmLead;
use App\Models\FirmSettings;
use App\Models\IntakeTemplate;
use App\Models\MatterType;
use App\Models\PracticeArea;
use App\Models\ReadinessScorecardComponent;
use App\Services\ConflictCheckService;
use App\Services\DeadlineService;
use App\Services\DocumentRequestService;
use App\Services\DocumentSecurityService;
use App\Services\DocumentUploadPolicyService;
use App\Services\InvoiceDraftingService;
use App\Services\LeadConversionService;
use App\Services\ManualPaymentService;
use App\Services\MatterOpeningService;
use App\Services\MatterReadinessService;
use App\Services\PaymentApplicationService;
use App\Services\PaymentClassificationService;
use App\Services\PaymentPlanService;
use App\Services\ProductionPilotWorkflowService;
use App\Services\ReadinessScorecardRegistry;
use App\Services\TimelineEventRecorder;
use App\Services\VirusScan\FakeVirusScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ProductionPilotWorkflowServiceTest — the required acceptance-
 * criterion test: "Pilot workflow test passes." Exercises all 12 steps
 * the master plan's Phase 5 Scope names verbatim, end to end, entirely
 * through existing Phase 1-4 services (no business logic is
 * reimplemented — this test proves ORCHESTRATION works, not that any
 * individual service's own rules are correct; those are already
 * exhaustively covered by each phase's own test suite).
 */
class ProductionPilotWorkflowServiceTest extends TestCase
{
    use RefreshDatabase;

    private ProductionPilotWorkflowService $pilot;
    private InvoiceDraftingService $invoices;

    protected function setUp(): void
    {
        parent::setUp();

        $timeline = new TimelineEventRecorder();

        $paymentPlanService = new PaymentPlanService($timeline);
        $this->invoices = new InvoiceDraftingService(new \App\Services\TimeEntryApprovalService(new \App\Services\EmployeeRateService()), $timeline);

        $this->pilot = new ProductionPilotWorkflowService(
            new LeadConversionService($timeline),
            new MatterOpeningService(new ConflictCheckService($timeline), $timeline),
            new DocumentRequestService(),
            new DocumentSecurityService(new DocumentUploadPolicyService()),
            new FakeVirusScanner(),
            new DeadlineService(new \App\Services\CalendarEventService()),
            $this->invoices,
            $paymentPlanService,
            new ManualPaymentService(
                new PaymentClassificationService(),
                new PaymentApplicationService($paymentPlanService, $timeline),
                $timeline,
                app(\App\Services\OperatingJournalRecorderService::class),
            ),
            new MatterReadinessService(new ReadinessScorecardRegistry()),
        );
    }

    public function test_the_full_pilot_workflow_runs_end_to_end_through_every_existing_phase_1_to_4_service(): void
    {
        $firm = Firm::factory()->create();
        FirmSettings::factory()->forFirm($firm)->create();
        $practiceArea = PracticeArea::factory()->create();
        $matterType = MatterType::factory()->forPracticeArea($practiceArea)->create();
        $intakeTemplate = IntakeTemplate::factory()->create();

        // Step 1-2: lead conversion + client creation
        $lead = FirmLead::factory()->forFirm($firm)->create();
        $client = $this->pilot->convertLeadToClient($lead, [
            'display_name' => 'Maria Gonzalez',
            'email' => 'maria.gonzalez@example.test',
            'phone' => '555-0100',
            'preferred_language' => 'en',
            'preferred_timezone' => 'America/New_York',
        ]);

        $this->assertNotNull($client->id);
        // firm_leads has permanent FORCE ROW LEVEL SECURITY (Section
        // 39A-3J) — LeadConversionService::convert() (reached via
        // ProductionPilotWorkflowService::convertLeadToClient())
        // clears its own tenant context in a finally block before
        // returning, so this post-call read needs explicit tenant
        // context re-established.
        $this->assertTrue($this->runWithFirmContext($firm, fn () => $lead->fresh())->isConverted());

        // Step 3-4: matter opening + conflict check (search terms
        // deliberately unrelated to any existing record so the check
        // comes back clear).
        $matter = $this->pilot->openMatterWithConflictCheck(
            $firm,
            $client,
            $practiceArea->id,
            $matterType->id,
            ['Zzyzx Nonexistent Opposing Party 9182736'],
        );

        $this->assertSame(MatterStatus::Open, $matter->status);
        // conflict_check_runs has permanent FORCE ROW LEVEL SECURITY
        // (Section 39A-3I) — this post-call read needs explicit tenant
        // context re-established.
        $this->assertSame(
            ConflictCheckRunStatus::Completed,
            $this->runWithFirmContext($firm, fn () => $matter->conflictCheckRuns()->latest('id')->first())->status
        );

        // Step 5: intake
        $intake = $this->pilot->submitIntake($firm, $client, $intakeTemplate, ['immigration_status' => 'H-1B'], $matter);

        $this->assertSame(IntakeSubmissionStatus::Submitted, $intake->status);

        // Step 6: document request
        $documentRequest = $this->pilot->requestDocuments($firm, $client, [
            ['label' => 'Passport copy'],
            ['label' => 'I-94 record'],
        ], $matter);

        $this->assertCount(2, $documentRequest->items);

        // Step 7: document upload (clean scan — path has no
        // eicar/infected/scanfail marker)
        $document = $this->pilot->uploadDocument(
            $firm,
            'passport.pdf',
            'application/pdf',
            2048,
            'documents/pilot-passport.pdf',
            $matter,
            $client,
        );

        $this->assertSame(DocumentScanStatus::Clean, $document->scan_status);
        $this->assertSame(DocumentStatus::Uploaded, $document->status);
        $this->assertTrue($document->isUsable());

        // Step 8: deadline reminders
        $reminderDates = $this->pilot->scheduleDeadlineWithReminders(
            $firm,
            'Response deadline',
            'response_deadline',
            now()->addDays(30),
            [7, 3, 1],
            $matter,
        );

        $this->assertCount(3, $reminderDates);
        // Section 39A-3K follow-up: calendar_events now has permanent
        // FORCE ROW LEVEL SECURITY (Section 39A-3K). The write above
        // (via DeadlineService::create() -> CalendarEventService::
        // createFor(), inside DeadlineService's own runWithFirmContext()
        // wrap) still succeeds exactly as before, but that context is
        // always cleared again before scheduleDeadlineWithReminders()
        // returns — so this post-call raw count read needs its own
        // explicit, scoped tenant context or it would be fail-closed to
        // zero rows.
        $this->assertSame(
            1,
            $this->runWithFirmContext($firm, fn () => \App\Models\CalendarEvent::withoutGlobalScopes()->where('matter_id', $matter->id)->count()),
        );

        // Step 9: invoice draft
        $invoice = $this->pilot->draftInvoice($firm, $client, 'Flat fee for representation', 150000, $matter);

        $this->assertSame(InvoiceStatus::Draft, $invoice->status);
        $this->assertSame(150000, $invoice->total_cents);

        // Step 10: payment plan
        $plan = $this->pilot->createAndActivatePaymentPlan($firm, $client, [
            ['amount_cents' => 75000, 'due_at' => now()->addDays(15)],
            ['amount_cents' => 75000, 'due_at' => now()->addDays(45)],
        ], $matter, $invoice);

        $this->assertSame(PaymentPlanStatus::Active, $plan->status);
        $this->assertCount(2, $plan->installments);

        // Invoices must be reviewed/approved/sent before a payment can
        // apply to them (InvoiceDraftingService/PaymentApplicationService
        // rule) — this is normal invoice lifecycle, not a pilot-workflow
        // shortcut.
        $this->invoices->submitForReview($invoice);
        $this->invoices->approve($this->runWithFirmContext($firm, fn () => $invoice->fresh()));
        $this->invoices->send($this->runWithFirmContext($firm, fn () => $invoice->fresh()));

        // Step 11: manual payment (accepted operating payment, applied
        // to the invoice)
        $payment = $this->pilot->recordManualPayment($firm, $client, 75000, $matter, $this->runWithFirmContext($firm, fn () => $invoice->fresh()));

        $this->assertSame(PaymentStatus::Succeeded, $payment->status);
        $this->assertSame(75000, $this->runWithFirmContext($firm, fn () => $invoice->fresh())->amount_paid_cents);

        // Step 12: readiness score
        ReadinessScorecardComponent::factory()->create(['component_key' => 'documents_approved', 'status' => ReadinessComponentStatus::Active]);

        $score = $this->pilot->computeReadinessScore($this->runWithFirmContext($firm, fn () => $matter->fresh()));

        $this->assertSame($matter->id, $score->matter_id);
        $this->assertNotNull($score->computed_at);
    }

    public function test_the_workflow_can_run_for_a_second_independent_firm_without_cross_contamination(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $leadA = FirmLead::factory()->forFirm($firmA)->create();
        $leadB = FirmLead::factory()->forFirm($firmB)->create();

        $clientA = $this->pilot->convertLeadToClient($leadA, ['display_name' => 'Firm A Client', 'email' => 'a@example.test']);
        $clientB = $this->pilot->convertLeadToClient($leadB, ['display_name' => 'Firm B Client', 'email' => 'b@example.test']);

        $this->assertSame($firmA->id, $clientA->firm_id);
        $this->assertSame($firmB->id, $clientB->firm_id);
        $this->assertNotSame($clientA->id, $clientB->id);
    }
}
