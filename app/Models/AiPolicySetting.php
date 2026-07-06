<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * AiPolicySetting — approved decision #6: PLATFORM-LEVEL only. No
 * firm_id column, deliberately does NOT use BelongsToTenant (there is
 * no tenant to scope to — this is global reference/configuration data,
 * matching ModuleCatalog's own reasoning). Stores platform-wide AI
 * guardrails/defaults, e.g. whether firm_owned mode is globally
 * permitted at all.
 */
class AiPolicySetting extends Model
{
    use HasFactory;

    protected $table = 'ai_policy_settings';

    protected $fillable = [
        'key',
        'value_json',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'value_json' => 'array',
        ];
    }

    public function updatedBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
