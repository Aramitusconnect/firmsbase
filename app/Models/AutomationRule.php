<?php

namespace App\Models;

use App\Enums\DomainEventType;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * AutomationRule — Event-Driven Automation Engine, item 4. See the
 * create-table migration's own docblock for the full field-by-field
 * rationale (conditions_json AND-only closed vocabulary, actions_json
 * closed registry, requires_approval as an ADDITIVE-only gate, version
 * snapshotting). Firm-owned, never hidden — every row (including the
 * six first-party starters) is fully visible/editable/disableable via
 * the Firm UI. AutomationRuleService is the only writer.
 */
class AutomationRule extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'firm_id',
        'name',
        'description',
        'event_type',
        'enabled',
        'priority',
        'conditions_json',
        'actions_json',
        'requires_approval',
        'is_starter_template',
        'version',
        'created_by_firm_user_id',
        'updated_by_firm_user_id',
    ];

    protected function casts(): array
    {
        return [
            'event_type' => DomainEventType::class,
            'enabled' => 'boolean',
            'priority' => 'integer',
            'conditions_json' => 'array',
            'actions_json' => 'array',
            'requires_approval' => 'boolean',
            'is_starter_template' => 'boolean',
            'version' => 'integer',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(FirmUser::class, 'created_by_firm_user_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(FirmUser::class, 'updated_by_firm_user_id');
    }

    public function executions(): HasMany
    {
        return $this->hasMany(AutomationExecution::class);
    }
}
