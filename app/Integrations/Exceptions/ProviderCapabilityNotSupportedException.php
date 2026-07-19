<?php

declare(strict_types=1);

namespace App\Integrations\Exceptions;

use App\Integrations\Enums\ProviderKey;

/**
 * ProviderCapabilityNotSupportedException — for defensive use by a
 * future checkpoint's orchestrator services (e.g.
 * ProviderConnectionService, SyncOrchestratorService): thrown when
 * calling code has already checked `instanceof` against a required
 * `Supports*` capability contract and found the resolved provider does
 * not implement it. Not used within Checkpoint 1 itself (no
 * orchestrator service exists yet), but the type must exist now per
 * checkpoint-00-final-specification.md §21 so later checkpoints have a
 * stable, shared exception type rather than each inventing its own.
 *
 * The message includes only the provider key value and the short
 * capability-contract name (e.g. "SupportsOAuthContract"), never an
 * internal fully-qualified class path.
 */
final class ProviderCapabilityNotSupportedException extends \RuntimeException
{
    public function __construct(ProviderKey $providerKey, string $capability)
    {
        parent::__construct(sprintf(
            'Provider "%s" does not support the "%s" capability.',
            $providerKey->value,
            $capability,
        ));
    }
}
