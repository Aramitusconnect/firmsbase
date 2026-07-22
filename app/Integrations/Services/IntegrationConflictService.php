<?php

declare(strict_types=1);

namespace App\Integrations\Services;

use App\Integrations\Enums\ConflictStatus;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationConflict;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * IntegrationConflictService — the ONLY writer of
 * `integration_conflicts` (Checkpoint 6,
 * reviews/checkpoint-06/frozen-design-post-review.md §4/§6/§8;
 * agent-6f-mapping-conflict-design.md §3-§5). Checkpoint 6 production
 * code paths only ever call recordDetection() — no resolution
 * workflow/UI exists yet (Checkpoint 10/11 scope). transitionStatus()
 * exists as the required sole-writer state-transition PRIMITIVE
 * (mirroring ProviderConnectionService::transitionStatus()'s
 * precedent, per frozen-design-post-review.md §8's explicit
 * requirement that state-transition validity is a sole-writer-service
 * responsibility, not a DB trigger — this codebase has zero CREATE
 * TRIGGER precedent) so a future resolution checkpoint has a correct,
 * ready-made entry point rather than writing directly to the model.
 *
 * The five migration-level CHECK constraints are the PRIMARY,
 * DB-enforced safety mechanism and cannot be bypassed by any
 * application code, including this class. transitionStatus()'s own
 * validation is defense-in-depth on top of them, not a substitute —
 * removing this class's checks would surface as a DB exception instead
 * of a typed application exception, never as a silent bypass.
 */
final class IntegrationConflictService
{
    private const PRIVILEGED_RESOURCE_TYPES = ['invoice', 'payment', 'document', 'message'];

    /**
     * Atomic, idempotent detection write (frozen-design-post-review.md
     * §6): raw INSERT ... ON CONFLICT (firm_integration_id,
     * resource_type, local_type, local_id) WHERE status IN ('detected',
     * 'awaiting_review') DO NOTHING RETURNING *. MUST remain raw SQL —
     * Postgres requires the ON CONFLICT clause to repeat the partial
     * index's WHERE predicate exactly, which Laravel's fluent
     * insertOrIgnoreReturning()/upsert() uniqueBy CANNOT express
     * (agent-6c §10's documented trap). A second concurrent detection
     * of the "same" still-open conflict silently references the
     * existing open row rather than raising an error or duplicating.
     *
     * requires_manual_review is forced true whenever resource_type is
     * one of the four privileged/financial types, regardless of the
     * caller-supplied value — mirrors the migration's own
     * flag_matches_resource_type CHECK constraint so an ordinary
     * detection insert never fails at the DB layer for a classification
     * omission the caller should not need to get right by hand.
     */
    public function recordDetection(
        FirmIntegration $connection,
        string $resourceType,
        string $localType,
        int $localId,
        string $conflictType,
        ?array $localValue = null,
        ?array $externalValue = null,
        ?int $syncItemId = null,
        ?int $externalMappingId = null,
        bool $requiresManualReview = false,
        ?string $localVersionToken = null,
        ?string $externalVersionToken = null,
    ): IntegrationConflict {
        $requiresManualReview = $requiresManualReview || in_array($resourceType, self::PRIVILEGED_RESOURCE_TYPES, true);

        $row = DB::selectOne(
            'INSERT INTO integration_conflicts '.
            '(firm_id, firm_integration_id, sync_item_id, external_mapping_id, resource_type, local_type, '.
            'local_id, conflict_type, local_value, external_value, local_version_token, external_version_token, '.
            'status, requires_manual_review, detected_at, created_at, updated_at) '.
            "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'detected', ?, now(), now(), now()) ".
            'ON CONFLICT (firm_integration_id, resource_type, local_type, local_id) '.
            "WHERE status IN ('detected', 'awaiting_review') ".
            'DO NOTHING '.
            'RETURNING *',
            [
                $connection->firm_id, $connection->id, $syncItemId, $externalMappingId, $resourceType, $localType,
                $localId, $conflictType,
                $localValue === null ? null : json_encode($localValue, JSON_THROW_ON_ERROR),
                $externalValue === null ? null : json_encode($externalValue, JSON_THROW_ON_ERROR),
                $localVersionToken, $externalVersionToken, $requiresManualReview,
            ]
        );

        if ($row !== null) {
            return IntegrationConflict::hydrate([(array) $row])->first();
        }

        return IntegrationConflict::query()
            ->where('firm_integration_id', $connection->id)
            ->where('resource_type', $resourceType)
            ->where('local_type', $localType)
            ->where('local_id', $localId)
            ->whereIn('status', ['detected', 'awaiting_review'])
            ->firstOrFail();
    }

    /**
     * Sole-writer state-transition primitive. Validates, in application
     * code, everything the DB CHECK constraints ALSO independently
     * enforce (defense in depth, per class docblock) plus the one thing
     * the CHECK constraints structurally cannot see: that $conflict is
     * currently in an OPEN state (Detected/AwaitingReview) before
     * transitioning — Postgres CHECK constraints cannot reference a
     * row's prior value.
     */
    public function transitionStatus(
        IntegrationConflict $conflict,
        ConflictStatus $newStatus,
        ?int $resolvedByFirmUserId = null,
        ?int $resolutionApprovedByFirmUserId = null,
        ?string $resolutionNote = null,
    ): IntegrationConflict {
        if (! $conflict->isOpen()) {
            throw new RuntimeException(
                "Conflict {$conflict->id} cannot transition to {$newStatus->value}: it is not currently open ".
                "(current status: {$conflict->status->value})."
            );
        }

        if ($newStatus === ConflictStatus::Expired && $conflict->requires_manual_review) {
            throw new RuntimeException(
                "Conflict {$conflict->id} cannot silently expire: requires_manual_review is true."
            );
        }

        $isResolvedShaped = $newStatus->isResolvedShaped();
        $isPrivileged = in_array($conflict->resource_type, self::PRIVILEGED_RESOURCE_TYPES, true);

        if ($isResolvedShaped && ($resolvedByFirmUserId === null)) {
            throw new RuntimeException("Conflict {$conflict->id} cannot resolve without resolved_by_firm_user_id.");
        }

        if ($isResolvedShaped && ($isPrivileged || $conflict->requires_manual_review)) {
            if ($resolutionApprovedByFirmUserId === null || $resolutionApprovedByFirmUserId === $resolvedByFirmUserId) {
                throw new RuntimeException(
                    "Conflict {$conflict->id} is privileged/flagged for manual review and requires a distinct, ".
                    'non-null resolution_approved_by_firm_user_id before it may resolve.'
                );
            }
        }

        $conflict->update([
            'status' => $newStatus,
            'resolved_by_firm_user_id' => $isResolvedShaped ? $resolvedByFirmUserId : $conflict->resolved_by_firm_user_id,
            'resolution_approved_by_firm_user_id' => $isResolvedShaped
                ? $resolutionApprovedByFirmUserId
                : $conflict->resolution_approved_by_firm_user_id,
            'resolution_note' => $resolutionNote ?? $conflict->resolution_note,
            'resolved_at' => $isResolvedShaped ? now() : $conflict->resolved_at,
        ]);

        return $conflict->fresh();
    }
}
