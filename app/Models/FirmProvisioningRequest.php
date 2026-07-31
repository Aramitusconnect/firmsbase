<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FirmProvisioningStatus;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FirmProvisioningRequest — the idempotency ledger for
 * FirmProvisioningService::provision(). See the create-table migration's
 * own docblock for why this is an ordinary FK-bearing table rather than
 * the FK-free shape `provider_operation_attempts` uses.
 *
 * Sole writer: FirmProvisioningService.
 */
class FirmProvisioningRequest extends Model
{
    use HasFactory, HasPublicUuid;

    protected $fillable = [
        'idempotency_key',
        'request_payload_hash',
        'requested_by_platform_admin_id',
        'firm_id',
        'owner_user_id',
        'status',
        'failure_category',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => FirmProvisioningStatus::class,
            'completed_at' => 'datetime',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function ownerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function requestedByPlatformAdmin(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'requested_by_platform_admin_id');
    }
}
