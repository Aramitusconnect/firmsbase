<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * EmailMessageLink — the email-to-client/matter association row. At
 * least one of matter_id/client_id must be set; enforced by
 * EmailMessageLinkingService, not a DB constraint. No uuid — a join
 * row, not an independently-referenced workflow record.
 */
class EmailMessageLink extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'firm_id',
        'email_message_id',
        'matter_id',
        'client_id',
        'linked_by_firm_user_id',
        'is_primary',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function emailMessage(): BelongsTo
    {
        return $this->belongsTo(EmailMessage::class);
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function linkedByFirmUser(): BelongsTo
    {
        return $this->belongsTo(FirmUser::class, 'linked_by_firm_user_id');
    }
}
