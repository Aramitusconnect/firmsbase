<?php

namespace App\Models;

use App\Enums\EmailAccountConnectionStatus;
use App\Enums\EmailProvider;
use App\Enums\EmailStorageMode;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * EmailAccount — a connected mailbox. connected_by_firm_user_id is the
 * only actor column (approved decision — firm-user-only, no platform-
 * admin path). storage_mode is the firm's current configured setting;
 * it is read by EmailSyncService at the start of each sync run and
 * copied onto each captured EmailMessage as that message's frozen,
 * effective storage_mode.
 */
class EmailAccount extends Model
{
    use HasFactory, HasPublicUuid, BelongsToTenant;

    protected $fillable = [
        'firm_id',
        'provider',
        'mailbox_address',
        'connection_status',
        'storage_mode',
        'connected_by_firm_user_id',
        'last_synced_at',
        'error_reason',
    ];

    protected function casts(): array
    {
        return [
            'provider' => EmailProvider::class,
            'connection_status' => EmailAccountConnectionStatus::class,
            'storage_mode' => EmailStorageMode::class,
            'last_synced_at' => 'datetime',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function connectedByFirmUser(): BelongsTo
    {
        return $this->belongsTo(FirmUser::class, 'connected_by_firm_user_id');
    }

    public function oauthTokens(): HasMany
    {
        return $this->hasMany(EmailOAuthToken::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(EmailMessage::class);
    }

    public function syncEvents(): HasMany
    {
        return $this->hasMany(EmailSyncEvent::class);
    }

    public function visibilityRules(): HasMany
    {
        return $this->hasMany(EmailVisibilityRule::class);
    }
}
