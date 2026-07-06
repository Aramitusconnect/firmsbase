<?php

namespace App\Enums;

/**
 * AiProviderKeyStatus — firm_ai_provider_keys.status. Mirrors
 * WebhookSecretStatus exactly (project rule 6 / approved decision:
 * rotated keys are never deleted, only marked Rotated, so historical
 * ai_usage_events.encryption_key_id references stay explainable).
 */
enum AiProviderKeyStatus: string
{
    case Active = 'active';
    case Rotated = 'rotated';
}
