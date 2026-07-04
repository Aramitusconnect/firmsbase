<?php

namespace App\Models;

use App\Enums\CalendarEventType;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * CalendarEvent — subject() is a real morphTo(), same pattern as
 * Phase 2's TimelineEvent::subject(). No external Google/Outlook sync
 * (out of phase) — purely FirmsBase's own internal calendar. No uuid —
 * internal/staff-facing only in Phase 4.
 */
class CalendarEvent extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'firm_id',
        'matter_id',
        'event_type',
        'subject_type',
        'subject_id',
        'title',
        'starts_at',
        'ends_at',
        'all_day',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'event_type' => CalendarEventType::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'all_day' => 'boolean',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
