<?php

namespace App\Models;

use App\Enums\RecordStatus;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * BillingAccount — platform billing only. Must never be mixed with
 * firm-client invoice/payment records (project rule 4). No
 * payment_method_ref column — that is explicitly out of scope for
 * Phase 1 (no payment processing yet). Not tenant-owned (it is part of
 * the tenancy/commercial boundary), so it does not use BelongsToTenant.
 */
class BillingAccount extends Model
{
    use HasFactory, HasPublicUuid;

    protected $fillable = [
        'name',
        'status',
        'billing_email',
    ];

    protected function casts(): array
    {
        return [
            'status' => RecordStatus::class,
        ];
    }

    public function firms(): HasMany
    {
        return $this->hasMany(Firm::class);
    }
}
