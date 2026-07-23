<?php

declare(strict_types=1);

namespace App\Integrations\Enums;

/**
 * UsageOperationType — a small, non-exhaustive vocabulary aid for
 * `integration_usage_records.operation_type` (Checkpoint 9, frozen
 * design §1.1/§2). This is deliberately NOT a DB-level enum and this
 * table's `operation_type` column is a plain governed string, never
 * cast to this enum at the model layer — mirrors
 * `integration_sync_runs.resource_type`'s established precedent
 * exactly (see that table's own create migration docblock): a closed
 * PHP/DB enum would force a core-framework migration every time a
 * future provider/capability introduces a new operation shape, so
 * `IntegrationUsageRecorderService::recordOnce()` accepts
 * `operation_type` as a plain string, not this enum directly.
 *
 * The six cases below are copied verbatim from the existing, already-
 * reviewed `App\Integrations\Data\SanitizedHealthDiagnostic::OPERATION_*`
 * closed vocabulary (Checkpoint 8) — the same six operation shapes this
 * codebase already tracks for health diagnostics are the natural
 * starting vocabulary for usage evidence too, since both describe "what
 * kind of operation against a provider connection just happened."
 * Reusing the identical vocabulary (rather than inventing a second,
 * slightly-different one) avoids two near-duplicate closed lists
 * drifting apart over time.
 */
enum UsageOperationType: string
{
    case HealthCheck = 'health_check';
    case TokenRefresh = 'token_refresh';
    case PullSync = 'pull_sync';
    case PushSync = 'push_sync';
    case WebhookProcess = 'webhook_process';
    case OutboxDispatch = 'outbox_dispatch';
}
