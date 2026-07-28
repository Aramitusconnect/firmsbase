<?php

declare(strict_types=1);

namespace App\Models;

use App\Integrations\Models\FirmIntegration;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FinancialEvidenceClientConsent — FirmsVault Live Integrations,
 * Checkpoint 4 ("Plaid financial evidence add-on";
 * checkpoint4-design-workspace-and-admin-ui.md §4.6). The client's
 * actual decision in response to a `FinancialEvidenceMatterRequest`:
 * which products were granted or declined, when, from what IP — the
 * consent EVENT. `financial_evidence_snapshots.consent_reference`'s
 * target. Written exclusively from Client Portal actions
 * (`PlaidConsentPage`).
 */
class FinancialEvidenceClientConsent extends Model
{
    use BelongsToTenant, HasFactory, HasPublicUuid;

    protected $table = 'financial_evidence_client_consents';

    protected $fillable = [
        'firm_id',
        'client_id',
        'matter_id',
        'matter_request_id',
        'firm_integration_id',
        'granted_products_json',
        'granted_at',
        'declined_at',
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'granted_products_json' => 'array',
            'granted_at' => 'datetime',
            'declined_at' => 'datetime',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function matterRequest(): BelongsTo
    {
        return $this->belongsTo(FinancialEvidenceMatterRequest::class, 'matter_request_id');
    }

    public function firmIntegration(): BelongsTo
    {
        return $this->belongsTo(FirmIntegration::class);
    }

    public function isDeclined(): bool
    {
        return $this->declined_at !== null;
    }

    public function isGranted(): bool
    {
        return $this->granted_at !== null;
    }
}
