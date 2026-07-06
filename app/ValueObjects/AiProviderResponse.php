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
 */
final readonly class AiProviderResponse
{
    /**
     * @param  array<string>  $requestedToolActions
     */
    public function __construct(
        public string $outputText,
        public int $tokensIn,
        public int $tokensOut,
        public array $requestedToolActions = [],
    ) {
    }
}
