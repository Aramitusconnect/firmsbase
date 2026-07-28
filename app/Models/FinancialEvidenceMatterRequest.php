<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FinancialEvidenceMatterRequest — FirmsVault Live Integrations,
 * Checkpoint 4 ("Plaid financial evidence add-on";
 * checkpoint4-design-workspace-and-admin-ui.md §4.1). The firm's
 * outbound ask: "please connect a financial account for this matter" —
 * created before any client action, pre-consent. Written by the firm
 * panel's `PlaidMatterRequestsPage`; read by the Client Portal's
 * `PlaidRequestReviewPage`.
 */
class FinancialEvidenceMatterRequest extends Model
{
    use BelongsToTenant, HasFactory, HasPublicUuid;

    protected $table = 'financial_evidence_matter_requests';

    protected $fillable = [
        'firm_id',
        'matter_id',
        'requested_by_firm_user_id',
        'purpose',
        'requested_products_json',
        'status',
        'requested_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'requested_products_json' => 'array',
            'requested_at' => 'datetime',
            'cancelled_at' => 'datetime',
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

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(FirmUser::class, 'requested_by_firm_user_id');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
