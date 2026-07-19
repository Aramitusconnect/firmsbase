<?php

declare(strict_types=1);

namespace App\Integrations\Enums;

/**
 * SyncDirection — describes which way data flows for a given resource
 * type/provider combination. Vocabulary-level only at Checkpoint 1; the
 * `integration_sync_runs`/`integration_sync_items` tables that will
 * persist this are out of scope until Checkpoint 6
 * (checkpoint-00-final-specification.md §5, §7, §21).
 */
enum SyncDirection: string
{
    case Inbound = 'inbound';
    case Outbound = 'outbound';
    case Bidirectional = 'bidirectional';
}
