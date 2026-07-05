<?php

namespace App\Models;

use App\Enums\AccountingExportBatchStatus;
use App\Enums\AccountingExportTarget;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * AccountingExportBatch — the firm-owned root of one fake/simulated
 * one-way export run. firm_id is non-nullable, so this model uses
 * BelongsToTenant (mirrors Phase 8's ExportJob). Completed/CompletedWithErrors/
 * Failed/Blocked batches are never rewritten (correction #9) — only
 * AccountingExportBatchService may transition status, and never back
 * to an earlier state.
 */
class AccountingExportBatch extends Model
{
    use HasFactory, HasPublicUuid, BelongsToTenant;

    protected $fillable = [
        'firm_id',
        'export_target',
        'status',
        'requested_by_firm_user_id',
        'date_range_start',
        'date_range_end',
        'started_at',
        'completed_at',
        'failed_reason',
    ];

    protected function casts(): array
    {
        return [
            'export_target' => AccountingExportTarget::class,
            'status' => AccountingExportBatchStatus::class,
            'date_range_start' => 'date',
            'date_range_end' => 'date',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(FirmUser::class, 'requested_by_firm_user_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(AccountingExportLine::class);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            AccountingExportBatchStatus::Completed,
            AccountingExportBatchStatus::CompletedWithErrors,
            AccountingExportBatchStatus::Failed,
            AccountingExportBatchStatus::Blocked,
        ], true);
    }
}
