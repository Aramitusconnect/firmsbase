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
    ) {
    }
}
