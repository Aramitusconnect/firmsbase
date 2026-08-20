<?php

namespace App\Services;

use App\ValueObjects\AiPromptRequest;
use App\ValueObjects\AiProviderResponse;

/**
 * AiProviderAdapterInterface — the entire contract a "provider"
 * implements.
 *
 * Deliberately NOT bound in the container. An adapter is built per firm
 * by AiProviderResolver from that firm's own mode and credential, so
 * that a container binding can never make one firm's configuration
 * serve another's request — which is exactly how a stand-in adapter
 * once ended up answering for a firm that had configured OpenAI. A
 * second provider adds a class here and a branch in the resolver,
 * without changing any call site.
 */
interface AiProviderAdapterInterface
{
    public function generate(AiPromptRequest $request): AiProviderResponse;
}
