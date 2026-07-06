<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PrivateEnterpriseSettings — one row per private-enterprise firm. The
 * requires_* booleans are declarations of what this deployment needs;
 * no real provisioning happens in Phase 16.
 */
class PrivateEnterpriseSettings extends Model
{
    use HasFactory, HasPublicUuid, BelongsToTenant;

    protected $table = 'private_enterprise_settings';

    protected $fillable = [
        'firm_id',
        'requires_custom_domain',
        'requires_isolated_database',
        'requires_isolated_storage',
        'telemetry_prohibited',
    ];

    protected $attributes = [
        'requires_custom_domain' => false,
        'requires_isolated_database' => false,
        'requires_isolated_storage' => false,
        'telemetry_prohibited' => false,
    ];

    protected function casts(): array
    {
        return [
            'requires_custom_domain' => 'boolean',
            'requires_isolated_database' => 'boolean',
            'requires_isolated_storage' => 'boolean',
            'telemetry_prohibited' => 'boolean',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }
}
