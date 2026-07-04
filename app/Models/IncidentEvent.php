<?php

namespace App\Models;

use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * IncidentEvent — append-only. correlation_id ties every row for one
 * incident together — there is no separate "incidents" parent table
 * (see this table's migration doc comment). firm_id is NULLABLE (null
 * = platform-wide incident); deliberately does NOT use BelongsToTenant
 * for the same reason as HealthCheck/BackupRestoreTest. event_type is
 * a plain string (approved clarification); severity/status are strict
 * enums carried on every row (the current value is always "the latest
 * row for this correlation_id").
 */
class IncidentEvent extends Model
{
    use HasFactory;

    const UPDATED_AT = null;

    protected $fillable = [
        'firm_id',
        'correlation_id',
        'event_type',
        'severity',
        'status',
        'customer_impact',
        'notification_needed',
        'root_cause',
        'resolution',
        'message',
        'actor_user_id',
    ];

    protected function casts(): array
    {
        return [
            'severity' => IncidentSeverity::class,
            'status' => IncidentStatus::class,
            'customer_impact' => 'boolean',
            'notification_needed' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function actorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function isResolved(): bool
    {
        return $this->status === IncidentStatus::Resolved;
    }
}
