<?php

declare(strict_types=1);

namespace App\Marketplace\Services;

use App\Enums\MarketplaceIntakeEventType;
use App\Enums\MarketplaceIntakeStatus;
use App\Marketplace\Exceptions\MarketplaceIntakeIneligibleException;
use App\Marketplace\Models\DirectoryFirm;
use App\Marketplace\Models\MarketplaceIntake;
use App\Marketplace\Models\MarketplaceIntakeEvent;
use App\Models\Client;
use App\Models\Document;
use App\Models\Firm;
use App\Models\FirmLead;
use App\Models\FirmUser;
use App\Models\PracticeArea;
use App\Services\IntakeTemplateService;
use App\Services\TenantContextService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

/**
 * MarketplaceIntakeService — Mission 3 (MyAttorney Conversion + AI
 * Intake), checkpoints 1-3. The ONLY writer of
 * marketplace_intakes/marketplace_intake_events domain-model rows
 * (start + basic status transitions + event recording), plus the
 * secure resumable-link primitives (checkpoint 2: signed URL
 * generation, resume tracking, expiry) and Firm eligibility gating
 * (checkpoint 3: startForDirectoryFirm()). Mirrors PaymentRequestService's
 * own create()/recordEvent()/signedUrl() shape exactly.
 */
class MarketplaceIntakeService
{
    public function __construct(
        private readonly MarketplaceIntakeEligibilityService $eligibilityService = new MarketplaceIntakeEligibilityService,
        private readonly IntakeTemplateService $templateService = new IntakeTemplateService,
    ) {}

    /**
     * How long a fresh intake's resumable link stays valid by default
     * — mirrors PaymentRequestService::DEFAULT_EXPIRY_DAYS. A prospect
     * who has not finished (or a Firm that has not yet reviewed) an
     * intake within this window must start over rather than trust an
     * indefinitely-valid public link.
     */
    private const DEFAULT_EXPIRY_DAYS = 30;

    /**
     * The ONLY entry point a genuinely public "Start Secure Intake"
     * surface may call — checkpoint 3's Firm-eligibility gate. Every
     * fact this method acts on (which canonical Firm, whether it's
     * eligible) is re-derived server-side from $directoryFirm's own
     * stored row; the caller supplies nothing the browser could have
     * forged beyond which listing it's looking at (the slug that
     * resolved to this $directoryFirm in the first place — resolving
     * that slug is the caller's job, not this method's).
     *
     * @throws MarketplaceIntakeIneligibleException if the listing is
     *                                              unclaimed, lacks the SecureIntake capability, is not publicly
     *                                              Published, is not accepting_inquiries, or has no canonical
     *                                              Firm at all — see MarketplaceIntakeEligibilityService for the
     *                                              exact, ordered check list.
     */
    public function startForDirectoryFirm(DirectoryFirm $directoryFirm, ?PracticeArea $practiceArea = null): MarketplaceIntake
    {
        $eligibility = $this->eligibilityService->evaluate($directoryFirm);

        if (! $eligibility->eligible) {
            throw new MarketplaceIntakeIneligibleException($eligibility->reasonCode ?? 'ineligible');
        }

        $firm = Firm::query()->findOrFail($directoryFirm->firm_id);

        return $this->start($firm, $directoryFirm, $practiceArea);
    }

    public function start(
        Firm $firm,
        ?DirectoryFirm $directoryFirm = null,
        ?PracticeArea $practiceArea = null,
    ): MarketplaceIntake {
        if ($directoryFirm !== null && (int) $directoryFirm->firm_id !== (int) $firm->id) {
            throw new \RuntimeException('This directory listing does not belong to this firm.');
        }

        return (new TenantContextService)->runWithFirmContext($firm, function () use ($firm, $directoryFirm, $practiceArea) {
            $template = $this->templateService->templateForPracticeArea($practiceArea);

            $intake = MarketplaceIntake::create([
                'firm_id' => $firm->id,
                'directory_firm_id' => $directoryFirm?->id,
                'practice_area_id' => $practiceArea?->id,
                'intake_template_id' => $template?->id,
                'status' => MarketplaceIntakeStatus::Started,
                'expires_at' => now()->addDays(self::DEFAULT_EXPIRY_DAYS),
            ]);

            $this->recordEvent($firm, $intake, MarketplaceIntakeEventType::Started);

            return $intake;
        });
    }

