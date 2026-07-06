<?php

namespace App\Models;

use App\Enums\DegradedBehavior;
use App\Enums\IntegrationType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * IntegrationDegradationMode — platform-level reference data (no
 * firm_id, no BelongsToTenant, mirrors ModuleCatalog's exact
 * reasoning). Exactly 4 rows, seeded by an idempotent data migration.
 * Declaration-only (approved decision #1).
 */
class IntegrationDegradationMode extends Model
{
    use HasFactory;

    protected $table = 'integration_degradation_modes';

    protected $fillable = [
        'integration_type',
        'degraded_behavior',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'integration_type' => IntegrationType::class,
            'degraded_behavior' => DegradedBehavior::class,
        ];
    }
}
