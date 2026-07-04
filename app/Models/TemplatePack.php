<?php

namespace App\Models;

use App\Enums\TemplatePackStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * TemplatePack — GLOBAL catalog (immigration is the first). No
 * BelongsToTenant, no uuid. Firms install a specific version via
 * InstalledTemplatePack.
 */
class TemplatePack extends Model
{
    use HasFactory;

    protected $fillable = [
        'practice_area_id',
        'pack_code',
        'name',
        'description',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => TemplatePackStatus::class,
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'pack_code';
    }

    public function practiceArea(): BelongsTo
    {
        return $this->belongsTo(PracticeArea::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(TemplatePackVersion::class);
    }

    public function installedTemplatePacks(): HasMany
    {
        return $this->hasMany(InstalledTemplatePack::class);
    }
}
