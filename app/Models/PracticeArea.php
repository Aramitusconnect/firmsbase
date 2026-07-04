<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * PracticeArea — GLOBAL platform catalog (approved decision). Firms
 * enable/select from this via FirmPracticeArea; no per-firm custom
 * core practice areas in Phase 2. No BelongsToTenant, no uuid.
 */
class PracticeArea extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    public function matterTypes(): HasMany
    {
        return $this->hasMany(MatterType::class);
    }

    public function templatePacks(): HasMany
    {
        return $this->hasMany(TemplatePack::class);
    }

    public function firmPracticeAreas(): HasMany
    {
        return $this->hasMany(FirmPracticeArea::class);
    }
}
