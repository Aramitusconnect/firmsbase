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
 * CommunicationConsent — client_id's real foreign key is now completed
 * (Phase 2 migration
 * 2026_07_05_600023_add_client_foreign_key_to_communication_consents_table.php).
 * Adding the client() relationship below, same as
 * ClientCommunicationPreference. Only ConsentService may transition
 * status/granted_at/revoked_at — every transition must be paired with
 * a CommunicationConsentEvent row.
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

    /**
     * Phase 2 addition — the real relationship, now that Client exists.
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
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
