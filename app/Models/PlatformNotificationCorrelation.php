<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * PlatformNotificationCorrelation — see the creating migration's own
 * docblock. Deliberately NOT RLS-protected (see that migration).
 * PlatformNotificationCorrelationService is the only writer;
 * SesEventConsumerService is the only reader for inbound resolution.
 *
 * recipient_fingerprint is a keyed HMAC, never plaintext — see
 * PlatformNotificationCorrelationService's own docblock. $hidden below
 * is defense in depth in case this model is ever serialized (it
 * shouldn't be — no Filament resource exists for it).
 */
class PlatformNotificationCorrelation extends Model
{
    protected $fillable = [
        'correlation_id',
        'account_type',
        'account_id',
        'notification_type',
        'recipient_fingerprint',
        'provider_message_id',
    ];

    protected $hidden = [
        'recipient_fingerprint',
    ];

    public function account(): MorphTo
    {
        return $this->morphTo();
    }
}
