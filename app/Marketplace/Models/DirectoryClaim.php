<?php

declare(strict_types=1);

namespace App\Marketplace\Models;

use App\Marketplace\Enums\ClaimState;
use App\Models\Concerns\HasPublicUuid;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\PlatformAdmin;
use Database\Factories\DirectoryClaimFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DirectoryClaim — Mission 2 (MyAttorney Marketplace Core), sections
 * 20-23. See the migration's own docblock for the RLS-exemption
 * reasoning and the file-evidence deferral decision. Deliberately a
 * "dumb" model — every state transition, guard, and invariant lives in
 * MarketplaceClaimService, matching this codebase's established
 * services-own-business-logic convention (e.g.
 * PaymentAllocationResolutionService).
 */
class DirectoryClaim extends Model
{
    use HasFactory, HasPublicUuid;

    protected $fillable = [
        'directory_firm_id',
        'firm_id',
        'claimant_firm_user_id',
        'state',
        'claim_basis',
        'reviewer_notes',
        'rejection_reason',
        'revocation_reason',
        'conflicts_with_claim_id',
        'submitted_at',
        'decided_at',
        'decided_by_platform_admin_id',
        'revoked_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'state' => ClaimState::class,
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
            'revoked_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    protected static function newFactory(): DirectoryClaimFactory
    {
        return DirectoryClaimFactory::new();
    }

    public function directoryFirm(): BelongsTo
    {
        return $this->belongsTo(DirectoryFirm::class);
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function claimant(): BelongsTo
    {
        return $this->belongsTo(FirmUser::class, 'claimant_firm_user_id');
    }

    public function conflictsWith(): BelongsTo
    {
        return $this->belongsTo(self::class, 'conflicts_with_claim_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'decided_by_platform_admin_id');
    }
}
