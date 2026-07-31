<?php

declare(strict_types=1);

namespace App\Enums;

use App\Integrations\Enums\ProviderOperationAttemptState;

/**
 * ProviderOperationReconciliationOutcome — Checkpoint 8.2
 * (§A-reconciliation). The closed set of audit action categories a
 * Platform Admin reconciliation action can record — never a free-form
 * string, matching the same closed-vocabulary discipline
 * `SanitizedSyncFailureSummary`'s `VALID_CATEGORIES` already establishes
 * for this codebase's other audit/redaction value objects.
 */
enum ProviderOperationReconciliationOutcome: string
{
    case ConfirmSucceeded = 'confirm_provider_succeeded';
    case ConfirmSucceededDenied = 'confirm_provider_succeeded_denied';
    case AuthorizeRetry = 'authorize_retry';
    case AuthorizeRetryDenied = 'authorize_retry_denied';
    case ResolveWithoutRetry = 'resolve_without_retry';
    case ResolveWithoutRetryDenied = 'resolve_without_retry_denied';

    /** The `provider_operation_attempts.attempt_state` this outcome results in, for audit-trail readability only — never re-derived from this value in production logic. */
    public function resultingState(): string
    {
        return match ($this) {
            self::ConfirmSucceeded, self::ResolveWithoutRetry => ProviderOperationAttemptState::LocalProcessingComplete->value,
            self::AuthorizeRetry => ProviderOperationAttemptState::RetryAllowed->value,
            self::ConfirmSucceededDenied, self::AuthorizeRetryDenied, self::ResolveWithoutRetryDenied => ProviderOperationAttemptState::ReconciliationRequired->value,
        };
    }
}
