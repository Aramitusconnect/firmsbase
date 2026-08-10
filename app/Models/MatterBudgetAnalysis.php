<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MatterBudgetAnalysis — Predictive Matter Budget Alerts, item 10. One
 * current row per Matter, recomputed in place by
 * MatterBudgetAnalysisService — see its own create-table migration's
 * "mirrors matter_readiness_scores" docblock. Every value here is
 * derived and safely rebuildable; never itself a source of truth.
 */
class MatterBudgetAnalysis extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'firm_id',
        'matter_id',
        'matter_budget_id',
        'hours_by_role_json',
        'expenses_by_category_json',
        'total_labor_cost_cents',
        'total_expenses_cents',
        'revenue_expected_cents',
        'revenue_invoiced_cents',
        'revenue_collected_cents',
        'revenue_outstanding_cents',
        'estimated_margin_cents',
        'estimated_margin_percent',
        'current_margin_cents',
        'current_margin_percent',
        'work_completion_percent',
        'work_completion_breakdown_json',
        'time_elapsed_percent',
        'projected_hours_by_role_json',
        'projected_overrun_hours_by_role_json',
        'projected_final_cost_cents',
        'projected_margin_cents',
        'projected_margin_percent',
        'computed_at',
    ];

    protected function casts(): array
    {
        return [
            'hours_by_role_json' => 'array',
            'expenses_by_category_json' => 'array',
            'total_labor_cost_cents' => 'integer',
            'total_expenses_cents' => 'integer',
            'revenue_expected_cents' => 'integer',
            'revenue_invoiced_cents' => 'integer',
            'revenue_collected_cents' => 'integer',
            'revenue_outstanding_cents' => 'integer',
            'estimated_margin_cents' => 'integer',
            'estimated_margin_percent' => 'integer',
            'current_margin_cents' => 'integer',
            'current_margin_percent' => 'integer',
            'work_completion_percent' => 'integer',
            'work_completion_breakdown_json' => 'array',
            'time_elapsed_percent' => 'integer',
            'projected_hours_by_role_json' => 'array',
            'projected_overrun_hours_by_role_json' => 'array',
            'projected_final_cost_cents' => 'integer',
            'projected_margin_cents' => 'integer',
            'projected_margin_percent' => 'integer',
            'computed_at' => 'datetime',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function matterBudget(): BelongsTo
    {
        return $this->belongsTo(MatterBudget::class);
    }
}
