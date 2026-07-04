<?php

namespace App\Models;

use App\Enums\TemplateUpgradeLogStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * TemplateUpgradeLog — firm_id is NOT NULL — genuinely firm-scoped,
 * uses BelongsToTenant, gets Phase 6 RLS. Append-only: a rollback NEVER
 * mutates or deletes the original Applied row — it inserts a NEW row
 * with status = RolledBack and rollback_of_id pointing back at the row
 * it undoes (mirrors Phase 5's MaintenanceWindowService::reschedule()
 * supersede pattern exactly).
 */
class TemplateUpgradeLog extends Model
{
    use HasFactory, HasPublicUuid, BelongsToTenant;

    protected $fillable = [
        'firm_id',
        'installed_template_pack_id',
        'from_version_id',
        'to_version_id',
        'status',
        'applied_at',
        'applied_by',
        'rollback_of_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => TemplateUpgradeLogStatus::class,
            'applied_at' => 'datetime',
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

    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }

    public function rollbackOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'rollback_of_id');
    }

    public function rolledBackBy(): HasOne
    {
        return $this->hasOne(self::class, 'rollback_of_id');
    }

    public function isRolledBack(): bool
    {
        return $this->status === TemplateUpgradeLogStatus::RolledBack;
    }
}
