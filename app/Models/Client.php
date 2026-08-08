<?php

namespace App\Models;

use App\Enums\ClientPortalStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * Client — created ONLY by LeadConversionService (never any other
 * way — project rule: leads must not silently become clients).
 * communication_preferences_id links to Phase 1's
 * ClientCommunicationPreference; portal_status/portal_invitation_*
 * prepare the schema for client-portal access (no invitation is
 * actually sent in Phase 2 — see the migration comment).
 *
 * Phase 4 addition: document requests and tasks addressed to this
 * client (client_id is nullable on tasks — most tasks are internal/
 * matter-only and never reach a client).
 * Phase 5 addition: pilot feedback items submitted by or about this
 * client.
 */
class Client extends Model
{
    use BelongsToTenant, HasFactory, HasPublicUuid;

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

    /**
     * Phase 4 additions below.
     */
    public function documentRequests(): HasMany
    {
        return $this->hasMany(DocumentRequest::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function notificationEvents(): HasMany
    {
        return $this->hasMany(NotificationEvent::class);
    }

    /**
     * Phase 5 addition.
     */
    public function pilotFeedbackItems(): HasMany
    {
        return $this->hasMany(PilotFeedbackItem::class);
    }

    /**
     * Tier1-G (Firm Feature Manifest "Relationships" wiring) additions
     * below. TimeEntry and Payment both carry a direct `client_id`
     * column of their own (nullable, but a real FK — see each table's
     * own migration) — so these are plain, direct HasMany relations,
     * exactly the same shape as contacts()/matters()/documentRequests()
     * above, not a new query pattern.
     */
    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Expense has no `client_id` column of its own (only `matter_id` —
     * see Expense's own migration doc comment: "matter linkage/
     * attribution... lives in matter_expenses, not here"), so a
     * client's expenses only exist transitively through its matters.
     * This is a genuine chain HasManyThrough (Client hasMany Matter,
     * Matter hasMany Expense) — the same shape as Matter::
     * conflictCheckResults()'s own precedent for "flatten across an
     * intermediate hasMany", not a new aggregation pattern.
     */
    public function expenses(): HasManyThrough
    {
        return $this->hasManyThrough(Expense::class, Matter::class, 'client_id', 'matter_id');
    }
}
