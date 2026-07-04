<?php

namespace App\Models;

use App\Enums\ConsentChannel;
use App\Enums\NotificationEventStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * NotificationEvent — append-only; each row is a single point-in-time
 * event, not a mutable multi-state record. NotificationDispatchService
 * is the only place these rows are created. correlation_id ties
 * together the attempted/queued/sent/bounced rows of one logical
 * notification.
 */
class NotificationEvent extends Model
{
    use HasFactory;

    const UPDATED_AT = null;

    protected $fillable = [
        'firm_id',
        'notification_template_id',
        'client_id',
        'matter_id',
        'correlation_id',
        'channel',
        'recipient',
        'status',
        'reason',
        'subject_type',
        'subject_id',
    ];

    protected function casts(): array
    {
        return [
            'channel' => ConsentChannel::class,
            'status' => NotificationEventStatus::class,
            'created_at' => 'datetime',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function notificationTemplate(): BelongsTo
    {
        return $this->belongsTo(NotificationTemplate::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
