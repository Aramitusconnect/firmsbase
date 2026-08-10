<?php

namespace App\Models;

use App\Enums\MatterBudgetExpenseCategory;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ExpenseCategory — firm_id is non-nullable (correction #3 — tenant-
 * safe, no platform-global categories in Phase 12), so this model uses
 * BelongsToTenant. chart_of_accounts_id is nullable; an expense built
 * from an unmapped category still exports as a line, it just fails at
 * simulation time (correction #4) rather than being blocked earlier.
 *
 * budget_category (Predictive Matter Budget Alerts, item 6) is a
 * nullable, explicit, Firm-set mapping into
 * MatterBudgetExpenseCategory's four closed buckets — never guessed
 * from `name`. See its own migration docblock.
 */
class ExpenseCategory extends Model
{
    use BelongsToTenant, HasFactory, HasPublicUuid;

    protected $fillable = [
        'firm_id',
        'chart_of_accounts_id',
        'name',
        'is_active',
        'budget_category',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'budget_category' => MatterBudgetExpenseCategory::class,
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function chartOfAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'chart_of_accounts_id');
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }
}
