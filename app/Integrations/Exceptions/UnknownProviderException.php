<?php

declare(strict_types=1);

namespace App\Integrations\Exceptions;

/**
 * UnknownProviderException — thrown by ProviderRegistry::get() when
 * asked to resolve a ProviderKey that has no entry in
 * config('integrations.providers'). The message intentionally
 * includes only the offending provider key value, never any internal
 * class name or file path, so it is safe to surface in logs or (in a
 * later checkpoint) to an operator-facing error without leaking
 * implementation detail.
 */
final class UnknownProviderException extends \RuntimeException
{
    public function __construct(string $providerKey)
    {
        parent::__construct(sprintf('Unknown integration provider key: "%s".', $providerKey));
    }
}
