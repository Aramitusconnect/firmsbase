<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TaskCategoryRoleExpectation — Leverage Ratio Optimizer, item 7/8.
 * See its own create-table migration for the "genuinely separate from
 * matter_budget_templates" rationale. StaffingPolicyService is the
 * only writer.
 */
class TaskCategoryRoleExpectation extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'firm_id',
        'task_category',
        'recommended_roles_json',
        'notes',
        'created_by_firm_user_id',
        'updated_by_firm_user_id',
    ];

    protected function casts(): array
    {
        return [
            'recommended_roles_json' => 'array',
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
}
