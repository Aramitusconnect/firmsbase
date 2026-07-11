<?php

namespace App\Services;

use App\Enums\CalendarEventType;
use App\Enums\IntakeSubmissionStatus;
use App\Enums\ManualPaymentMethod;
use App\Enums\MatterStatus;
use App\Enums\PaymentClassification;
use App\Models\Client;
use App\Models\Deadline;
use App\Models\Document;
use App\Models\DocumentRequest;
use App\Models\Firm;
use App\Models\FirmLead;
use App\Models\IntakeSubmission;
use App\Models\IntakeTemplate;
use App\Models\Invoice;
use App\Models\Matter;
use App\Models\MatterReadinessScore;
use App\Models\Payment;
use App\Models\PaymentPlan;
use App\Models\User;
use App\Services\VirusScan\VirusScanner;
use Illuminate\Support\Str;

/**
 * ProductionPilotWorkflowService — orchestrates the 12-step end-to-end
 * pilot workflow the master plan's Phase 5 Scope names verbatim: "lead
 * conversion, client creation, matter opening, conflict check, intake,
 * document request, document upload, deadline reminders, invoice
 * draft, payment plan, manual payment, and readiness score." This is
 * PURE ORCHESTRATION — every step delegates to the exact Phase 1-4
 * service that already owns that behavior; no business logic is
 * reimplemented here (project rule: services/tests/orchestrators
 * only). runFullPilotWorkflow() is what
 * ProductionPilotWorkflowServiceTest exercises to prove the acceptance
 * criterion "Pilot workflow test passes."
 */
class ProductionPilotWorkflowService
{
    public function __construct(
        private LeadConversionService $leadConversion,
        private MatterOpeningService $matterOpening,
        private DocumentRequestService $documentRequests,
        private DocumentSecurityService $documentSecurity,
        private VirusScanner $virusScanner,
        private DeadlineService $deadlines,
        private InvoiceDraftingService $invoices,
        private PaymentPlanService $paymentPlans,
        private ManualPaymentService $manualPayments,
        private MatterReadinessService $readiness,
    ) {
    }

    /**
     * Step 1-2: lead conversion + client creation. Client creation is
     * a side effect of LeadConversionService::convert() (project rule:
     * a Client is only ever created there) — there is no separate
     * "create a client" step to orchestrate.
     */
    public function convertLeadToClient(FirmLead $lead, array $clientAttributes, ?User $actor = null): Client
    {
        return $this->leadConversion->convert($lead, $clientAttributes, $actor);
    }

    /**
     * Step 3-4: matter opening + conflict check. Creates the Matter
     * row directly (no dedicated MatterCreationService exists in
     * Phase 1-4 — Matter::create() carries no gating rules of its own;
     * every gate lives in MatterOpeningService, which this step calls
     * immediately after), then requests and clears a conflict check
     * before opening.
     */
    public function openMatterWithConflictCheck(
        Firm $firm,
        Client $client,
        int $practiceAreaId,
        int $matterTypeId,
        array $conflictSearchTerms,
        ?User $actor = null,
    ): Matter {
        $matter = (new TenantContextService())->runWithFirmContext($firm, fn () => Matter::create([
            'firm_id' => $firm->id,
            'client_id' => $client->id,
            'primary_practice_area_id' => $practiceAreaId,
            'matter_type_id' => $matterTypeId,
            'status' => MatterStatus::Draft,
        ]));

        $conflictCheckRun = $this->matterOpening->requestConflictCheck($matter, $conflictSearchTerms, actor: $actor);

        $freshMatter = (new TenantContextService())->runWithFirmContext($firm, fn () => $matter->fresh());

        return $this->matterOpening->openMatter($freshMatter, $conflictCheckRun, $actor);
    }

