<?php

namespace App\Models;

use App\Enums\TemplatePackStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * TemplatePackVersion — GLOBAL. Matters pin to a specific row here at
 * creation time (Matter::pinned_template_pack_version_id) so a later
 * pack upgrade never silently changes an already-open matter.
 */
class TemplatePackVersion extends Model
{
    use HasFactory;

    protected $fillable = [
        'template_pack_id',
        'version',
        'status',
        'release_notes',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => TemplatePackStatus::class,
            'published_at' => 'datetime',
        ];
    }

    public function templatePack(): BelongsTo
    {
        return $this->belongsTo(TemplatePack::class);
    }

    public function intakeTemplates(): HasMany
    {
        return $this->hasMany(IntakeTemplate::class);
    }

    public function installedTemplatePacks(): HasMany
    {
        return $this->hasMany(InstalledTemplatePack::class);
    }

    public function pinnedMatters(): HasMany
    {
        return $this->hasMany(Matter::class, 'pinned_template_pack_version_id');
    }
}
