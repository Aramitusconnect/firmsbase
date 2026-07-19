<?php

declare(strict_types=1);

namespace App\Integrations\Enums;

/**
 * AuthMethod — the closed set of authentication mechanisms a provider
 * may declare via IntegrationProviderContract::supportedAuthMethods().
 * Purely descriptive/documentation-level at Checkpoint 1: no OAuth
 * route, credential table, or live provider exists yet
 * (checkpoint-00-final-specification.md §21).
 */
enum AuthMethod: string
{
    case OAuth2 = 'oauth2';
    case ApiKey = 'api_key';
    case None = 'none';
}
