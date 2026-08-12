<?php

declare(strict_types=1);

namespace App\Marketplace\Services;

use App\Enums\AiUsageActionType;
use App\Marketplace\Models\MarketplaceAiUsageEvent;
use App\Marketplace\Models\MarketplaceIntake;
use App\Models\Firm;
use App\Services\TenantContextService;
use App\ValueObjects\AiPromptRequest;
use App\ValueObjects\AiProviderResponse;

/**
 * MarketplaceAiUsageRecorderService — Mission 3 (MyAttorney Conversion
 * + AI Intake), checkpoint 6. The ONLY writer of
 * marketplace_ai_usage_events, and the counterpart to
 * AiUsageRecorderService for the two anonymous-actor cases that
 * service structurally cannot serve (ai_usage_events.user_id is a
 * DB-level NOT NULL foreign key, and no anonymous MyAttorney visitor
 * ever has a User row):
 *
 *   1. Pre-Firm classification — $firm and $intake both null.
 *   2. Firm-scoped conversational intake by an unconverted prospect —
 *      $firm and $intake set, but still no User.
 *
 * AiUsageRecorderService itself is untouched by this checkpoint — see
 * its own docblock, which remains accurate. This service does not
 * replace it, extend it, or share a base class with it; the two exist
 * side by side because the actors they record usage for are genuinely
 * different (a real firm User vs. an anonymous prospect).
 */
class MarketplaceAiUsageRecorderService
{
    public function __construct(
        private readonly TenantContextService $tenantContext = new TenantContextService,
    ) {}

    public function record(
        ?Firm $firm,
        ?MarketplaceIntake $intake,
        string $sessionHash,
        ?string $ipAddress,
        AiPromptRequest $request,
        AiProviderResponse $response,
    ): MarketplaceAiUsageEvent {
        if ($intake !== null && $firm === null) {
            throw new \InvalidArgumentException('A marketplace intake cannot be recorded without its owning firm.');
        }

        if ($intake !== null && (int) $intake->firm_id !== (int) $firm->id) {
            throw new \RuntimeException('This marketplace intake does not belong to this firm.');
        }

        $write = fn () => MarketplaceAiUsageEvent::create([
            'firm_id' => $firm?->id,
            'marketplace_intake_id' => $intake?->id,
            'session_hash' => $sessionHash,
            'ip_address' => $ipAddress,
            'provider' => $request->provider,
            'model' => $request->model,
            'action_type' => $request->actionType,
            'tokens_in' => $response->tokensIn,
            'tokens_out' => $response->tokensOut,
        ]);

        return $firm !== null
            ? $this->tenantContext->runWithFirmContext($firm, $write)
            : $this->tenantContext->runWithoutFirmContext($write);
    }

    /**
     * Convenience helper for MarketplaceIssueClassifierService, which
     * always records with the same fixed action type and no firm/intake.
     */
    public function recordPlatformClassification(
        string $sessionHash,
        ?string $ipAddress,
        AiPromptRequest $request,
        AiProviderResponse $response,
    ): MarketplaceAiUsageEvent {
        if ($request->actionType !== AiUsageActionType::IntakeClassification) {
            throw new \InvalidArgumentException('Platform classification usage must use AiUsageActionType::IntakeClassification.');
        }

        return $this->record(null, null, $sessionHash, $ipAddress, $request, $response);
    }
}
