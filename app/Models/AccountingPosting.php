<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AccountingPosting — a single debit/credit line of an
 * AccountingJournalEntry. Append-only, mirroring the parent entry and
 * TrustLedgerEntry exactly: the model's own booted() hook throws
 * \LogicException on ANY update or delete. No $timestamps — a posting
 * has no independent lifecycle beyond its parent journal entry's
 * created_at.
 */
class AccountingPosting extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'firm_id',
        'accounting_journal_entry_id',
        'chart_of_account_id',
        'client_id',
        'matter_id',
        'debit_cents',
        'credit_cents',
        'memo',
    ];

    protected function casts(): array
    {
        return [
            'debit_cents' => 'integer',
            'credit_cents' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new \LogicException(
                'accounting_postings is append-only: an existing row can never be updated. Post a reversing journal entry instead.'
            );
        });

        static::deleting(function () {
            throw new \LogicException(
                'accounting_postings is append-only: an existing row can never be deleted. Post a reversing journal entry instead.'
            );
        });
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(AccountingJournalEntry::class, 'accounting_journal_entry_id');
    }

    public function chartOfAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }
}