    /**
     * The ONLY identifier ever placed in the public resumable intake
     * URL — mirrors PaymentRequestService::signedUrl() exactly. The
     * signature carries nothing but the opaque uuid; every other fact
     * about the intake is read server-side from the row itself.
     */
    public function signedUrl(MarketplaceIntake $intake): string
    {
        $expiration = $intake->expires_at ?? now()->addDays(self::DEFAULT_EXPIRY_DAYS);

        return URL::temporarySignedRoute('public.marketplace-intakes.show', $expiration, ['uuid' => $intake->uuid]);
    }

    /**
     * Resolves a marketplace_intakes row from nothing but its own
     * public uuid (a resumable-link visitor holds no firm context) —
     * mirrors PaymentRequestService::resolveByUuid() exactly.
     */
    public function resolveByUuid(string $uuid): ?MarketplaceIntake
    {
        return (new TenantContextService)->withMarketplaceIntakeSelfLookupContext(
            $uuid,
            fn () => MarketplaceIntake::query()->where('uuid', $uuid)->first(),
        );
    }

    /**
     * Called on every genuine page load of the resumable link — mirrors
     * PaymentRequestService::recordLinkAccessed() exactly. Never
     * mutates status; a resumed intake stays wherever its own state
     * machine already had it.
     */
    public function recordLinkResumed(Firm $firm, MarketplaceIntake $intake, ?string $ipAddress): void
    {
        (new TenantContextService)->runWithFirmContext($firm, function () use ($firm, $intake, $ipAddress) {
            $intake->update(['last_resumed_at' => now()]);

            $this->recordEvent($firm, $intake, MarketplaceIntakeEventType::LinkResumed, ipAddress: $ipAddress);
        });
    }

    /**
     * A non-terminal intake whose expires_at has passed transitions to
     * Expired the next time anything tries to act on it — never
     * silently treated as still-open. Idempotent: calling this again
     * on an already-Expired intake is a no-op.
     */
    public function markExpired(Firm $firm, MarketplaceIntake $intake): MarketplaceIntake
    {
        $this->assertBelongsToFirm($firm, $intake);

        if ($intake->status === MarketplaceIntakeStatus::Expired) {
            return $intake;
        }

        if ($intake->status->isTerminal()) {
            throw new \RuntimeException('Only a non-terminal intake can expire.');
        }

        return (new TenantContextService)->runWithFirmContext($firm, function () use ($firm, $intake) {
            $intake->update(['status' => MarketplaceIntakeStatus::Expired]);

            $this->recordEvent($firm, $intake, MarketplaceIntakeEventType::Expired);

            return $intake->fresh();
        });
    }

    /**
     * Mission 3, checkpoint 13. $communicationsConsent/$portalConsent
     * are the prospect's own affirmative choices at their own final
     * submission step — never fabricated on their behalf later. Both
     * default false (opt-in only, matching this project's own
     * established consent philosophy — see ConsentService's own
     * docblock) so every pre-checkpoint-13 caller of this method
     * remains valid unchanged. Recorded here as timestamps rather than
     * real ConsentService rows because no Client exists yet at
     * submission time (ConsentService::capture() requires a client_id);
     * ConvertMarketplaceProspectService (checkpoint 11 + this
     * checkpoint's own extension) translates a granted timestamp into a
     * real, channel-scoped consent record once the Client is created.
     */
    public function markSubmitted(
        Firm $firm,
        MarketplaceIntake $intake,
        bool $communicationsConsent = false,
        bool $portalConsent = false,
    ): MarketplaceIntake {
        $this->assertBelongsToFirm($firm, $intake);

        if (! $intake->status->isEditableByProspect()) {
            throw new \RuntimeException('Only a Started/InProgress intake can be submitted.');
        }

        return (new TenantContextService)->runWithFirmContext($firm, function () use ($firm, $intake, $communicationsConsent, $portalConsent) {
            $intake->update([
                'status' => MarketplaceIntakeStatus::Submitted,
                'submitted_at' => now(),
                'communications_consent_at' => $communicationsConsent ? now() : null,
                'portal_consent_at' => $portalConsent ? now() : null,
            ]);

            $this->recordEvent($firm, $intake, MarketplaceIntakeEventType::Submitted, metadata: [
                'communications_consent' => $communicationsConsent,
                'portal_consent' => $portalConsent,
            ]);

            return $intake->fresh();
        });
    }

