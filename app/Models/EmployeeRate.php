<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * EmployeeRate — one billing rate + one internal cost rate per
 * employee, effective-dated (approved decision). Only
 * EmployeeRateService opens/closes rows; no uuid (internal admin
 * config only). "Employee" = a User acting in this firm — user_id is a
 * plain bigint FK, no relationship added to User.php.
 */
class EmployeeRate extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'firm_id',
        'user_id',
        'billing_rate_cents',
        'cost_rate_cents',
        'currency',
        'effective_from',
        'effective_to',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'datetime',
            'effective_to' => 'datetime',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isOpenEnded(): bool
    {
        return is_null($this->effective_to);
    }
}
