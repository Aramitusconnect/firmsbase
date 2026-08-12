<?php

declare(strict_types=1);

namespace App\Marketplace\Services;

use App\Enums\FirmLeadStatus;
use App\Enums\MarketplaceIntakeStatus;
use App\Enums\WebhookEventType;
use App\Marketplace\Models\MarketplaceIntake;
use App\Models\Firm;
use App\Models\FirmLead;
use App\Models\FirmUser;
use App\Models\Matter;
use App\Services\DocumentSecurityService;
use App\Services\LeadConversionService;
use App\Services\MatterCreationService;
use App\Services\TenantContextService;
use App\Services\WebhookEventRecorderService;
use Illuminate\Support\Facades\DB;

/**
 * ConvertMarketplaceProspectService — Mission 3 (MyAttorney Conversion
 * + AI Intake), checkpoint 11. The mission's central checkpoint:
 * turning an Accepted MarketplaceIntake into a real Client and Matter,
 * for the first time in this mission — and doing so through the SAME
 * canonical machinery every other Client/Matter creation path in this
 * codebase already uses, never a parallel shortcut (this mission's own
 * top-line rule: AI/the browser must never bypass canonical business
 * rules).
 *
 * The bridge is exactly what marketplace_intakes' own create-table
 * migration already documented, verbatim, since checkpoint 1: create/
 * resolve a firm_leads row, then defer to the EXISTING
 * LeadConversionService for the actual Client creation — this service
 * never creates a Client directly, matching Client's own "single
 * legitimate path" rule. Matter creation is a distinct, later step via
 * the EXISTING MatterCreationService, matching that service's own hard
 * requirement that a real Client already exist. Neither existing
 * service is modified or duplicated here — this class is pure
 * orchestration.
 *
 * Conflict-review gate: markAccepted() (checkpoint 10) already refuses
 * a ConflictReviewRequired intake, and nothing anywhere in this
 * codebase can move an Accepted intake back to ConflictReviewRequired
 * (markConflictReviewRequired() itself only accepts an UnderReview
 * intake) — so re-asserting status === Accepted here is airtight
 * proof the conflict gate already passed, not a gap.
 *
 * matterTypeId is a required, caller-supplied parameter (never
 * auto-resolved) — no PracticeArea-to-MatterType default mapping
 * exists anywhere in this codebase (a practice area legitimately has
 * many matter types), matching MatterCreationService's own required,
 * non-defaulted parameter design. A Firm reviewer picks it explicitly
 * at conversion time.
 */
class ConvertMarketplaceProspectService
{
    public function __construct(
        private readonly LeadConversionService $leadConversion,
        private readonly MatterCreationService $matterCreation,
        private readonly DocumentSecurityService $documentSecurity,
        private readonly MarketplaceIntakeDocumentService $intakeDocuments = new MarketplaceIntakeDocumentService,
        private readonly MarketplaceIntakeService $intakeService = new MarketplaceIntakeService,
        private readonly TenantContextService $tenantContext = new TenantContextService,
    ) {}

    /**
     * @throws \RuntimeException if the intake is not Accepted, already converted,
     *                           or has no practice area to create a Matter against.
     */
    public function convert(
        Firm $firm,
        MarketplaceIntake $intake,
        int $matterTypeId,
        ?FirmUser $actor = null,
        ?int $assignedAttorneyId = null,
    ): Matter {
        $this->assertBelongsToFirm($firm, $intake);

        if ($intake->isConverted()) {
            throw new \RuntimeException('This intake has already been converted.');
        }

        if ($intake->status !== MarketplaceIntakeStatus::Accepted) {
            throw new \RuntimeException('Only an Accepted intake can be converted.');
        }

        if ($intake->practice_area_id === null) {
            throw new \RuntimeException('This intake has no practice area — cannot create a matter without one.');
        }

        $practiceAreaId = (int) $intake->practice_area_id;

        // Step 1: a plain, unrestricted FirmLead create — mirrors
        // "+Add Lead"'s own shape exactly (identity fields only, never
        // structured_data, which holds question-specific answers, not
        // identity fields). Always creates a new row; this service
        // does not attempt lead deduplication (a genuinely separate
        // concern, out of this checkpoint's scope).
        $firmLead = $this->tenantContext->runWithFirmContext($firm, fn () => FirmLead::create([
            'firm_id' => $firm->id,
            'practice_area_interest_id' => $practiceAreaId,
            'name' => $intake->prospect_name,
            'email' => $intake->prospect_email,
            'phone' => $intake->prospect_phone,
            'status' => FirmLeadStatus::New,
        ]));

        // Step 2: the ONLY path a FirmLead ever becomes a Client —
        // reused wholesale. Fires WebhookEventType::ClientCreated
        // internally; nothing further needed here for the Client side.
        $client = $this->leadConversion->convert($firmLead, [
            'display_name' => $intake->prospect_name,
            'legal_name' => null,
            'email' => $intake->prospect_email,
            'phone' => $intake->prospect_phone,
            'preferred_language' => 'en',
            'preferred_timezone' => null,
        ], $actor?->user);

        // Step 3: the ONLY general-purpose "create a matter" service.
        // Always leaves the new Matter in Draft — opening (and its own
        // conflict-check gate) stays MatterOpeningService's exclusive
        // job, out of this checkpoint's scope.
        $matter = $this->matterCreation->create($firm, $client, $practiceAreaId, $matterTypeId, $assignedAttorneyId);

        // MatterCreationService itself fires no webhook event
        // (confirmed) — mirror ImportApplyService's own standalone
        // MatterCreated emission here so this checkpoint's own Matter
        // creation is not silent.
        DB::afterCommit(function () use ($firm, $matter) {
            try {
                app(WebhookEventRecorderService::class)->record($firm, WebhookEventType::MatterCreated, $matter);
            } catch (\Throwable $e) {
                report($e);
            }
        });

        // Step 4: re-link every scan-clean document — never an
        // infected/pending one — to the real Matter/Client.
        $usableDocuments = $this->intakeDocuments->usableDocumentsForFirmReview($firm, $intake);

        foreach ($usableDocuments as $document) {
            $this->documentSecurity->linkToMatterAndClient($document, $matter, $client);
        }

        // Step 5: the intake's own terminal transition — the sole
        // writer of converted_firm_lead_id/converted_client_id/
        // converted_at.
        $this->intakeService->markConverted($firm, $intake, $firmLead, $client, $actor);

        // Not ->fresh() — no active firm context at this point (each
        // step above opens and closes its own runWithFirmContext), and
        // nothing after MatterCreationService::create() mutates the
        // matter's own row, so the in-memory object is already current.
        return $matter;
    }

    private function assertBelongsToFirm(Firm $firm, MarketplaceIntake $intake): void
    {
        if ((int) $intake->firm_id !== (int) $firm->id) {
            throw new \RuntimeException('This marketplace intake does not belong to this firm.');
        }
    }
}
