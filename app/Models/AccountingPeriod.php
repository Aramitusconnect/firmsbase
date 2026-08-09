<?php

namespace App\Models;

use App\Enums\AccountingPeriodStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AccountingPeriod — Phase K. Mutable lifecycle (closed -> reopened ->
 * closed again), unlike the append-only ledger-shaped models this
 * mission otherwise adds — see the creating migration's own docblock.
 */
class AccountingPeriod extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'firm_id',
        'period_start',
        'period_end',
        'status',
        'opening_balance_cents',
        'closing_balance_cents',
        'ar_snapshot_json',
        'trust_liability_snapshot_json',
        'unresolved_exceptions_json',
        'closed_by_firm_user_id',
        'closed_at',
        'reopened_by_firm_user_id',
        'reopened_at',
        'reopen_reason',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'status' => AccountingPeriodStatus::class,
            'opening_balance_cents' => 'integer',
            'closing_balance_cents' => 'integer',
            'ar_snapshot_json' => 'array',
            'trust_liability_snapshot_json' => 'array',
            'unresolved_exceptions_json' => 'array',
            'closed_at' => 'datetime',
            'reopened_at' => 'datetime',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(FirmUser::class, 'closed_by_firm_user_id');
    }

    public function reopenedBy(): BelongsTo
    {
        return $this->belongsTo(FirmUser::class, 'reopened_by_firm_user_id');
    }

    public function isClosed(): bool
    {
        return $this->status === AccountingPeriodStatus::Closed;
    }
}
