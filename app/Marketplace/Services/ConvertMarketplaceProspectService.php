<?php

declare(strict_types=1);

namespace App\Marketplace\Services;

use App\Enums\ConsentChannel;
use App\Enums\MarketplaceIntakeStatus;
use App\Enums\WebhookEventType;
use App\Marketplace\Models\MarketplaceIntake;
use App\Models\Firm;
use App\Models\FirmLead;
use App\Models\FirmUser;
use App\Models\Matter;
use App\Services\ClientPortalService;
use App\Services\ConsentService;
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
 *
 * Checkpoint 13 ("Client Portal handoff + notifications/consent")
 * addition: translates the prospect's own submission-time consent
 * (never fabricated by the Firm reviewer performing this conversion)
 * into real ConsentService records and, if portal consent was
 * granted, marks the new Client Invited via ClientPortalService.
 * Deliberately does NOT send a portal-invitation email — no
 * accept-invitation page exists anywhere in this app for ANY client
 * (a confirmed, pre-existing gap unrelated to this mission, out of
 * this checkpoint's own scope to build).
 */
class ConvertMarketplaceProspectService
{
    /**
     * Mission 3, checkpoint 13 — the consent-text version this
     * conversion attributes to every ConsentService::capture() call it
     * makes. A single, versioned constant (never a per-call literal)
     * so a future change to the intake's own consent copy has one
     * place to bump, matching ConsentService's own $consentTextVersion
     * contract (an auditable record of exactly what the prospect
     * agreed to, not just that they agreed to something).
     */
    private const CONSENT_TEXT_VERSION = 'myattorney-intake-consent-v1';

    public function __construct(
        private readonly LeadConversionService $leadConversion,
        private readonly MatterCreationService $matterCreation,
        private readonly DocumentSecurityService $documentSecurity,
        private readonly ConsentService $consentService,
        private readonly ClientPortalService $clientPortal,
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
        // concern, out of this checkpoint's scope). status is
        // deliberately NEVER set explicitly here — same as
        // CreateFirmLead's own "+Add Lead" page — the model's own
        // firm_leads.status DB default (FirmLeadStatus::New) applies,
        // matching WorkflowTransitionEnforcementSearchTest's own
        // project-wide rule that every direct status-enum write for a
        // catalog workflow must live inside app/Services, never
        // app/Marketplace/Services.
        $firmLead = $this->tenantContext->runWithFirmContext($firm, fn () => FirmLead::create([
            'firm_id' => $firm->id,
            'practice_area_interest_id' => $practiceAreaId,
            'name' => $intake->prospect_name,
            'email' => $intake->prospect_email,
            'phone' => $intake->prospect_phone,
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

        // Step 2.5 (checkpoint 13): translate the prospect's OWN
        // submission-time consent (MarketplaceIntakeService::
        // markSubmitted()'s own communicationsConsent/portalConsent
        // params — never fabricated on their behalf by the Firm
        // reviewer performing this conversion) into real, channel-
        // scoped ConsentService records now that a real Client finally
        // exists. Deliberately NOT wrapped in try/catch, unlike the
        // webhook emission below — a real consent record is a
        // first-class part of the handoff, not a best-effort side
        // notification, and must fail the whole conversion loudly if
        // it cannot be recorded rather than silently proceeding without
        // it (fail-closed).
        if ($intake->communications_consent_at !== null) {
            $this->consentService->capture(
                $firm,
                $client->id,
                ConsentChannel::Email,
                self::CONSENT_TEXT_VERSION,
                actor: $actor?->user,
                capturedVia: 'myattorney_intake_submission',
            );
        }

        if ($intake->portal_consent_at !== null) {
            $this->consentService->capture(
                $firm,
                $client->id,
                ConsentChannel::Portal,
                self::CONSENT_TEXT_VERSION,
                actor: $actor?->user,
                capturedVia: 'myattorney_intake_submission',
            );

            // Only marks portal_status=Invited/generates the invitation
            // token — ClientPortalService::invite() itself sends no
            // email (confirmed pre-existing gap: no accept-invitation
            // page exists anywhere in this app for ANY client, not only
            // MyAttorney-converted ones — out of this checkpoint's own
            // scope to build; flagged for the mission's final report).
            $this->clientPortal->invite($client);
        }

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
