<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TimelineEvent — append-only. event_type is a plain string (approved
 * decision), not a closed enum — this table spans every future phase.
 * TimelineEventRecorder is the single write path; no other service
 * should insert rows here directly.
 *
 * Carries HasPublicUuid: individual timeline events are expected to be
 * exposed later in matter activity feeds, client portal activity,
 * notifications, APIs, and admin review screens — the internal bigint
 * id must never be the public identifier for those surfaces. This
 * differs from SecurityEvent, which stays internal-only and has no
 * uuid.
 */
class TimelineEvent extends Model
{
    use HasFactory, BelongsToTenant, HasPublicUuid;

    const UPDATED_AT = null;

    protected $fillable = [
        'firm_id',
        'subject_type',
        'subject_id',
        'event_type',
        'actor_type',
        'actor_id',
        'occurred_at',
        'metadata_json',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'metadata_json' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function subject(): \Illuminate\Database\Eloquent\Relations\MorphTo
    {
        return $this->morphTo();
    }
}
