<?php

namespace App\Services;

use App\Models\DataProcessingRecord;
use App\Models\PlatformAdmin;
use App\Models\RetentionPolicy;

/**
 * DataProcessingRecordService — informational processing-activity
 * register (approved decision #7). No external call; no compliance
 * claim beyond recorded metadata.
 */
class DataProcessingRecordService
{
    public function record(array $attributes, PlatformAdmin $recordedBy): DataProcessingRecord
    {
        return DataProcessingRecord::create(array_merge($attributes, [
            'recorded_by_platform_admin_id' => $recordedBy->id,
            'recorded_at' => now(),
        ]));
    }

    public function linkRetentionPolicy(DataProcessingRecord $record, RetentionPolicy $policy): DataProcessingRecord
    {
        $record->update(['retention_policy_id' => $policy->id]);

        return $record->fresh();
    }

    public function retire(DataProcessingRecord $record): DataProcessingRecord
    {
        $record->update(['status' => \App\Enums\DataProcessingRecordStatus::Retired]);

        return $record->fresh();
    }
}
