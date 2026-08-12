<?php

declare(strict_types=1);

namespace App\Marketplace\Services;

use App\Enums\MarketplaceIntakeEventType;
use App\Enums\MarketplaceIntakeStatus;
use App\Marketplace\Exceptions\MarketplaceIntakeIneligibleException;
use App\Marketplace\Models\DirectoryFirm;
use App\Marketplace\Models\MarketplaceIntake;
use App\Marketplace\Models\MarketplaceIntakeEvent;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\PracticeArea;
use App\Services\IntakeTemplateService;
use App\Services\TenantContextService;
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

    public function markSubmitted(Firm $firm, MarketplaceIntake $intake): MarketplaceIntake
    {
        $this->assertBelongsToFirm($firm, $intake);

        if (! $intake->status->isEditableByProspect()) {
            throw new \RuntimeException('Only a Started/InProgress intake can be submitted.');
        }

        return (new TenantContextService)->runWithFirmContext($firm, function () use ($firm, $intake) {
            $intake->update([
                'status' => MarketplaceIntakeStatus::Submitted,
                'submitted_at' => now(),
            ]);

            $this->recordEvent($firm, $intake, MarketplaceIntakeEventType::Submitted);

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
