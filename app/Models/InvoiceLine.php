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
 *
 * Phase 12 addition: expense_id (nullable, unique) — populated only
 * when line_type is ReimbursableExpense, and only by
 * ReimbursableExpenseInvoiceLineService (the sole writer of that
 * combination). The unique constraint on invoice_lines.expense_id
 * (2026_07_16_900010 migration) guarantees a given expense can never
 * back more than one invoice line, at the database level.
 */
class InvoiceLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'invoice_id',
        'time_entry_id',
        'expense_id',
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

    /**
     * Phase 12 addition. Only populated for line_type ===
     * InvoiceLineType::ReimbursableExpense.
     */
    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }
}
