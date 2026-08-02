<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NotificationEventStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * PlatformNotificationSuppression — see the creating migration's own
 * docblock. The platform-scope analogue of a suppressed recipient,
 * independent of SuppressionService/notification_events (which
 * requires a resolved firm this path exists because one could not be
 * found). One row per suppressed recipient_fingerprint — current
 * state, not an event log. PlatformNotificationCorrelationService is
 * the only writer and reader.
 */
class PlatformNotificationSuppression extends Model
{
    protected $fillable = [
        'recipient_fingerprint',
        'status',
        'correlation_id',
        'reason',
        'suppressed_at',
    ];

    protected $hidden = [
        'recipient_fingerprint',
    ];

    protected function casts(): array
    {
        return [
            'status' => NotificationEventStatus::class,
            'suppressed_at' => 'datetime',
        ];
    }
}