    public function markUnderReview(Firm $firm, MarketplaceIntake $intake, ?FirmUser $actor = null): MarketplaceIntake
    {
        $this->assertBelongsToFirm($firm, $intake);

        if ($intake->status !== MarketplaceIntakeStatus::Submitted) {
            throw new \RuntimeException('Only a Submitted intake can be marked under review.');
        }

        return (new TenantContextService)->runWithFirmContext($firm, function () use ($firm, $intake, $actor) {
            $intake->update([
                'status' => MarketplaceIntakeStatus::UnderReview,
                'under_review_at' => now(),
            ]);

            $this->recordEvent($firm, $intake, MarketplaceIntakeEventType::MarkedUnderReview, actor: $actor);

            return $intake->fresh();
        });
    }

    /**
     * Mission 3, checkpoint 8. Only MarketplaceIntakeConflictCheckService
     * calls this — never directly from a controller/AI surface — since
     * it is the outcome of a real search, not a bare user action.
     * $possibleMatchCount is metadata only, never the matched entity's
     * own name/type/id (confidential existing-client data).
     */
    public function markConflictReviewRequired(Firm $firm, MarketplaceIntake $intake, ?FirmUser $actor = null, int $possibleMatchCount = 0): MarketplaceIntake
    {
        $this->assertBelongsToFirm($firm, $intake);

        if ($intake->status !== MarketplaceIntakeStatus::UnderReview) {
            throw new \RuntimeException('Only an UnderReview intake can require conflict review.');
        }

        return (new TenantContextService)->runWithFirmContext($firm, function () use ($firm, $intake, $actor, $possibleMatchCount) {
            $intake->update(['status' => MarketplaceIntakeStatus::ConflictReviewRequired]);

            $this->recordEvent($firm, $intake, MarketplaceIntakeEventType::ConflictReviewRequired, actor: $actor, metadata: ['possible_match_count' => $possibleMatchCount]);

            return $intake->fresh();
        });
    }

    /**
     * A Firm reviewer (FirmOwner/Attorney — same actor set
     * ClientCrmAccessPolicyService::canResolveConflictResult() already
     * requires for the Matter-level equivalent) has manually confirmed
     * the flagged possible matches are not a real conflict, and the
     * intake returns to UnderReview so the normal accept/decline
     * workflow (checkpoint 10) can proceed. Never automatic — mirrors
     * ConflictCheckService::resolveResult()'s own "only a human may set
     * a terminal outcome" rule.
     */
    public function clearConflictReview(Firm $firm, MarketplaceIntake $intake, ?FirmUser $actor = null): MarketplaceIntake
    {
        $this->assertBelongsToFirm($firm, $intake);

        if ($intake->status !== MarketplaceIntakeStatus::ConflictReviewRequired) {
            throw new \RuntimeException('Only a ConflictReviewRequired intake can be cleared back to review.');
        }

        return (new TenantContextService)->runWithFirmContext($firm, function () use ($firm, $intake, $actor) {
            $intake->update(['status' => MarketplaceIntakeStatus::UnderReview]);

            $this->recordEvent($firm, $intake, MarketplaceIntakeEventType::ConflictReviewCleared, actor: $actor);

            return $intake->fresh();
        });
    }

