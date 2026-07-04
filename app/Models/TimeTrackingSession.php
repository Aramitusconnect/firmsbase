<?php

namespace App\Models;

use App\Enums\TimeTrackingSessionStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * TimeTrackingSession — timer mechanics only; all state transitions
 * (start/pause/resume/stop) live in TimeTrackingService, never here.
 * No uuid — internal only. timeEntry() looks up the entry generated
 * when this session was stopped (time_entries.time_tracking_session_id
 * points back here — this table has no reverse FK column).
 */
class TimeTrackingSession extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'firm_id',
        'user_id',
        'matter_id',
        'client_id',
        'status',
        'started_at',
        'accumulated_seconds',
        'last_resumed_at',
        'ended_at',
        'total_seconds',
        'is_billable',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'status' => TimeTrackingSessionStatus::class,
            'started_at' => 'datetime',
            'last_resumed_at' => 'datetime',
            'ended_at' => 'datetime',
            'is_billable' => 'boolean',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function timeEntry(): HasOne
    {
        return $this->hasOne(TimeEntry::class);
    }

    public function isActive(): bool
    {
        return $this->status === TimeTrackingSessionStatus::Active;
    }
}
