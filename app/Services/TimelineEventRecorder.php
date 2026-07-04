<?php

namespace App\Services;

use App\Models\Firm;
use App\Models\TimelineEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * TimelineEventRecorder — the ONLY write path into timeline_events.
 * No other service should call TimelineEvent::create() directly; this
 * keeps event-logging centralized instead of scattered ad hoc across
 * every Phase 2+ service. event_type is a plain string (approved
 * decision) — see TimelineEvent's own doc comment for why.
 */
class TimelineEventRecorder
{
    public function record(
        Firm $firm,
        string $eventType,
        ?Model $subject = null,
        ?User $actor = null,
        array $metadata = [],
    ): TimelineEvent {
        return TimelineEvent::create([
            'firm_id' => $firm->id,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'event_type' => $eventType,
            'actor_type' => $actor ? User::class : null,
            'actor_id' => $actor?->id,
            'occurred_at' => now(),
            'metadata_json' => $metadata,
        ]);
    }
}
