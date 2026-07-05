<?php

namespace App\Models;

use App\Enums\MigrationProjectStatus;
use App\Enums\MigrationSourceType;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * MigrationProject — firm_id is non-nullable, so this model DOES use
 * BelongsToTenant (approved correction #10). Source types are guides/
 * labels only — no real external API call is ever made.
 */
class MigrationProject extends Model
{
    use HasFactory, HasPublicUuid, BelongsToTenant;

    protected $fillable = [
        'firm_id',
        'source_type',
        'status',
        'notes',
        'started_at',
        'completed_at',
        'created_by_firm_user_id',
        'created_by_platform_admin_id',
    ];

    protected function casts(): array
    {
        return [
            'source_type' => MigrationSourceType::class,
            'status' => MigrationProjectStatus::class,
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
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

    public function importBatches(): HasMany
    {
        return $this->hasMany(ImportBatch::class);
    }
}
