<?php

namespace App\Models;

use App\Enums\AccountingExportErrorSeverity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AccountingExportError — mirrors Phase 8's ImportError exactly: no
 * uuid, no own firm_id (scoped transitively through
 * accounting_export_line_id), append-only. Written exclusively by
 * AccountingExportErrorLogger. Immutable — an error row is never
 * updated or deleted once written (correction #9).
 */
class AccountingExportError extends Model
{
    use HasFactory;

    const UPDATED_AT = null;

    protected $fillable = [
        'accounting_export_line_id',
        'field',
        'severity',
        'message',
    ];

    protected function casts(): array
    {
        return [
            'severity' => AccountingExportErrorSeverity::class,
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new \LogicException('accounting_export_errors is append-only and cannot be updated.');
        });

        static::deleting(function () {
            throw new \LogicException('accounting_export_errors is append-only and cannot be deleted.');
        });
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(AccountingExportLine::class, 'accounting_export_line_id');
    }
}
