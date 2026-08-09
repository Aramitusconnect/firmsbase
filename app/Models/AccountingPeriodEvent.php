<?php

namespace App\Models;

use App\Enums\AccountingPeriodEventType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AccountingPeriodEvent — Accounting Integrity Hardening Pass, item 7.
 * Append-only, mirroring TrustApprovalEvent exactly: one immutable row
 * per close/reopen transition, enforced by the booted() guard below —
 * never a second, mutable log a future bug could silently overwrite.
 */
class AccountingPeriodEvent extends Model
{
    use HasFactory;

    const UPDATED_AT = null;

    protected $fillable = [
        'firm_id',
        'accounting_period_id',
        'event_type',
        'actor_firm_user_id',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'event_type' => AccountingPeriodEventType::class,
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new \LogicException('accounting_period_events is append-only and cannot be updated.');
        });

        static::deleting(function () {
            throw new \LogicException('accounting_period_events is append-only and cannot be deleted.');
        });
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function accountingPeriod(): BelongsTo
    {
        return $this->belongsTo(AccountingPeriod::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(FirmUser::class, 'actor_firm_user_id');
    }
}
