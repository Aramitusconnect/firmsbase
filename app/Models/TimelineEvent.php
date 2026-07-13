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

    /**
     * Section 39A-3L Phase B6 fast-follow — append-only enforcement
     * guard, mirroring TrustLedgerEntry::booted()/SecurityEvent::booted()
     * exactly. timeline_events_tenant_isolation is a single FOR ALL
     * policy sharing one USING/WITH CHECK expression, so a stray
     * UPDATE/DELETE from the row's own tenant context would actually
     * SUCCEED at the RLS layer rather than no-op — this app-layer guard
     * is the real enforcement against a mistaken mutation of an existing
     * row.
     */
    protected static function booted(): void
    {
        static::updating(function () {
            throw new \LogicException(
                'timeline_events is append-only: an existing row can never be updated.'
            );
        });

        static::deleting(function () {
            throw new \LogicException(
                'timeline_events is append-only: an existing row can never be deleted.'
            );
        });
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
