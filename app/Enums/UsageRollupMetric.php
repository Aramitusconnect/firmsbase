<?php

namespace App\Enums;

/**
 * UsageRollupMetric — usage_rollups.metric. Exact 3 metrics named in
 * project rule 11 / PDF Phase 6 scope: AI, storage, seats.
 */
enum UsageRollupMetric: string
{
    case AiTokens = 'ai_tokens';
    case StorageBytes = 'storage_bytes';
    case SeatsActive = 'seats_active';
}