    /**
     * Step 5: intake. No dedicated IntakeService exists in Phase 1-4 —
     * IntakeSubmission's only lifecycle is Draft -> Submitted -> ->
     * Reviewed with no gating service, so this step performs the same
     * two writes any caller would.
     *
     * Section 39A-3L, Checkpoint 13 — the entire method body is wrapped
     * in a single runWithFirmContext() call now that intake_submissions
     * is FORCE RLS-enabled. A partial wrap (create() only) was
     * confirmed to fail: update() would silently no-op (0 rows
     * affected, no exception) outside an active tenant context, and the
     * subsequent fresh() would then return null, violating this
     * method's non-nullable return type. submitIntake() does not call
     * any other already-self-wrapping method and is only ever called
     * from outside any active context, so wrapping the whole call here
     * carries no nested-wrap risk.
     */
    public function submitIntake(Firm $firm, Client $client, IntakeTemplate $template, array $responses, ?Matter $matter = null): IntakeSubmission
    {
        return (new TenantContextService())->runWithFirmContext($firm, function () use ($firm, $client, $template, $responses, $matter) {
            $submission = IntakeSubmission::create([
                'firm_id' => $firm->id,
                'client_id' => $client->id,
                'matter_id' => $matter?->id,
                'intake_template_id' => $template->id,
                'status' => IntakeSubmissionStatus::Draft,
                'responses_json' => [],
            ]);

            $submission->update([
                'status' => IntakeSubmissionStatus::Submitted,
                'responses_json' => $responses,
                'submitted_at' => now(),
            ]);

            return $submission->fresh();
        });
    }

    /**
     * Step 6: document request.
     */
    public function requestDocuments(Firm $firm, Client $client, array $items, ?Matter $matter = null): DocumentRequest
    {
        return $this->documentRequests->create($firm, $client, $items, $matter);
    }

    /**
     * Step 7: document upload. Uploads then scans synchronously (the
     * same VirusScanner + DocumentSecurityService::applyScanResult()
     * pair ScanDocumentJob calls asynchronously in real traffic) so the
     * pilot workflow test can assert on the final scanned state without
     * a real queue worker (project rule: no real queue workers
     * required in tests).
     */
    public function uploadDocument(
        Firm $firm,
        string $originalFilename,
        string $mimeType,
        int $sizeBytes,
        string $storagePath,
        ?Matter $matter = null,
        ?Client $client = null,
    ): Document {
        $document = $this->documentSecurity->upload(
            $firm,
            $originalFilename,
            $mimeType,
            $sizeBytes,
            'local',
            $storagePath,
            hash('sha256', $storagePath),
            $matter,
            $client,
        );

        $scanResult = $this->virusScanner->scan($document->storage_disk, $document->storage_path);

        return $this->documentSecurity->applyScanResult($document, $scanResult);
    }

    /**
     * Step 8: deadline reminders. Creates the deadline (which itself
     * auto-creates a linked CalendarEvent, per DeadlineService) and
     * returns the computed reminder dates.
     *
     * @return array<int, \Illuminate\Support\Carbon>
     */
    public function scheduleDeadlineWithReminders(
        Firm $firm,
        string $title,
        string $deadlineType,
        \DateTimeInterface $dueAt,
        array $reminderOffsetsDays,
        ?Matter $matter = null,
    ): array {
        $deadline = $this->deadlines->create($firm, $title, $deadlineType, $dueAt, $matter, reminderOffsetsDays: $reminderOffsetsDays);

        return $this->deadlines->reminderDates($deadline);
    }

    /**
     * Step 9: invoice draft.
     */
    public function draftInvoice(Firm $firm, Client $client, string $description, int $amountCents, ?Matter $matter = null): Invoice
    {
        return $this->invoices->createFlatFee($firm, $client, $description, $amountCents, $matter);
    }

    /**
     * Step 10: payment plan.
     *
     * @param  array<int, array{amount_cents:int, due_at:\DateTimeInterface}>  $installments
     */
    public function createAndActivatePaymentPlan(Firm $firm, Client $client, array $installments, ?Matter $matter = null, ?Invoice $invoice = null): PaymentPlan
    {
        $plan = $this->paymentPlans->create($firm, $client, $installments, $matter, $invoice);

        return $this->paymentPlans->activate($plan);
    }

    /**
     * Step 11: manual payment. Uses PaymentClassification::
     * OperatingPayment by default — the pilot workflow proves the
     * happy accepted path, not the blocked-payment path (that is
     * already exhaustively covered by Phase 3's own test suite).
     */
    public function recordManualPayment(
        Firm $firm,
        Client $client,
        int $amountCents,
        ?Matter $matter = null,
        ?Invoice $invoice = null,
        PaymentClassification $classification = PaymentClassification::OperatingPayment,
    ): Payment {
        return $this->manualPayments->submit(
            $firm,
            $client,
            $amountCents,
            ManualPaymentMethod::Check,
            $classification,
            (string) Str::uuid(),
            $matter,
            $invoice,
        );
    }

    /**
     * Step 12: readiness score.
     */
    public function computeReadinessScore(Matter $matter): MatterReadinessScore
    {
        return $this->readiness->recompute($matter);
    }
}
