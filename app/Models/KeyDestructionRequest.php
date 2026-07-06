<?php

namespace App\Models;

use App\Enums\KeyDestructionRequestStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * KeyDestructionRequest — firm_id non-nullable, uses BelongsToTenant.
 * Deletion is always blocked once created (governance evidence); status
 * otherwise transitions freely through its own gated workflow
 * (KeyDestructionRequestService/KeyDestructionExecutionService).
 */
class KeyDestructionRequest extends Model
{
    use HasFactory, HasPublicUuid, BelongsToTenant;

    protected $fillable = [
        'firm_id',
        'offboarding_request_id',
        'tenant_encryption_key_id',
        'status',
        'reason',
        'requested_by_platform_admin_id',
        'requested_at',
        'executed_at',
        'cancelled_at',
        'cancelled_reason',
    ];

    protected function casts(): array
    {
        return [
            'status' => KeyDestructionRequestStatus::class,
            'requested_at' => 'datetime',
            'executed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function () {
            throw new \LogicException('key_destruction_requests rows can never be deleted — they are permanent governance evidence.');
        });
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function offboardingRequest(): BelongsTo
    {
        return $this->belongsTo(OffboardingRequest::class);
    }

    public function tenantEncryptionKey(): BelongsTo
    {
        return $this->belongsTo(TenantEncryptionKey::class);
    }

    public function requestedByPlatformAdmin(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'requested_by_platform_admin_id');
    }

    public function approval(): HasOne
    {
        return $this->hasOne(KeyDestructionApproval::class);
    }

    public function isApproved(): bool
    {
        return $this->status === KeyDestructionRequestStatus::Approved;
    }
}
