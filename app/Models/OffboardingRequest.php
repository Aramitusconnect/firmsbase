<?php

namespace App\Models;

use App\Enums\OffboardingRequestStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OffboardingRequest extends Model
{
    use HasFactory, HasPublicUuid, BelongsToTenant;

    protected $fillable = [
        'firm_id',
        'status',
        'reason',
        'requested_by_platform_admin_id',
        'requested_at',
        'completed_at',
        'cancelled_at',
        'cancelled_reason',
    ];

    protected function casts(): array
    {
        return [
            'status' => OffboardingRequestStatus::class,
            'requested_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function requestedByPlatformAdmin(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'requested_by_platform_admin_id');
    }

    public function exports(): HasMany
    {
        return $this->hasMany(OffboardingExport::class);
    }

    public function keyDestructionRequests(): HasMany
    {
        return $this->hasMany(KeyDestructionRequest::class);
    }
}
