<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MatterAssignment — deliberately does NOT use BelongsToTenant. No
 * firm_id column of its own; isolation is transitive through matter_id
 * -> matters.firm_id. removed_at (not row deletion) preserves staffing
 * history.
 */
class MatterAssignment extends Model
{
    use HasFactory;

    protected $fillable = [
        'matter_id',
        'user_id',
        'role',
        'is_lead',
        'assigned_at',
        'removed_at',
    ];

    protected function casts(): array
    {
        return [
            'is_lead' => 'boolean',
            'assigned_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return is_null($this->removed_at);
    }
}
