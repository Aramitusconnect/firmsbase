<?php

namespace App\Models;

use App\Enums\ConflictScope;
use App\Enums\ConsolidationMode;
use App\Enums\RecordStatus;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Organization — an optional parent grouping over one or more firms.
 * Not itself tenant-owned (it IS part of the tenancy boundary), so it
 * does not use BelongsToTenant.
 */
class Organization extends Model
{
    use HasFactory, HasPublicUuid;

    protected $fillable = [
        'name',
        'legal_name',
        'status',
        'primary_contact',
        'conflict_scope',
        'consolidation_mode',
    ];

    protected function casts(): array
    {
        return [
            'status' => RecordStatus::class,
            'conflict_scope' => ConflictScope::class,
            'consolidation_mode' => ConsolidationMode::class,
        ];
    }

    public function firms(): HasMany
    {
        return $this->hasMany(Firm::class);
    }
}
