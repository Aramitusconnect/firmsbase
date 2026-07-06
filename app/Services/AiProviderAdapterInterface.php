<?php

namespace App\Services;

use App\ValueObjects\AiPromptRequest;
use App\ValueObjects\AiProviderResponse;

/**
 * AiProviderAdapterInterface — the entire contract a "provider"
 * implements. Phase 15 registers ONLY FakeAiProviderAdapter against
 * this interface in the container. No implementation in this phase
 * may perform real HTTP, SDK, OAuth, DNS, curl, Guzzle, fsockopen, or
 * any other network behavior — a future, separately-approved
 * provider-integration phase would add real adapters (OpenAiAdapter,
 * AnthropicAdapter, etc.) behind this same interface without changing
 * any call site.
 */
interface AiProviderAdapterInterface
{
    public function generate(AiPromptRequest $request): AiProviderResponse;
}
