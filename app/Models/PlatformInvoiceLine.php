<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PlatformInvoiceLine — firm_id is nullable and used ONLY for per-firm
 * usage attribution on a consolidated organization invoice (project
 * rule 4). Not a tenant boundary — no BelongsToTenant, no Phase 6 RLS
 * (approved decision, see the migration's docblock).
 */
class PlatformInvoiceLine extends Model
{
    use HasFactory, HasPublicUuid;

    protected $fillable = [
        'platform_invoice_id',
        'firm_id',
        'description',
        'quantity',
        'unit_amount_cents',
        'amount_cents',
        'usage_metric',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(PlatformInvoice::class, 'platform_invoice_id');
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }
}
