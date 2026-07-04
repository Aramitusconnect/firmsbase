<?php

namespace App\Models;

use App\Enums\MaintenanceWindowStatus;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MaintenanceWindow — firm_id is NULLABLE (null = platform-wide
 * window); deliberately does NOT use BelongsToTenant for the same
 * reason as HealthCheck/BackupRestoreTest/IncidentEvent. Carries a
 * public uuid (approved conservative-uuid-scope pattern). Rescheduling
 * creates a NEW row via MaintenanceWindowService::reschedule() and
 * marks THIS row Cancelled with rescheduled_from_id pointing back —
 * never mutates an already-scheduled window's own dates in place
 * (mirrors Phase 3's PaymentPlan renegotiate() pattern).
 */
class MaintenanceWindow extends Model
{
    use HasFactory, HasPublicUuid;

    protected $fillable = [
        'firm_id',
        'title',
        'status',
        'scheduled_starts_at',
        'scheduled_ends_at',
        'actual_starts_at',
        'actual_ends_at',
        'affected_components',
        'public_message',
        'private_message',
        'customer_notification_sent_at',
        'rescheduled_from_id',
        'cancelled_at',
        'cancellation_reason',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => MaintenanceWindowStatus::class,
            'scheduled_starts_at' => 'datetime',
            'scheduled_ends_at' => 'datetime',
            'actual_starts_at' => 'datetime',
            'actual_ends_at' => 'datetime',
            'affected_components' => 'array',
            'customer_notification_sent_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function rescheduledFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'rescheduled_from_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function customerNotificationSent(): bool
    {
        return ! is_null($this->customer_notification_sent_at);
    }
}
