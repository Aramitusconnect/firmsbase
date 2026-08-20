<?php

declare(strict_types=1);

/*
 * Canonical AI provider configuration.
 *
 * The model is configured here rather than scattered through domain services:
 * MarketplaceIntakeConversationalAssistantService previously hardcoded both the
 * provider and the model at its AiPromptRequest call site, which is how a fake
 * adapter came to report `provider: openai` in the usage ledger.
 *
 * A firm may override the model through firm_ai_settings.allowed_models_json
 * (an existing column) — no schema change is required to select a model.
 */
return [
    /*
     * The default OpenAI model for intake extraction.
     *
     * gpt-5.6-terra balances reasoning quality against cost, which suits
     * legal-intake questioning where a misread answer is worse than a slightly
     * higher token price. gpt-5.6-luna is the cheaper high-volume tier and is a
     * reasonable switch once extraction quality is measured in staging.
     *
     * Access is NOT assumed: AiProviderConnectionTester proves the configured
     * model is reachable for the firm's own credential before anything claims
     * it works.
     */
    'openai' => [
        'model' => env('OPENAI_MODEL', 'gpt-5.6-terra'),
        'base_uri' => env('OPENAI_BASE_URI', 'https://api.openai.com/v1'),
        'timeout_seconds' => (int) env('OPENAI_TIMEOUT_SECONDS', 20),
        'connect_timeout_seconds' => (int) env('OPENAI_CONNECT_TIMEOUT_SECONDS', 5),

        /*
         * Deliberately 0. A retry on a Responses API call is a second billable
         * request, and the intake already degrades to the deterministic
         * questionnaire on failure — retrying buys a marginally better chance
         * of an AI turn at the cost of unbounded duplicate spend. Raise this
         * only alongside idempotency keys.
         */
        'max_retries' => (int) env('OPENAI_MAX_RETRIES', 0),

        /*
         * Hard ceiling on a single intake turn's output.
         *
         * An intake extraction returns one question_code and one extracted
         * value — hundreds of tokens, not thousands. Capping it does two
         * things: it stops a runaway generation costing real money, and it
         * makes the pre-call budget reservation below meaningful, because the
         * worst case is now a known number rather than "whatever the model
         * decides". This is NOT sized for long-form drafting.
         */
        'max_output_tokens' => (int) env('OPENAI_MAX_OUTPUT_TOKENS', 512),

        /*
         * Characters per token, used only to estimate a pending request's
         * input size before it is sent. Deliberately conservative (a low
         * divisor over-estimates tokens); it is an estimate and the code says
         * so wherever it is used.
         */
        'estimated_chars_per_token' => (int) env('OPENAI_ESTIMATED_CHARS_PER_TOKEN', 3),
    ],
];
