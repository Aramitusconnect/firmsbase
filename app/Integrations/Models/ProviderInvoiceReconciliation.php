<?php

declare(strict_types=1);

namespace App\Integrations\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Models\PlatformAdmin;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ProviderInvoiceReconciliation — Global, platform-scoped (not
 * tenant-owned), modeled directly on `App\Models\TrustReconciliation`'s
 * own shape. The sole writer is
 * `App\Services\ProviderInvoiceReconciliationService::run()`
 * (checkpoint4-design-cost-control.md §6).
 */
class ProviderInvoiceReconciliation extends Model
{
    use HasFactory, HasPublicUuid;

    protected $table = 'provider_invoice_reconciliations';

    public const STATUS_BALANCED = 'balanced';

    public const STATUS_DISCREPANCY = 'discrepancy';

    protected $fillable = [
        'provider_key',
        'period_start',
        'period_end',
        'asserted_invoice_total_cents',
        'system_recorded_total_cents',
        'discrepancy_cents',
        'status',
        'performed_by',
        'completed_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'asserted_invoice_total_cents' => 'integer',
            'system_recorded_total_cents' => 'integer',
            'discrepancy_cents' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'performed_by');
    }
}
