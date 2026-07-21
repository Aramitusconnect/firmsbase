<?php

declare(strict_types=1);

namespace App\Integrations\Enums;

/**
 * ConnectionStatus — lifecycle state of a firm's connection to a
 * provider. Defined now (Checkpoint 1) purely as a vocabulary-level
 * enum for use by ProviderMetadata/contracts; the `firm_integrations`
 * table that will persist this state is out of scope until Checkpoint 3
 * (checkpoint-00-final-specification.md §5, §7, §21).
 *
 * Checkpoint 5 addition: `ReauthorizationRequired`, the ONE new case
 * approved by Agent H's review (item 13 / naming reconciliation;
 * frozen-design-post-review.md item 13) out of the ten candidate states
 * considered — every other candidate was either already covered by an
 * existing case or not justified for this checkpoint, and none were
 * added speculatively. Set exclusively by
 * App\Integrations\Services\ProviderConnectionService (the sole writer
 * of firm_integrations.status) when a token refresh fails with an
 * invalid/expired refresh token, or when a callback's account-mismatch
 * check rejects a reauthorization attempt.
 */
enum ConnectionStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case ScopeInsufficient = 'scope_insufficient';
    case Disconnected = 'disconnected';
    case Error = 'error';
    case ReauthorizationRequired = 'reauthorization_required';
}
