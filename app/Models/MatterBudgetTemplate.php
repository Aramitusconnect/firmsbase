<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MatterBudgetTemplate — Predictive Matter Budget Alerts, item 3. See
 * its own create-table migration for the full field-shape rationale.
 * Mutable Firm configuration — MatterBudgetService is the only writer;
 * applying a template to a Matter always snapshots it into a
 * MatterBudget row rather than the Matter depending on this row live.
 */
class MatterBudgetTemplate extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'firm_id',
        'name',
        'description',
        'practice_area_id',
        'matter_type_id',
        'expected_duration_days',
        'expected_hours_json',
        'expected_expenses_json',
        'expected_revenue_cents',
        'target_gross_margin_percent',
        'warning_threshold_percent',
        'high_threshold_percent',
        'active',
        'version',
        'created_by_firm_user_id',
        'updated_by_firm_user_id',
    ];

    protected function casts(): array
    {
        return [
            'expected_duration_days' => 'integer',
            'expected_hours_json' => 'array',
            'expected_expenses_json' => 'array',
            'expected_revenue_cents' => 'integer',
            'target_gross_margin_percent' => 'integer',
            'warning_threshold_percent' => 'integer',
            'high_threshold_percent' => 'integer',
            'active' => 'boolean',
            'version' => 'integer',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function practiceArea(): BelongsTo
    {
        return $this->belongsTo(PracticeArea::class);
    }

    public function matterType(): BelongsTo
    {
        return $this->belongsTo(MatterType::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(FirmUser::class, 'created_by_firm_user_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(FirmUser::class, 'updated_by_firm_user_id');
    }
}
