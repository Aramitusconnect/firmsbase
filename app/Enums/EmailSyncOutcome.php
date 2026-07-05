<?php

namespace App\Enums;

/**
 * EmailSyncOutcome — email_sync_events.outcome. Blocked is distinct
 * from Failed: Blocked means the sync did not run because policy
 * intentionally disallowed it (approved correction — storage_mode
 * Disabled blocks capture entirely), which is an expected, non-error
 * outcome; Failed means a sync attempt was permitted but something
 * went wrong. NotApplicable covers non-sync event types (e.g.
 * AccountConnected) where an outcome column value is required by the
 * schema but does not semantically apply.
 */
enum EmailSyncOutcome: string
{
    case Success = 'success';
    case Failed = 'failed';
    case PartialFailure = 'partial_failure';
    case Blocked = 'blocked';
    case NotApplicable = 'not_applicable';
}
