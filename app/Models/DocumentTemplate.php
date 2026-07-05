<?php

namespace App\Models;

use App\Enums\DocumentTemplateCategory;
use App\Enums\DocumentTemplateStatus;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * DocumentTemplate — firm_id NULLABLE (null = global default, set =
 * firm-specific override — the exact pattern Phase 4's
 * NotificationTemplate already uses). No BelongsToTenant, same
 * reasoning as Phase 8's ApiKey (nullable firm_id breaks the "narrow
 * only to firm-owned rows" assumption).
 */
class DocumentTemplate extends Model
{
    use HasFactory, HasPublicUuid;

    protected $fillable = [
        'firm_id',
        'template_code',
        'name',
        'category',
        'status',
        'created_by_firm_user_id',
        'created_by_platform_admin_id',
    ];

    protected function casts(): array
    {
        return [
            'category' => DocumentTemplateCategory::class,
            'status' => DocumentTemplateStatus::class,
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function createdByFirmUser(): BelongsTo
    {
        return $this->belongsTo(FirmUser::class, 'created_by_firm_user_id');
    }

    public function createdByPlatformAdmin(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'created_by_platform_admin_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(DocumentTemplateVersion::class);
    }

    public function isGlobalDefault(): bool
    {
        return is_null($this->firm_id);
    }
}
