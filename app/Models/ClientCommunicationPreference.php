<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ClientCommunicationPreference — client_id's real foreign key is now
 * completed (Phase 2 migration
 * 2026_07_05_600022_add_client_foreign_key_to_client_communication_preferences_table.php),
 * now that `clients` exists. Adding the client() relationship below is
 * exactly the moment Phase 1's doc comment said would come.
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
            'metadata' => 'array',
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
}
