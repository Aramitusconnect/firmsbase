<?php

declare(strict_types=1);

namespace App\Marketplace\Services;

use App\Enums\MarketplaceIntakeEventType;
use App\Enums\MarketplaceIntakeStatus;
use App\Marketplace\Models\DirectoryFirm;
use App\Marketplace\Models\MarketplaceIntake;
use App\Marketplace\Models\MarketplaceIntakeEvent;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\PracticeArea;
use App\Services\TenantContextService;

/**
 * MarketplaceIntakeService — Mission 3 (MyAttorney Conversion + AI
 * Intake), checkpoint 1. The ONLY writer of
 * marketplace_intakes/marketplace_intake_events domain-model rows at
 * this checkpoint (start + basic status transitions + event
 * recording). Deliberately does NOT yet include the public-facing
 * signed-URL/session/throttling layer — that is checkpoint 2's own
 * scope ("secure session/resume architecture"). Mirrors
 * PaymentRequestService's own create()/recordEvent() shape exactly.
 */
class MarketplaceIntakeService
{
    public function start(
        Firm $firm,
        ?DirectoryFirm $directoryFirm = null,
        ?PracticeArea $practiceArea = null,
    ): MarketplaceIntake {
        if ($directoryFirm !== null && (int) $directoryFirm->firm_id !== (int) $firm->id) {
            throw new \RuntimeException('This directory listing does not belong to this firm.');
        }

        return (new TenantContextService)->runWithFirmContext($firm, function () use ($firm, $directoryFirm, $practiceArea) {
            $intake = MarketplaceIntake::create([
                'firm_id' => $firm->id,
                'directory_firm_id' => $directoryFirm?->id,
                'practice_area_id' => $practiceArea?->id,
                'status' => MarketplaceIntakeStatus::Started,
            ]);

            $this->recordEvent($firm, $intake, MarketplaceIntakeEventType::Started);

            return $intake;
        });
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
