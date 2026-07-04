<?php

namespace App\Models;

use App\Enums\TemplateUpgradePreviewStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TemplateUpgradePreview — firm_id is NOT NULL — genuinely firm-scoped,
 * uses BelongsToTenant, gets Phase 6 RLS. Records the diff a firm would
 * see before choosing to apply an upgrade; never mutates
 * InstalledTemplatePack itself (that only happens via
 * TemplatePackInstallationService::install(), triggered by a later,
 * separate TemplateUpgradeLogService call).
 */
class TemplateUpgradePreview extends Model
{
    use HasFactory, HasPublicUuid, BelongsToTenant;

    protected $fillable = [
        'firm_id',
        'installed_template_pack_id',
        'from_version_id',
        'to_version_id',
        'status',
        'diff_summary_json',
        'previewed_at',
        'previewed_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => TemplateUpgradePreviewStatus::class,
            'diff_summary_json' => 'array',
            'previewed_at' => 'datetime',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function installedTemplatePack(): BelongsTo
    {
        return $this->belongsTo(InstalledTemplatePack::class);
    }

    public function fromVersion(): BelongsTo
    {
        return $this->belongsTo(TemplatePackVersion::class, 'from_version_id');
    }

    public function toVersion(): BelongsTo
    {
        return $this->belongsTo(TemplatePackVersion::class, 'to_version_id');
    }

    public function previewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'previewed_by');
    }
}
