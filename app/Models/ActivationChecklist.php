<?php

namespace App\Models;

use App\Enums\ActivationChecklistStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ActivationChecklist extends Model
{
    use HasFactory, HasPublicUuid, BelongsToTenant;

    protected $fillable = [
        'firm_id',
        'status',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ActivationChecklistStatus::class,
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ActivationChecklistItem::class);
    }

    /**
     * Complete only when every required item is complete. Waived
     * required items count as satisfied.
     */
    public function allRequiredItemsSatisfied(): bool
    {
        return $this->items()
            ->where('is_required', true)
            ->whereNull('waived_at')
            ->where('is_complete', false)
            ->doesntExist();
    }
}
