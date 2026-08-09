<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * InvoiceWriteOff — Phase G. Append-only audit trail for
 * InvoiceWriteOffService::writeOff(). No accounting journal
 * consequence (see the creating migration's own docblock for why).
 */
class InvoiceWriteOff extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'firm_id',
        'invoice_id',
        'amount_cents',
        'reason',
        'actor_firm_user_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new \LogicException('invoice_write_offs is append-only: an existing row can never be updated.');
        });

        static::deleting(function () {
            throw new \LogicException('invoice_write_offs is append-only: an existing row can never be deleted.');
        });
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(FirmUser::class, 'actor_firm_user_id');
    }
}
