<?php

namespace App\Models;

use App\Enums\RetentionPolicyStatus;
use App\Enums\RetentionRecordType;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * RetentionPolicy — firm_id nullable: null means a platform-default
 * policy, set means a firm-specific override. Deliberately does NOT use
 * BelongsToTenant (matching the LicenseFile/Phase 16 precedent for
 * dual-purpose nullable-firm tables) — a platform-default row (firm_id
 * null) must remain resolvable by RetentionPolicyService even while a
 * tenant context is active for some firm; applying the tenant global
 * scope would incorrectly hide it.
 *
 * Mutable, not append-only: status legitimately transitions Draft ->
 * Active -> Superseded/Archived over the policy's lifecycle, the same
 * pattern as Matter/FirmLead status fields elsewhere in this codebase.
 */
class RetentionPolicy extends Model
{
    use HasFactory, HasPublicUuid;

    protected $fillable = [
        'firm_id',
        'record_type',
        'document_category',
        'practice_area',
        'jurisdiction',
        'retention_period_days',
        'is_permanent',
        'allows_client_replacement',
        'preserves_audit_history_required',
        'legal_basis',
        'status',
        'effective_at',
        'superseded_at',
        'supersedes_policy_id',
        'reason',
        'created_by_platform_admin_id',
        'created_by_firm_user_id',
    ];

    protected function casts(): array
    {
        return [
            'record_type' => RetentionRecordType::class,
            'retention_period_days' => 'integer',
            'is_permanent' => 'boolean',
            'allows_client_replacement' => 'boolean',
            'preserves_audit_history_required' => 'boolean',
            'status' => RetentionPolicyStatus::class,
            'effective_at' => 'datetime',
            'superseded_at' => 'datetime',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function supersedesPolicy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_policy_id');
    }

    public function createdByPlatformAdmin(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'created_by_platform_admin_id');
    }

    public function createdByFirmUser(): BelongsTo
    {
        return $this->belongsTo(FirmUser::class, 'created_by_firm_user_id');
    }

    public function isPlatformDefault(): bool
    {
        return $this->firm_id === null;
    }
}
