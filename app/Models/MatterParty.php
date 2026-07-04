<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MatterParty — deliberately does NOT use BelongsToTenant. No firm_id
 * column of its own; isolation is transitive through matter_id ->
 * matters.firm_id, same pattern as ActivationChecklistItem in Phase 1.
 */
class MatterParty extends Model
{
    use HasFactory;

    protected $fillable = [
        'matter_id',
        'party_id',
        'relationship_type',
        'is_opposing',
        'is_related',
    ];

    protected function casts(): array
    {
        return [
            'is_opposing' => 'boolean',
            'is_related' => 'boolean',
        ];
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }
}
