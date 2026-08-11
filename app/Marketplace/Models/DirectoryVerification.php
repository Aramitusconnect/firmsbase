<?php

declare(strict_types=1);

namespace App\Marketplace\Models;

use App\Marketplace\Enums\VerificationDimension;
use App\Marketplace\Enums\VerificationSource;
use App\Marketplace\Enums\VerificationState;
use App\Models\Concerns\HasPublicUuid;
use App\Models\PlatformAdmin;
use Database\Factories\DirectoryVerificationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * DirectoryVerification — Mission 2 (MyAttorney Marketplace Core),
 * section 24. See the migration's own docblock for the polymorphic-
 * subject and RLS-exemption reasoning. A "dumb" model — every state
 * transition/guard lives in MarketplaceVerificationService, matching
 * DirectoryClaim's own established convention.
 */
class DirectoryVerification extends Model
{
    use HasFactory, HasPublicUuid;

    protected $fillable = [
        'verifiable_type',
        'verifiable_id',
        'dimension',
        'state',
        'source',
        'verified_at',
        'verified_by_platform_admin_id',
        'expires_at',
        'revoked_at',
        'revocation_reason',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'dimension' => VerificationDimension::class,
            'state' => VerificationState::class,
            'source' => VerificationSource::class,
            'verified_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    protected static function newFactory(): DirectoryVerificationFactory
    {
        return DirectoryVerificationFactory::new();
    }

    public function verifiable(): MorphTo
    {
        return $this->morphTo();
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'verified_by_platform_admin_id');
    }

    public function isCurrentlyVerified(): bool
    {
        if ($this->state !== VerificationState::Verified) {
            return false;
        }

        return $this->expires_at === null || $this->expires_at->isFuture();
    }
}
