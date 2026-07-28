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
 * FinancialEvidenceMatterAuthorization — FirmsVault Live Integrations,
 * Checkpoint 4 ("Plaid financial evidence add-on";
 * checkpoint4-design-workspace-and-admin-ui.md §1.4/§4.6, renamed from
 * `financial_evidence_matter_grants` per
 * checkpoint4-combined-design.md §1.4's binding naming pass). The
 * resulting, currently-in-effect binding: which `firm_integrations`
 * back this matter's financial evidence, plus the currently-authorized
 * date range — derived from a `FinancialEvidenceClientConsent`,
 * re-editable only through a renewal action (never silently widened by
 * any sync job). `superseded_at` marks a row replaced by a later
 * renewal — never edited in place.
 */
class FinancialEvidenceMatterAuthorization extends Model
{
    use BelongsToTenant, HasFactory, HasPublicUuid;

    protected $table = 'financial_evidence_matter_authorizations';

    protected $fillable = [
        'firm_id',
        'matter_id',
        'firm_integration_id',
        'consent_id',
        'authorized_date_range_start',
        'authorized_date_range_end',
        'superseded_at',
    ];

    protected function casts(): array
    {
        return [
            'authorized_date_range_start' => 'date',
            'authorized_date_range_end' => 'date',
            'superseded_at' => 'datetime',
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

    public function firmIntegration(): BelongsTo
    {
        return $this->belongsTo(FirmIntegration::class);
    }

    public function consent(): BelongsTo
    {
        return $this->belongsTo(FinancialEvidenceClientConsent::class, 'consent_id');
    }

    public function isActive(): bool
    {
        return $this->superseded_at === null;
    }
}
