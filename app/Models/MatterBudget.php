<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MatterBudget — Predictive Matter Budget Alerts, item 4/20. Append-
 * only per matter (see its own create-table migration) — a row is
 * NEVER updated once created; a revision is always a new row with an
 * incremented version. MatterBudgetService is the only writer.
 */
class MatterBudget extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'firm_id',
        'matter_id',
        'version',
        'source_template_id',
        'source_template_version',
        'expected_duration_days',
        'expected_hours_json',
        'expected_expenses_json',
        'expected_revenue_cents',
        'target_gross_margin_percent',
        'warning_threshold_percent',
        'high_threshold_percent',
        'change_reason',
        'created_by_firm_user_id',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'source_template_version' => 'integer',
            'expected_duration_days' => 'integer',
            'expected_hours_json' => 'array',
            'expected_expenses_json' => 'array',
            'expected_revenue_cents' => 'integer',
            'target_gross_margin_percent' => 'integer',
            'warning_threshold_percent' => 'integer',
            'high_threshold_percent' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $budget) {
            throw new \LogicException('matter_budgets is append-only — create a new revision instead of updating an existing row.');
        });
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function sourceTemplate(): BelongsTo
    {
        return $this->belongsTo(MatterBudgetTemplate::class, 'source_template_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(FirmUser::class, 'created_by_firm_user_id');
    }
}
