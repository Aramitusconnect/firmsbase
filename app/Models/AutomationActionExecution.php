<?php

namespace App\Models;

use App\Enums\AutomationActionExecutionStatus;
use App\Enums\AutomationActionRiskLevel;
use App\Enums\AutomationActionType;
use App\Enums\AutomationApprovalStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AutomationActionExecution — Event-Driven Automation Engine, item 8/9.
 * One row per action within a matched rule's actions_json. See the
 * create-table migration's own docblock for the idempotency_key shape
 * (rule_id:event_id:action_index:rule_version) and the risk_level/
 * approval defense-in-depth rationale.
 */
class AutomationActionExecution extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'firm_id',
        'automation_execution_id',
        'action_index',
        'action_type',
        'action_config_json',
        'idempotency_key',
        'risk_level',
        'status',
        'approval_status',
        'approved_by_firm_user_id',
        'approved_at',
        'attempts',
        'max_attempts',
        'next_attempt_at',
        'last_error',
        'started_at',
        'completed_at',
        'result_reference_type',
        'result_reference_id',
    ];

    protected function casts(): array
    {
        return [
            'action_index' => 'integer',
            'action_type' => AutomationActionType::class,
            'action_config_json' => 'array',
            'risk_level' => AutomationActionRiskLevel::class,
            'status' => AutomationActionExecutionStatus::class,
            'approval_status' => AutomationApprovalStatus::class,
            'approved_at' => 'datetime',
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'next_attempt_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'result_reference_id' => 'integer',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function execution(): BelongsTo
    {
        return $this->belongsTo(AutomationExecution::class, 'automation_execution_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(FirmUser::class, 'approved_by_firm_user_id');
    }

    public function isAwaitingApproval(): bool
    {
        return $this->status === AutomationActionExecutionStatus::RequiresReview
            && $this->approval_status === AutomationApprovalStatus::Pending;
    }
}
