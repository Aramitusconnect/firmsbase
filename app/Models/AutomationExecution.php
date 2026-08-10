<?php

namespace App\Models;

use App\Enums\AutomationExecutionStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * AutomationExecution — Event-Driven Automation Engine, item 9. One row
 * per (AutomationRule, DomainEvent) match attempt — see the create-table
 * migration's own docblock for why the unique(automation_rule_id,
 * domain_event_id) index IS the execution-level idempotency guarantee.
 */
class AutomationExecution extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'firm_id',
        'automation_rule_id',
        'domain_event_id',
        'rule_version',
        'conditions_evaluated_json',
        'matched',
        'status',
        'started_at',
        'completed_at',
        'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'rule_version' => 'integer',
            'conditions_evaluated_json' => 'array',
            'matched' => 'boolean',
            'status' => AutomationExecutionStatus::class,
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AutomationRule::class, 'automation_rule_id');
    }

    public function domainEvent(): BelongsTo
    {
        return $this->belongsTo(DomainEvent::class);
    }

    public function actionExecutions(): HasMany
    {
        return $this->hasMany(AutomationActionExecution::class);
    }
}
