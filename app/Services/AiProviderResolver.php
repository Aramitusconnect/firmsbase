<?php

declare(strict_types=1);

namespace App\Services;

use App\Ai\OpenAi\OpenAiProviderAdapter;
use App\Enums\AiMode;
use App\Enums\AiProvider;
use App\Models\Firm;

/**
 * The one place an AI adapter is constructed.
 *
 * Before this existed, MarketplaceIntakeConversationalAssistantService resolved
 * AiProviderAdapterInterface from the container — which meant the runtime
 * adapter was a global binding rather than a property of the firm's own
 * configuration, and every firm silently shared whatever was bound. That is how
 * a fake adapter ended up serving a firm that had configured OpenAI.
 *
 * Resolution is now derived from the firm: its AI mode decides whether it
 * brings its own credential, and the credential decides whether an adapter can
 * be built at all. There is no fallback adapter — if a firm is not properly
 * configured, this returns null and the caller degrades to the deterministic
 * questionnaire. Returning a stand-in would be worse than returning nothing,
 * because the intake would appear AI-assisted while producing invented output.
 */
final readonly class AiProviderResolver
{
    public function __construct(
        private AiProviderKeyService $keys,
        private AiModeResolutionService $modeResolution,
    ) {}

    /**
     * The provider a firm is configured to use, or null when none applies.
     *
     * Only firm_owned is supported today: platform_managed would require a
     * platform credential, and FirmsVault deliberately holds none.
     */
    public function providerFor(Firm $firm): ?AiProvider
    {
        if ($this->modeResolution->resolve($firm) !== AiMode::FirmOwned) {
            return null;
        }

        // One implemented provider today. When a second adapter lands this
        // becomes a firm-level provider preference read from firm_ai_settings.
        return AiProvider::OpenAi;
    }

    /**
     * The model this firm should use.
     *
     * firm_ai_settings.allowed_models_json is an existing column, so a firm can
     * pin a model without a schema change. Falls back to the configured
     * default.
     */
    public function modelFor(Firm $firm): string
    {
        $allowed = $firm->aiSettings?->allowed_models_json;

        if (is_array($allowed) && $allowed !== []) {
            $first = reset($allowed);

            if (is_string($first) && $first !== '') {
                return $first;
            }
        }

        return (string) config('ai.openai.model');
    }

    /**
     * Build the adapter for this firm, or null if it cannot be built.
     *
     * Null means "no AI this turn" — never an exception, because an intake in
     * progress must not break on a configuration gap.
     */
    public function adapterFor(Firm $firm): ?AiProviderAdapterInterface
    {
        $provider = $this->providerFor($firm);

        if ($provider === null || ! $provider->isImplemented()) {
            return null;
        }

        $key = $this->keys->activeKeyFor($firm, $provider);

        if ($key === null) {
            return null;
        }

        // Decryption happens here and nowhere earlier: the plaintext lives only
        // for the lifetime of this adapter instance, which is one turn.
        $secret = $this->keys->keyMaterialFor($firm, $key);

        return new OpenAiProviderAdapter(
            apiKey: $secret,
            model: $this->modelFor($firm),
            baseUri: (string) config('ai.openai.base_uri'),
            timeoutSeconds: (int) config('ai.openai.timeout_seconds'),
            connectTimeoutSeconds: (int) config('ai.openai.connect_timeout_seconds'),
            maxOutputTokens: (int) config('ai.openai.max_output_tokens'),
        );
    }

    /**
     * Whether this firm has a usable credential, without building an adapter or
     * decrypting anything. Safe for rendering status in the UI.
     */
    public function hasActiveCredential(Firm $firm): bool
    {
        $provider = $this->providerFor($firm);

        return $provider !== null && $this->keys->activeKeyFor($firm, $provider) !== null;
    }
}
