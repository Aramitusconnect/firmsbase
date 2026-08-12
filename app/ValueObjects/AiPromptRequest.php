<?php

namespace App\ValueObjects;

use App\Enums\AiProvider;
use App\Enums\AiUsageActionType;

/**
 * AiPromptRequest — the entire input contract AiProviderAdapterInterface
 * implementations accept. `documentDerivedText` is carried as a
 * DISTINCT, separately-labeled field from `instructionText` specifically
 * so an adapter (and PromptInjectionResistanceService, which inspects
 * this object before it reaches any adapter) can enforce project rule
 * 17: document-derived text is data, never instructions. No adapter
 * implementation may treat documentDerivedText as anything other than
 * inert data to summarize/quote/analyze.
 *
 * Mission 3 (MyAttorney Conversion + AI Intake), checkpoint 5 —
 * responseSchemaKey is a trailing, nullable, backward-compatible
 * addition. When set, it names a key registered in
 * AiStructuredOutputSchemaRegistry that the caller expects the
 * adapter's response to conform to (AiProviderResponse::structuredOutput).
 * Deliberately just a KEY, not an inline schema — the registry, not
 * this value object, is the single source of truth for what shape any
 * given key means, mirroring AutomationFieldAllowlistRegistry's own
 * "one hand-authored registry, never an ad-hoc inline definition"
 * convention. Null (the default) means free-text output only, exactly
 * today's existing behavior for every pre-checkpoint-5 caller.
 */
final readonly class AiPromptRequest
{
    /**
     * @param  array<int>  $matterIds
     */
    public function __construct(
        public AiProvider $provider,
        public string $model,
        public AiUsageActionType $actionType,
        public string $instructionText,
        public ?string $documentDerivedText,
        public array $matterIds,
        public bool $allowToolActions = false,
        public ?string $responseSchemaKey = null,
    ) {}
}
