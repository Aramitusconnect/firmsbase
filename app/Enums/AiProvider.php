<?php

namespace App\Enums;

/**
 * AiProvider — firm_ai_provider_keys.provider / firm_ai_settings
 * allowed_providers_json entries / ai_usage_events.provider.
 *
 * A closed configuration vocabulary that is WIDER than what actually
 * works: only OpenAi has an adapter behind it today (see
 * OpenAiProviderAdapter). implemented()/isImplemented() below is the
 * authoritative answer to "can this value actually be used", and
 * AiProviderKeyService::import() refuses anything else rather than
 * storing a credential for a provider nothing can call.
 *
 * The other cases stay because historical rows may carry them and
 * because adding an adapter later should not require an enum change.
 */
enum AiProvider: string
{
    case OpenAi = 'openai';
    case Anthropic = 'anthropic';
    case Google = 'google';
    case AzureOpenAi = 'azure_openai';
    case AwsBedrock = 'aws_bedrock';

    /**
     * The providers this release can actually talk to.
     *
     * The enum lists five identities because the domain intends to support
     * them; only OpenAI has an adapter today. Anything that offers a provider
     * to a firm — credential creation, the AI settings dropdown, Test
     * Connection, adapter resolution — must gate on this rather than on the
     * full case list, so the UI cannot advertise a provider that would fail on
     * first use.
     *
     * There is deliberately no Fake/Simulated case: the application no longer
     * contains a fake provider, and tests mock the HTTP boundary instead.
     *
     * @return list<self>
     */
    public static function implemented(): array
    {
        return [self::OpenAi];
    }

    /**
     * True when a firm may store a credential for this provider and have it
     * used for real. Kept as one method so the check is not re-derived at each
     * call site.
     */
    public function isImplemented(): bool
    {
        return in_array($this, self::implemented(), true);
    }
}
