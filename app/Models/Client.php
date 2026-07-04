<?php

namespace App\Models;

use App\Enums\ClientPortalStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Client — created ONLY by LeadConversionService (never any other
 * way — project rule: leads must not silently become clients).
 * communication_preferences_id links to Phase 1's
 * ClientCommunicationPreference; portal_status/portal_invitation_*
 * prepare the schema for client-portal access (no invitation is
 * actually sent in Phase 2 — see the migration comment).
 */
class Client extends Model
{
    use HasFactory, HasPublicUuid, BelongsToTenant;

    protected $fillable = [
        'firm_id',
        'communication_preferences_id',
        'display_name',
        'legal_name',
        'email',
        'phone',
        'preferred_language',
        'preferred_timezone',
        'portal_status',
        'portal_invitation_token',
        'portal_invitation_sent_at',
        'portal_invitation_accepted_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'portal_status' => ClientPortalStatus::class,
            'portal_invitation_sent_at' => 'datetime',
            'portal_invitation_accepted_at' => 'datetime',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function communicationPreferences(): BelongsTo
    {
        return $this->belongsTo(ClientCommunicationPreference::class, 'communication_preferences_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    public function matters(): HasMany
    {
        return $this->hasMany(Matter::class);
    }

    public function firmLeadsConverted(): HasMany
    {
        return $this->hasMany(FirmLead::class, 'converted_client_id');
    }

    public function communicationConsents(): HasMany
    {
        return $this->hasMany(CommunicationConsent::class);
    }

    public function intakeSubmissions(): HasMany
    {
        return $this->hasMany(IntakeSubmission::class);
    }
}
