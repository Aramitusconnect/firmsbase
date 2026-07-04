<?php

namespace App\Models;

use App\Enums\PlanModuleStatus;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PlanModule — which module_catalog modules a Plan grants, and whether
 * enabled by default. is_addon = true models an optional paid add-on
 * (approved decision: no separate add-ons table). module_code is a
 * STRING foreign key to module_catalog.module_code, matching how
 * firm_entitlements already addresses modules.
 */
class PlanModule extends Model
{
    use HasFactory, HasPublicUuid;

    protected $fillable = [
        'plan_id',
        'module_code',
        'enabled',
        'is_addon',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'is_addon' => 'boolean',
            'status' => PlanModuleStatus::class,
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(ModuleCatalog::class, 'module_code', 'module_code');
    }
}
