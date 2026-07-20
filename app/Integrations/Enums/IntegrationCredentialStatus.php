<?php

declare(strict_types=1);

namespace App\Integrations\Enums;

/**
 * IntegrationCredentialStatus — lifecycle state of an
 * `integration_credentials` row (Checkpoint 4,
 * checkpoint-00-final-specification.md §5/§10). Mirrors
 * AiProviderKeyStatus/WebhookSecretStatus's exact convention: old
 * credentials are rotated or revoked, never deleted — the database's
 * partial unique index
 * (integration_credentials_one_active_per_connection_and_type) enforces
 * "one active credential per connection per credential_type" even if
 * application logic were ever bypassed.
 */
enum IntegrationCredentialStatus: string
{
    case Active = 'active';
    case Rotated = 'rotated';
    case Revoked = 'revoked';
}
