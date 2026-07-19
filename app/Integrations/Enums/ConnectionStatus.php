<?php

declare(strict_types=1);

namespace App\Integrations\Enums;

/**
 * ConnectionStatus — lifecycle state of a firm's connection to a
 * provider. Defined now (Checkpoint 1) purely as a vocabulary-level
 * enum for use by ProviderMetadata/contracts; the `firm_integrations`
 * table that will persist this state is out of scope until Checkpoint 3
 * (checkpoint-00-final-specification.md §5, §7, §21).
 */
enum ConnectionStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case ScopeInsufficient = 'scope_insufficient';
    case Disconnected = 'disconnected';
    case Error = 'error';
}
