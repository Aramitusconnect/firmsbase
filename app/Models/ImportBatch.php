<?php

namespace App\Models;

use App\Enums\ImportBatchStatus;
use App\Enums\ImportEntityType;
use App\Enums\ImportSourceType;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ImportBatch — the root of the Import Center workflow. firm_id is
 * non-nullable, so this model DOES use BelongsToTenant (approved
 * correction #10).
 */
class ImportBatch extends Model
{
    use HasFactory, HasPublicUuid, BelongsToTenant;

    protected $fillable = [
        'firm_id',
        'entity_type',
        'source_type',
        'migration_project_id',
        'status',
        'created_by_firm_user_id',
        'created_by_platform_admin_id',
        'staged_at',
        'previewed_at',
        'confirmed_at',
        'applied_at',
        'rolled_back_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'entity_type' => ImportEntityType::class,
            'source_type' => ImportSourceType::class,
            'status' => ImportBatchStatus::class,
            'staged_at' => 'datetime',
            'previewed_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'applied_at' => 'datetime',
            'rolled_back_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function migrationProject(): BelongsTo
    {
        return $this->belongsTo(MigrationProject::class);
    }

    public function createdByFirmUser(): BelongsTo
    {
        return $this->belongsTo(FirmUser::class, 'created_by_firm_user_id');
    }

    public function createdByPlatformAdmin(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'created_by_platform_admin_id');
    }

    public function mappings(): HasMany
    {
        return $this->hasMany(ImportMapping::class);
    }

    public function rows(): HasMany
    {
        return $this->hasMany(ImportRow::class);
    }

    public function auditEvents(): HasMany
    {
        return $this->hasMany(ImportAuditEvent::class);
    }

    public function rollbackRecords(): HasMany
    {
        return $this->hasMany(ImportRollbackRecord::class);
    }
}
