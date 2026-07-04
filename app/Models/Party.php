<?php

namespace App\Models;

use App\Enums\PartyEntityType;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Party — matter-level parties (opposing/related, companies,
 * witnesses). entity_type distinguishes an individual from a company
 * — no separate companies table.
 */
class Party extends Model
{
    use HasFactory, HasPublicUuid, BelongsToTenant;

    protected $fillable = [
        'firm_id',
        'name',
        'entity_type',
        'email',
        'phone',
        'company',
        'normalized_search_keys',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'entity_type' => PartyEntityType::class,
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function matterParties(): HasMany
    {
        return $this->hasMany(MatterParty::class);
    }
}
