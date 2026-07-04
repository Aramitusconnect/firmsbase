<?php

namespace App\Models;

use App\Enums\InstalledTemplatePackStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * InstalledTemplatePack — per-firm install record. Upgrading updates
 * template_pack_version_id in place; it never retroactively changes
 * Matter::pinned_template_pack_version_id on already-open matters.
 *
 * Phase 6 addition: upgradePreviews()/upgradeLogs() relations —
 * TemplatePackCommercialService/TemplateUpgradePreviewService/
 * TemplateUpgradeLogService read/write these without ever duplicating
 * this table or template_packs/template_pack_versions.
 */
class InstalledTemplatePack extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'firm_id',
        'template_pack_id',
        'template_pack_version_id',
        'status',
        'installed_at',
        'disabled_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => InstalledTemplatePackStatus::class,
            'installed_at' => 'datetime',
            'disabled_at' => 'datetime',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function templatePack(): BelongsTo
    {
        return $this->belongsTo(TemplatePack::class);
    }

    public function templatePackVersion(): BelongsTo
    {
        return $this->belongsTo(TemplatePackVersion::class);
    }

    /**
     * Phase 6 additions below.
     */
    public function upgradePreviews(): HasMany
    {
        return $this->hasMany(TemplateUpgradePreview::class);
    }

    public function upgradeLogs(): HasMany
    {
        return $this->hasMany(TemplateUpgradeLog::class);
    }
}
