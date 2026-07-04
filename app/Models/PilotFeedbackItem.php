<?php

namespace App\Models;

use App\Enums\PilotFeedbackCategory;
use App\Enums\PilotFeedbackPriority;
use App\Enums\PilotFeedbackSource;
use App\Enums\PilotFeedbackStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PilotFeedbackItem — firm_id/client_id/matter_id/user_id all
 * nullable (internal-source feedback may be tied to none of them).
 * Deliberately does NOT use BelongsToTenant for the same reason as
 * HealthCheck/BackupRestoreTest/IncidentEvent/MaintenanceWindow — a
 * nullable firm_id would be hidden by that trait's global scope
 * whenever a tenant context is active. No uuid — internal triage tool
 * only.
 */
class PilotFeedbackItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'firm_id',
        'client_id',
        'matter_id',
        'user_id',
        'source',
        'category',
        'priority',
        'status',
        'title',
        'description',
        'resolution_notes',
        'follow_up_required',
        'follow_up_at',
        'resolved_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'source' => PilotFeedbackSource::class,
            'category' => PilotFeedbackCategory::class,
            'priority' => PilotFeedbackPriority::class,
            'status' => PilotFeedbackStatus::class,
            'follow_up_required' => 'boolean',
            'follow_up_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isResolved(): bool
    {
        return $this->status === PilotFeedbackStatus::Resolved;
    }
}
