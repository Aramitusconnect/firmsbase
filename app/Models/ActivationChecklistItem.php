<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ActivationChecklistItem — deliberately does NOT use BelongsToTenant.
 * This table has no firm_id column of its own — only
 * activation_checklist_id. Its isolation is transitive: only ever
 * reachable through its parent ActivationChecklist (which IS
 * tenant-owned). Denormalizing firm_id onto this table purely to reuse
 * the trait was considered and rejected — it would create a second
 * source of truth for the item's firm that could drift from its
 * parent's firm_id with no benefit.
 */
class ActivationChecklistItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'activation_checklist_id',
        'item_key',
        'label',
        'is_required',
        'is_complete',
        'completed_by',
        'completed_at',
        'waived_at',
        'waived_by',
        'waiver_reason',
    ];

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'is_complete' => 'boolean',
            'completed_at' => 'datetime',
            'waived_at' => 'datetime',
        ];
    }

    public function activationChecklist(): BelongsTo
    {
        return $this->belongsTo(ActivationChecklist::class);
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function waivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'waived_by');
    }

    public function isWaived(): bool
    {
        return ! is_null($this->waived_at);
    }
}
