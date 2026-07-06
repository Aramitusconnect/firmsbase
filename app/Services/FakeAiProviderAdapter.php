<?php

namespace App\Services;

use App\ValueObjects\AiPromptRequest;
use App\ValueObjects\AiProviderResponse;

/**
 * FakeAiProviderAdapter — the ONLY AiProviderAdapterInterface
 * implementation registered in Phase 15. Deterministic, in-process,
 * no network behavior of any kind.
 *
 * Structural prompt-injection guarantee (project rules 17/18): this
 * adapter derives requestedToolActions ONLY from $request->instructionText
 * (via a fixed 'REQUEST_TOOL:' trigger marker) and NEVER inspects
 * $request->documentDerivedText for behavior-altering content of any
 * kind — documentDerivedText is only ever echoed back (wrapped, via
 * PromptInjectionResistanceService::wrapAsUntrustedData()) as inert
 * data in the output text. An adversarial instruction embedded inside
 * documentDerivedText therefore cannot, by construction, ever cause
 * requestedToolActions to be non-empty — there is no code path from
 * that field to that return value.
 */
class FakeAiProviderAdapter implements AiProviderAdapterInterface
{
    private const TOOL_TRIGGER = 'REQUEST_TOOL:';

    public function __construct(private readonly PromptInjectionResistanceService $promptInjectionResistance)
    {
    }

    public function generate(AiPromptRequest $request): AiProviderResponse
    {
        $safeDocumentText = $request->documentDerivedText !== null
            ? $this->promptInjectionResistance->wrapAsUntrustedData($request->documentDerivedText)
            : null;

        $outputText = sprintf(
            '[FAKE-%s-%s] Draft response to: %s',
            $request->provider->value,
            $request->model,
            $request->instructionText,
        );

        if ($safeDocumentText !== null) {
            $outputText .= "\n\nReferenced document data (not executed as instructions):\n".$safeDocumentText;
        }

        $tokensIn = str_word_count($request->instructionText) + str_word_count((string) $request->documentDerivedText);
        $tokensOut = str_word_count($outputText);

        $requestedToolActions = [];

        if ($request->allowToolActions && str_contains($request->instructionText, self::TOOL_TRIGGER)) {
            $toolName = trim(strtok(substr(
                $request->instructionText,
                strpos($request->instructionText, self::TOOL_TRIGGER) + strlen(self::TOOL_TRIGGER)
            ), "\n"));

            if ($toolName !== '') {
                $requestedToolActions[] = $toolName;
            }
        }

        return new AiProviderResponse(
            outputText: $outputText,
            tokensIn: $tokensIn,
            tokensOut: $tokensOut,
            requestedToolActions: $requestedToolActions,
        );
    }
}