    /**
     * Mission 3, checkpoint 10 — the Firm's own commitment to proceed
     * toward a consultation with this prospect. Deliberately allowed
     * ONLY from Submitted/UnderReview, never from
     * ConflictReviewRequired — the browser/AI/a reviewer must never be
     * able to bypass an unresolved conflict flag by accepting straight
     * through it; clearConflictReview() must run first (mirrors the
     * state machine's own documented "(ConflictReviewRequired ->)
     * Accepted" shape, where the parenthetical only resolves back to
     * UnderReview before Accepted becomes reachable).
     *
     * Sets accepted_at and records Accepted — nothing more. No
     * FirmLead/Client/Matter/Consultation row is ever created here;
     * converted_firm_lead_id/converted_client_id/converted_at are set
     * ONLY by ConvertMarketplaceProspectService (checkpoint 11, per
     * this model's own docblock) — accepting a prospect is not the
     * same act as converting one.
     */
    public function markAccepted(Firm $firm, MarketplaceIntake $intake, ?FirmUser $actor = null): MarketplaceIntake
    {
        $this->assertBelongsToFirm($firm, $intake);

        if (! in_array($intake->status, [MarketplaceIntakeStatus::Submitted, MarketplaceIntakeStatus::UnderReview], true)) {
            throw new \RuntimeException('Only a Submitted or UnderReview intake can be accepted — clear any pending conflict review first.');
        }

        $updated = (new TenantContextService)->runWithFirmContext($firm, function () use ($firm, $intake, $actor) {
            $intake->update([
                'status' => MarketplaceIntakeStatus::Accepted,
                'accepted_at' => now(),
            ]);

            $this->recordEvent($firm, $intake, MarketplaceIntakeEventType::Accepted, actor: $actor);

            return $intake->fresh();
        });

        // Mission 3, checkpoint 13. Deferred to afterCommit() and never
        // allowed to throw — a transactional email failing to send must
        // never appear to fail (or actually roll back) the Firm's own
        // Accept decision, which has already been durably recorded
        // above (mirrors DocumentSecurityService::upload()'s own
        // webhook-emission pattern).
        DB::afterCommit(function () use ($firm, $updated) {
            try {
                app(MarketplaceProspectNotificationService::class)->notifyAccepted($firm, $updated);
            } catch (\Throwable $e) {
                report($e);
            }
        });

        return $updated;
    }

    /**
     * Mission 3, checkpoint 10. Unlike markAccepted(), Decline is
     * allowed from ANY pending-Firm-review state including
     * ConflictReviewRequired — a Firm may always decline a prospect
     * regardless of whether a possible conflict was ever resolved
     * (declining never risks acting on an unresolved conflict the way
     * accepting would). $reason is required, free text — matches this
     * codebase's established convention for "why did this not
     * proceed" fields (Payment::rejection_reason,
     * Opportunity::lost_reason, DirectoryClaim::rejection_reason) —
     * never a closed enum.
     */
    public function markDeclined(Firm $firm, MarketplaceIntake $intake, string $reason, ?FirmUser $actor = null): MarketplaceIntake
    {
        $this->assertBelongsToFirm($firm, $intake);

        if (trim($reason) === '') {
            throw new \InvalidArgumentException('A decline reason is required.');
        }

        if (! $intake->status->isPendingFirmReview()) {
            throw new \RuntimeException('Only an intake pending Firm review can be declined.');
        }

        $updated = (new TenantContextService)->runWithFirmContext($firm, function () use ($firm, $intake, $reason, $actor) {
            $intake->update([
                'status' => MarketplaceIntakeStatus::Declined,
                'declined_at' => now(),
                'decline_reason' => trim($reason),
            ]);

            $this->recordEvent($firm, $intake, MarketplaceIntakeEventType::Declined, actor: $actor);

            return $intake->fresh();
        });

        // Mission 3, checkpoint 13. See markAccepted()'s own comment —
        // same afterCommit()/never-throws contract.
        DB::afterCommit(function () use ($firm, $updated) {
            try {
                app(MarketplaceProspectNotificationService::class)->notifyDeclined($firm, $updated);
            } catch (\Throwable $e) {
                report($e);
            }
        });

        return $updated;
    }

