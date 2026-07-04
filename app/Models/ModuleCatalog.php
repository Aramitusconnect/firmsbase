<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ModuleCatalog — global reference data (the set of installable
 * practice-area/feature modules), not firm-scoped. No BelongsToTenant.
 */
class ModuleCatalog extends Model
{
    use HasFactory;

    protected $table = 'module_catalog';

    protected $fillable = [
        'module_code',
        'module_name',
        'category',
        'description',
        'is_active',
        'requires_admin_approval',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'requires_admin_approval' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'module_code';
    }

    public function entitlements(): HasMany
    {
        return $this->hasMany(FirmEntitlement::class, 'module_code', 'module_code');
    }
}
