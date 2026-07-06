<?php

namespace App\Enums;

/**
 * AiProvider — firm_ai_provider_keys.provider / firm_ai_settings
 * allowed_providers_json entries / ai_usage_events.provider. Phase 15
 * defines this as a closed configuration vocabulary only — no case
 * here has a real adapter behind it. Only FakeAiProviderAdapter is
 * registered in the container this phase, regardless of which
 * provider value is configured. A future, separately-approved
 * provider-integration phase would add real adapters keyed off these
 * same values without changing this enum.
 */
enum AiProvider: string
{
    case OpenAi = 'openai';
    case Anthropic = 'anthropic';
    case Google = 'google';
    case AzureOpenAi = 'azure_openai';
    case AwsBedrock = 'aws_bedrock';
}
