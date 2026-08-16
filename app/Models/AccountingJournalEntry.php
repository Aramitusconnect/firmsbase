<?php

namespace App\Models;

use App\Enums\AccountingJournalSourceType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * AccountingJournalEntry — append-only, no status column, mirroring
 * TrustLedgerEntry's own discipline exactly. $timestamps = false; only
 * `created_at` exists. The model's own booted() hook throws
 * \LogicException on ANY update or delete of an existing row. The
 * ONLY way to correct a posted entry is AccountingJournalReversalService
 * creating a brand-new entry with every posting's debit/credit
 * swapped, referencing this one via reverses_journal_entry_id — this
 * row's own fields never change.
 */
class AccountingJournalEntry extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'firm_id',
        'entry_date',
        'description',
        'source_type',
        'idempotency_key',
        'reverses_journal_entry_id',
        'posted_by_firm_user_id',
        'payment_id',
        'invoice_id',
        'expense_id',
        'trust_transfer_request_id',
        'pending_payment_allocation_id',
        // FirmsVault Pay Gate A2 — see
        // 2026_11_21_100012_add_payment_attempt_id_to_accounting_journal_entries_table.php.
        'payment_attempt_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'source_type' => AccountingJournalSourceType::class,
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new \LogicException(
                'accounting_journal_entries is append-only: an existing row can never be updated. Post a reversing entry instead.'
            );
        });

        static::deleting(function () {
            throw new \LogicException(
                'accounting_journal_entries is append-only: an existing row can never be deleted. Post a reversing entry instead.'
            );
        });
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function postings(): HasMany
    {
        return $this->hasMany(AccountingPosting::class);
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_journal_entry_id');
    }

    public function reversedBy(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_journal_entry_id');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(FirmUser::class, 'posted_by_firm_user_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function trustTransferRequest(): BelongsTo
    {
        return $this->belongsTo(TrustTransferRequest::class);
    }

    public function pendingPaymentAllocation(): BelongsTo
    {
        return $this->belongsTo(PendingPaymentAllocation::class);
    }
}
