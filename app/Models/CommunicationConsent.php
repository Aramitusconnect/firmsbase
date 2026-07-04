<?php

namespace App\Models;

use App\Enums\ConsentChannel;
use App\Enums\ConsentStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * CommunicationConsent — the compliance record. client_id is a
 * deferred FK (see ClientCommunicationPreference). Only ConsentService
 * may transition status/granted_at/revoked_at — every transition must
 * be paired with a CommunicationConsentEvent row.
 */
class CommunicationConsent extends Model
{
    use HasFactory, HasPublicUuid, BelongsToTenant;

    protected $fillable = [
        'firm_id',
        'client_id',
        'channel',
        'status',
        'consent_text_version',
        'granted_at',
        'revoked_at',
        'expires_at',
        'captured_via',
        'captured_ip',
    ];

    protected function casts(): array
    {
        return [
            'channel' => ConsentChannel::class,
            'status' => ConsentStatus::class,
            'granted_at' => 'datetime',
            'revoked_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(CommunicationConsentEvent::class);
    }

    public function isGranted(?\DateTimeInterface $at = null): bool
    {
        if ($this->status !== ConsentStatus::Granted) {
            return false;
        }

        $at ??= now();

        if ($this->expires_at && $this->expires_at->isBefore($at)) {
            return false;
        }

        return true;
    }
}