    /**
     * Mission 3, checkpoint 11. The sole writer of
     * converted_firm_lead_id/converted_client_id/converted_at (per
     * this model's own long-standing docblock rule) — only
     * ConvertMarketplaceProspectService calls this, after the real
     * FirmLead/Client/Matter chain it orchestrates has already been
     * created via the existing canonical LeadConversionService/
     * MatterCreationService. This method itself creates nothing — it
     * only records the intake's own terminal transition.
     */
    public function markConverted(Firm $firm, MarketplaceIntake $intake, FirmLead $firmLead, Client $client, ?FirmUser $actor = null): MarketplaceIntake
    {
        $this->assertBelongsToFirm($firm, $intake);

        if ($intake->status !== MarketplaceIntakeStatus::Accepted) {
            throw new \RuntimeException('Only an Accepted intake can be converted.');
        }

        return (new TenantContextService)->runWithFirmContext($firm, function () use ($firm, $intake, $firmLead, $client, $actor) {
            $intake->update([
                'status' => MarketplaceIntakeStatus::Converted,
                'converted_firm_lead_id' => $firmLead->id,
                'converted_client_id' => $client->id,
                'converted_at' => now(),
            ]);

            $this->recordEvent($firm, $intake, MarketplaceIntakeEventType::Converted, actor: $actor, metadata: [
                'firm_lead_id' => $firmLead->id,
                'client_id' => $client->id,
            ]);

            return $intake->fresh();
        });
    }

    public function abandonExpired(Firm $firm, MarketplaceIntake $intake): MarketplaceIntake
    {
        $this->assertBelongsToFirm($firm, $intake);

        if ($intake->status->isTerminal()) {
            throw new \RuntimeException('Only a non-terminal intake can be abandoned.');
        }

        return (new TenantContextService)->runWithFirmContext($firm, function () use ($firm, $intake) {
            $intake->update([
                'status' => MarketplaceIntakeStatus::Abandoned,
                'abandoned_at' => now(),
            ]);

            $this->recordEvent($firm, $intake, MarketplaceIntakeEventType::Abandoned);

            return $intake->fresh();
        });
    }

    /**
     * Mission 3, checkpoint 7 — the ONLY way a document_uploaded event
     * reaches marketplace_intake_events, preserving this class's own
     * "sole writer" invariant rather than letting
     * MarketplaceIntakeDocumentService write MarketplaceIntakeEvent
     * rows directly. Never mutates status — a document upload does not
     * itself advance the intake's own lifecycle.
     */
    public function recordDocumentUploaded(Firm $firm, MarketplaceIntake $intake, Document $document, ?string $ipAddress = null): MarketplaceIntakeEvent
    {
        $this->assertBelongsToFirm($firm, $intake);

        return (new TenantContextService)->runWithFirmContext($firm, fn () => $this->recordEvent(
            $firm,
            $intake,
            MarketplaceIntakeEventType::DocumentUploaded,
            metadata: ['document_id' => $document->id],
            ipAddress: $ipAddress,
        ));
    }

    private function assertBelongsToFirm(Firm $firm, MarketplaceIntake $intake): void
    {
        if ((int) $intake->firm_id !== (int) $firm->id) {
            throw new \RuntimeException('This marketplace intake does not belong to this firm.');
        }
    }

    private function recordEvent(
        Firm $firm,
        MarketplaceIntake $intake,
        MarketplaceIntakeEventType $eventType,
        ?FirmUser $actor = null,
        array $metadata = [],
        ?string $ipAddress = null,
    ): MarketplaceIntakeEvent {
        return MarketplaceIntakeEvent::create([
            'firm_id' => $firm->id,
            'marketplace_intake_id' => $intake->id,
            'event_type' => $eventType,
            'actor_firm_user_id' => $actor?->id,
            'metadata' => $metadata === [] ? null : $metadata,
            'ip_address' => $ipAddress,
        ]);
    }
}
