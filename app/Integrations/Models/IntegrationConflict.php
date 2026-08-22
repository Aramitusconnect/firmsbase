<?php

declare(strict_types=1);

namespace App\Integrations\Models;

use App\Integrations\Enums\ConflictStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Firm;
use App\Models\FirmUser;
use Database\Factories\IntegrationConflictFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * IntegrationConflict — a detected divergence between a FirmsBase-side
 * record and its mapped external counterpart (Checkpoint 6,
 * reviews/checkpoint-06/frozen-design-post-review.md §4/§6/§8;
 * agent-6f-mapping-conflict-design.md §3-§5). Direct firm-owned.
 *
 * Structural auto-resolution block: the DB CHECK constraints on this
 * table's create migration are the PRIMARY safety mechanism (they read
 * `resource_type` directly and cannot be bypassed by application code
 * at all) — this model's role is secondary, defense-in-depth
 * discipline: `status`/actor columns are mutated ONLY through the
 * sole-writer IntegrationConflictService.
 *
 * Compensating control for the disclosed resolved_by_firm_user_id /
 * resolution_approved_by_firm_user_id bare-FK gap (identical,
 * disclosed Checkpoint 3/5 composite-FK-impossibility — firm_users
 * carries only UNIQUE(user_id, firm_id), not UNIQUE(firm_id, id)):
 * this model's `saving` listener, shaped verbatim after
 * FirmIntegration::assertConnectedByFirmUserBelongsToSameFirm(),
 * independently verifies BOTH actor columns' referenced firm_users
 * rows belong to this row's own firm_id, whenever either is set. This
 * never widens tenant isolation (FORCE RLS remains the actual
 * isolation boundary) — it only prevents a narrower audit-attribution-
 * integrity gap.
 *
 * newFactory() override is REQUIRED (STANDING CONVENTION,
 * checkpoint-00-final-specification.md §6).
 */
class IntegrationConflict extends Model
{
    use BelongsToTenant, HasFactory;

    protected $table = 'integration_conflicts';

    protected $fillable = [
        'firm_id',
        'firm_integration_id',
        'sync_item_id',
        'external_mapping_id',
        'resource_type',
        'local_type',
        'local_id',
        'conflict_type',
        'local_value',
        'external_value',
        'local_version_token',
        'external_version_token',
        'status',
        'requires_manual_review',
        'resolved_by_firm_user_id',
        'resolution_approved_by_firm_user_id',
        'resolution_note',
        'resolved_at',
        'detected_at',
        'expires_at',
    ];

    protected static function booted(): void
    {
        static::saving(function (IntegrationConflict $model): void {
            $model->assertActorBelongsToSameFirm('resolved_by_firm_user_id');
            $model->assertActorBelongsToSameFirm('resolution_approved_by_firm_user_id');
        });
    }

    protected function casts(): array
    {
        return [
            'status' => ConflictStatus::class,
            'local_value' => 'array',
            'external_value' => 'array',
            'requires_manual_review' => 'boolean',
            'resolved_at' => 'datetime',
            'detected_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    protected static function newFactory(): IntegrationConflictFactory
    {
        return IntegrationConflictFactory::new();
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function firmIntegration(): BelongsTo
    {
        return $this->belongsTo(FirmIntegration::class);
    }

    public function syncItem(): BelongsTo
    {
        return $this->belongsTo(IntegrationSyncItem::class, 'sync_item_id');
    }

    public function externalMapping(): BelongsTo
    {
        return $this->belongsTo(IntegrationExternalMapping::class, 'external_mapping_id');
    }

    public function resolvedByFirmUser(): BelongsTo
    {
        return $this->belongsTo(FirmUser::class, 'resolved_by_firm_user_id');
    }

    public function resolutionApprovedByFirmUser(): BelongsTo
    {
        return $this->belongsTo(FirmUser::class, 'resolution_approved_by_firm_user_id');
    }

    public function isOpen(): bool
    {
        return $this->status instanceof ConflictStatus && $this->status->isOpen();
    }

    /**
     * The compensating, application-level control substituting for the
     * disclosed resolved_by_firm_user_id / resolution_approved_by_firm_user_id
     * bare-FK gap (see class docblock). Deliberately looks up the
     * referenced firm_users row WITHOUT the BelongsToTenant global
     * scope, so the check reflects that row's real, unfiltered firm_id
     * rather than silently passing (via a "not found" false negative)
     * whenever no tenant context, or the wrong tenant context, happens
     * to be active. firm_users' own FORCE RLS policy may still narrow
     * the underlying DB read to the active session's firm — that is an
     * additional, independent layer, not a substitute for this
     * explicit comparison.
     */
    private function assertActorBelongsToSameFirm(string $column): void
    {
        $firmUserId = $this->getAttribute($column);

        if (empty($firmUserId)) {
            return;
        }

        if (empty($this->firm_id)) {
            throw new RuntimeException(
                "integration_conflicts.firm_id must be set before {$column} can be validated."
            );
        }

        $firmUser = FirmUser::query()
            ->withoutGlobalScope('tenant')
            ->find($firmUserId);

        if ($firmUser === null || (int) $firmUser->firm_id !== (int) $this->firm_id) {
            throw new RuntimeException(
                "{$column} must reference a firm_users row belonging to the same firm_id as this ".
                'integration_conflicts row (disclosed compensating control for the bare-FK gap — see this '.
                "model's class docblock)."
            );
        }
    }
}
