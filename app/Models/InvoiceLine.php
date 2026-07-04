<?php

namespace App\Models;

use App\Enums\InvoiceLineType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * InvoiceLine — a detail record of its parent invoice, no own firm_id
 * (tenant isolation flows through invoice_id, same pattern as
 * Phase 2's matter_parties). No uuid — accessed only through the
 * parent Invoice.
 */
class InvoiceLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'invoice_id',
        'time_entry_id',
        'line_type',
        'description',
        'quantity',
        'rate_cents',
        'amount_cents',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'line_type' => InvoiceLineType::class,
            'quantity' => 'decimal:4',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function timeEntry(): BelongsTo
    {
        return $this->belongsTo(TimeEntry::class);
    }
}
