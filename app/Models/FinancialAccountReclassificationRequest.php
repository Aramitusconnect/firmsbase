<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FinancialAccountReclassificationRequest — FirmsVault Live
 * Integrations, Checkpoint 4 ("Plaid financial evidence add-on";
 * checkpoint4-design-workspace-and-admin-ui.md §5). The sensitive
 * account-classification change request/approval state machine, styled
 * after `TrustHighRiskAdjustmentService`'s
 * request -> first-approve -> second-approve pattern. The sole write
 * point for `financial_evidence_bank_accounts.classification` is
 * `FinancialAccountReclassificationService::approve()`'s second-approval
 * branch — never any code path off this model directly.
 */
class FinancialAccountReclassificationRequest extends Model
{
    use BelongsToTenant, HasFactory, HasPublicUuid;

    protected $table = 'financial_account_reclassification_requests';

    protected $fillable = [
        'firm_id',
        'bank_account_id',
        'requested_classification',
        'previous_classification',
        'requested_by_firm_user_id',
        'requested_at',
        'reason',
        'first_approved_by_firm_user_id',
        'first_approved_at',
        'second_approved_by_firm_user_id',
        'second_approved_at',
        'rejected_by_firm_user_id',
        'rejected_at',
        'status',
        'correlation_uuid',
    ];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'first_approved_at' => 'datetime',
            'second_approved_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialEvidenceBankAccount::class, 'bank_account_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(FirmUser::class, 'requested_by_firm_user_id');
    }

    public function firstApprover(): BelongsTo
    {
        return $this->belongsTo(FirmUser::class, 'first_approved_by_firm_user_id');
    }

    public function secondApprover(): BelongsTo
    {
        return $this->belongsTo(FirmUser::class, 'second_approved_by_firm_user_id');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isFirstApproved(): bool
    {
        return $this->status === 'first_approved';
    }
}
