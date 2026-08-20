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

    /*
     * Deliberately turned off with no replacement.
     *
     * Distinct from Rotated, which implies a successor key exists. A firm that
     * revokes its credential has chosen to stop using AI, and the AI settings
     * page needs to say so truthfully rather than implying a rotation that
     * never happened.
     *
     * No migration: firm_ai_provider_keys.status is a plain string column.
     */
    case Revoked = 'revoked';
}
