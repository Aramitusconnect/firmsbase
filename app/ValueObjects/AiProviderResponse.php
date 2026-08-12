<?php

namespace App\ValueObjects;

/**
 * AiProviderResponse — the entire output contract
 * AiProviderAdapterInterface implementations return.
 * `requestedToolActions` is always empty unless the adapter explicitly
 * decided to request a tool action from its OWN instruction handling —
 * PromptInjectionResistanceService's test surface asserts that content
 * found only inside documentDerivedText can never cause a non-empty
 * requestedToolActions array.
 *
 * Mission 3 (MyAttorney Conversion + AI Intake), checkpoint 5 —
 * structuredOutput is populated only when the originating
 * AiPromptRequest carried a responseSchemaKey; null otherwise (every
 * pre-checkpoint-5 caller). A non-null value is NOT presumed valid
 * merely by being present — callers must still run it through
 * AiStructuredOutputSchemaRegistry::validate() before trusting or
 * persisting it (e.g. into MarketplaceIntake::structured_data). This
 * value object only carries what the adapter claims; it enforces
 * nothing about its own shape.
 */
final readonly class AiProviderResponse
{
    /**
     * @param  array<string>  $requestedToolActions
     * @param  array<string, mixed>|null  $structuredOutput
     */
    public function __construct(
        public string $outputText,
        public int $tokensIn,
        public int $tokensOut,
        public array $requestedToolActions = [],
        public ?array $structuredOutput = null,
    ) {}
}
