<?php

namespace App\Models;

use App\Enums\SignatureRecipientType;
use App\Enums\SignatureRequestStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * SignatureRequestRecipient — one row per signer. status reuses
 * SignatureRequestStatus verbatim (see that enum's docblock).
 * text_version/consented_at are a cache of the Phase 6-compatible
 * consent evidence; the authoritative, immutable record is the
 * consent_captured SignatureEvent row.
 */
class SignatureRequestRecipient extends Model
{
    use HasFactory, HasPublicUuid, BelongsToTenant;

    protected $fillable = [
        'signature_request_id',
        'firm_id',
        'recipient_type',
        'client_id',
        'contact_id',
        'party_id',
        'recipient_firm_user_id',
        'signer_name',
        'signer_email',
        'status',
        'text_version',
        'viewed_at',
        'consented_at',
        'signed_at',
        'declined_at',
        'declined_reason',
        'expires_at',
        'voided_at',
        'access_token_hash',
    ];

    protected $hidden = [
        'access_token_hash',
    ];

    protected function casts(): array
    {
        return [
            'recipient_type' => SignatureRecipientType::class,
            'status' => SignatureRequestStatus::class,
            'viewed_at' => 'datetime',
            'consented_at' => 'datetime',
            'signed_at' => 'datetime',
            'declined_at' => 'datetime',
            'expires_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function signatureRequest(): BelongsTo
    {
        return $this->belongsTo(SignatureRequest::class);
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function recipientFirmUser(): BelongsTo
    {
        return $this->belongsTo(FirmUser::class, 'recipient_firm_user_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(SignatureEvent::class);
    }

    public function hasConsented(): bool
    {
        return $this->consented_at !== null;
    }
}
