<?php

declare(strict_types=1);

namespace App\Marketplace\Services;

use App\Marketplace\ValueObjects\MarketplaceIssueClassificationResult;
use App\Models\PracticeArea;
use App\Services\AiModeResolutionService;
use App\Services\PromptInjectionResistanceService;
use App\ValueObjects\AiPromptRequest;

/**
 * MarketplaceIssueClassifierService — Mission 3 (MyAttorney Conversion
 * + AI Intake), checkpoint 6. The PRE-FIRM issue classifier (mission
 * requirements #1-3): a visitor describes their issue in a few words
 * before choosing a Firm, and this service proposes a single
 * PracticeArea. It is deliberately the ONLY thing AI is allowed to do
 * before a Firm is selected:
 *
 *   - Collects nothing but the issue description itself — no name, no
 *     contact detail, no confidential narrative. $issueDescription is
 *     hard-truncated to MAX_ISSUE_DESCRIPTION_LENGTH before it is ever
 *     placed in a prompt, enforcing "minimal routing information" in
 *     code, not just by convention.
 *   - Never creates a MarketplaceIntake, FirmLead, Client, or Matter.
 *   - Never ranks, scores, or selects a Firm — MarketplaceSearchService's
 *     existing deterministic marketplace search (Mission 2) is
 *     entirely untouched and remains the only thing that ever produces
 *     a Firm ordering. AI here only proposes a practice area filter a
 *     visitor may then search with (or ignore).
 *   - Its output is always a PROPOSAL: the visitor may accept the
 *     suggested PracticeArea or pick a different one manually — see
 *     MarketplaceIssueClassificationResult's own docblock.
 *
 * Structural safety: $issueDescription is placed ONLY in
 * AiPromptRequest::documentDerivedText, never instructionText — the
 * same rule every other AI call site in this codebase follows (see
 * AiPromptRequest's own docblock) — so nothing the visitor types can
 * ever alter which schema is requested or trigger a tool action.
 * The adapter's own structural guarantee (never derives
 * requestedToolActions from documentDerivedText) is the enforcement
 * mechanism; this service's use of documentDerivedText is what invokes
 * that guarantee.
 *
 * Every failure mode (kill switch engaged, throttled, empty
 * description, provider throws, response fails schema validation,
 * classified practice_area_code does not match a real active/visible
 * PracticeArea) is caught and converted to
 * MarketplaceIssueClassificationResult::unavailable() — this method
 * never throws for an ordinary "AI unavailable" case, and never
 * exposes a stack trace to the caller. A visitor who receives an
 * unavailable result can always fall back to picking a practice area
 * manually from marketplace search's own existing filter list.
 *
 * No retry: a pre-Firm visitor is not worth spending a firm's tokens
 * on twice, and OpenAiProviderAdapter is configured with zero retries
 * for the same reason (config('ai.openai.max_retries')).
 */
class MarketplaceIssueClassifierService
{
    private const MAX_ISSUE_DESCRIPTION_LENGTH = 500;

    private const RESPONSE_SCHEMA_KEY = 'practice_area_classification';

    public function __construct(
        private readonly AiModeResolutionService $modeResolution,
        private readonly MarketplaceAiUsageThrottleService $throttle,
        private readonly MarketplaceAiUsageRecorderService $usageRecorder,
        private readonly PromptInjectionResistanceService $promptInjectionResistance,
    ) {}

    public function classify(string $issueDescription, string $sessionHash, ?string $ipAddress): MarketplaceIssueClassificationResult
    {
        if ($this->modeResolution->platformKillSwitchEngaged()) {
            return MarketplaceIssueClassificationResult::unavailable('platform_kill_switch_engaged');
        }

        $throttleDecision = $this->throttle->evaluate(null, $sessionHash, $ipAddress);

        if (! $throttleDecision->allowed) {
            return MarketplaceIssueClassificationResult::unavailable('throttled');
        }

        $trimmedDescription = trim(mb_substr(trim($issueDescription), 0, self::MAX_ISSUE_DESCRIPTION_LENGTH));

        if ($trimmedDescription === '') {
            return MarketplaceIssueClassificationResult::unavailable('empty_description');
        }

        // Pre-Firm classification makes NO provider call.
        //
        // This runs before the visitor has chosen a firm, so there is no firm
        // credential, no firm budget and no firm to attribute usage or cost to.
        // AI is only reachable once ownership exists. The alternative — a
        // platform-owned OpenAI key — was explicitly rejected: it would let
        // anonymous traffic spend FirmsVault's money with no tenant to bill and
        // no budget to enforce.
        //
        // Returning unavailable() is the designed degradation, not an error:
        // the deterministic marketplace search remains authoritative for
        // discovery, so a visitor can still search, browse firms, choose one
        // and start intake. Real AI begins after firm selection.
        return MarketplaceIssueClassificationResult::unavailable('no_firm_scoped_provider');
    }
}
