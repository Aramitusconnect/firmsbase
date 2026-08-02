<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ConsentChannel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * NotificationProviderCorrelation — see the creating migration's own
 * docblock for the full rationale. Deliberately NOT RLS-protected (see
 * that migration). OutboundMailCorrelationService is the only writer;
 * SesEventConsumerService is the only reader for inbound resolution.
 */
class NotificationProviderCorrelation extends Model
{
    use HasFactory;

    protected $fillable = [
        'correlation_id',
        'firm_id',
        'channel',
        'recipient_normalized',
        'provider_message_id',
    ];

    protected function casts(): array
    {
        return [
            'channel' => ConsentChannel::class,
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }
}
