<?php

namespace App\Models;

use App\Enums\ReadinessComponentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * ReadinessScorecardComponent — GLOBAL platform catalog (no firm_id),
 * same pattern as Phase 2's PracticeArea/MatterType. This is the
 * durable RECORD that a component exists; the actual evaluation logic
 * is registered in code via ReadinessScorecardRegistry, keyed by
 * component_key. A new component can be added with a data row here
 * plus registry code — never a schema change.
 */
class ReadinessScorecardComponent extends Model
{
    use HasFactory;

    protected $fillable = [
        'component_key',
        'label',
        'description',
        'status',
        'introduced_in_phase',
        'weight',
    ];

    protected function casts(): array
    {
        return [
            'status' => ReadinessComponentStatus::class,
        ];
    }

    public function isActive(): bool
    {
        return $this->status === ReadinessComponentStatus::Active;
    }
}
