<?php

namespace App\Models;

use App\Enums\SupportAccessSessionStatus;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SupportAccessSession — time-limited, actor-bound. A session must
 * never be treated as authorizing access purely from its `status`
 * column: SupportAccessPolicyService/isCurrentlyValid() always also
 * checks expires_at against now(), so an "active"-flagged but expired
 * row can never authorize access.
 */
class SupportAccessSession extends Model
{
    use HasFactory, HasPublicUuid;

    protected $fillable = [
        'support_access_request_id',
        'firm_id',
        'platform_admin_id',
        'status',
        'started_at',
        'expires_at',
        'ended_at',
        'revoked_by',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => SupportAccessSessionStatus::class,
            'started_at' => 'datetime',
            'expires_at' => 'datetime',
            'ended_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function supportAccessRequest(): BelongsTo
    {
        return $this->belongsTo(SupportAccessRequest::class);
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function platformAdmin(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class);
    }

    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'revoked_by');
    }

    public function isCurrentlyValid(): bool
    {
        return $this->status === SupportAccessSessionStatus::Active
            && $this->expires_at !== null
            && $this->expires_at->isFuture();
    }
}
