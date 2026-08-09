<?php

namespace App\Models;

use App\Enums\ChartOfAccountPurpose;
use App\Enums\ChartOfAccountType;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ChartOfAccount — the firm-owned chart-of-accounts foundation. firm_id
 * is non-nullable, so this model uses BelongsToTenant. No platform-
 * global/default rows exist in Phase 12 (correction #4) — every row is
 * created by a firm through ChartOfAccountsService.
 */
class ChartOfAccount extends Model
{
    use BelongsToTenant, HasFactory, HasPublicUuid;

    protected $table = 'chart_of_accounts';

    protected $fillable = [
        'firm_id',
        'account_code',
        'account_name',
        'account_type',
        'purpose',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'account_type' => ChartOfAccountType::class,
            'purpose' => ChartOfAccountPurpose::class,
            'is_active' => 'boolean',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function expenseCategories(): HasMany
    {
        return $this->hasMany(ExpenseCategory::class);
    }

    public function accountingExportLines(): HasMany
    {
        return $this->hasMany(AccountingExportLine::class);
    }
}
