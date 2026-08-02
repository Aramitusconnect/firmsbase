<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * SesEventReceipt — see the creating migration's own docblock. The
 * durable idempotency ledger for inbound SES/SNS events.
 * SesEventConsumerService is the only writer and reader.
 */
class SesEventReceipt extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'idempotency_key',
        'event_type',
        'ses_message_id',
        'sqs_message_id',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'processed_at' => 'datetime',
        ];
    }
}
