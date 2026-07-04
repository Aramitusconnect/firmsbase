<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CommunicationConsentEvent — append-only audit trail. UPDATED_AT
 * disabled, no uuid, rows never mutated after insert.
 */
class CommunicationConsentEvent extends Model
{
    use HasFactory, BelongsToTenant;

    const UPDATED_AT = null;

    protected $fillable = [
        'communication_consent_id',
        'firm_id',
        'action',
        'previous_status',
        'new_status',
        'consent_text_version',
        'actor_user_id',
        'source',
        'metadata_json',
    ];

    protected function casts(): array
    {
        return [
            'metadata_json' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function communicationConsent(): BelongsTo
    {
        return $this->belongsTo(CommunicationConsent::class);
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
