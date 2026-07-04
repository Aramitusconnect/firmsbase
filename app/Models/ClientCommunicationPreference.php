<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ClientCommunicationPreference — client_id is a deferred FK (plain
 * nullable unsigned bigint). `clients` does not exist yet; the client
 * phase adds the real foreign key via ALTER TABLE. No clientId()
 * relationship yet — there is no Client model to point it at.
 */
class ClientCommunicationPreference extends Model
{
    use HasFactory, HasPublicUuid, BelongsToTenant;

    protected $fillable = [
        'firm_id',
        'client_id',
        'preferred_language',
        'preferred_timezone',
        'notification_frequency',
        'do_not_contact',
    ];

    protected function casts(): array
    {
        return [
            'do_not_contact' => 'boolean',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }
}
