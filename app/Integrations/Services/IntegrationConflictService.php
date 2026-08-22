<?php

declare(strict_types=1);

namespace App\Integrations\Services;

use App\Integrations\Enums\ConflictStatus;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationConflict;
use App\Models\FirmUser;
use App\Services\TimelineEventRecorder;
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

    public function __construct(private readonly TimelineEventRecorder $events) {}

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
            $conflict = IntegrationConflict::hydrate([(array) $row])->first();

            // Checkpoint 9 addition (frozen design §3): fires on the
            // INSERT branch only — never the DO NOTHING branch, which
            // silently references an already-existing open conflict
            // rather than genuinely detecting a new one.
            $this->events->record($connection->firm, 'integration_conflict.detected', $conflict, null, [
                'integration_conflict_id' => $conflict->id,
                'resource_type' => $resourceType,
                'conflict_type' => $conflictType,
                'requires_manual_review' => $requiresManualReview,
            ]);

            return $conflict;
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

        $fresh = $conflict->fresh();

        // Checkpoint 9 additions (frozen design §3):
        // `integration_conflict.resolved` fires on a terminal transition
        // into ANY resolved-shaped status (ResolvedLocalWins/
        // ResolvedRemoteWins/ResolvedMerged/Ignored);
        // `integration_conflict.expired` fires on the fully-automated
        // Expired transition only.
        if ($isResolvedShaped) {
            $resolvingActor = $resolvedByFirmUserId === null
                ? null
                : FirmUser::query()->find($resolvedByFirmUserId)?->user;

            $this->events->record($fresh->firm, 'integration_conflict.resolved', $fresh, $resolvingActor, [
                'integration_conflict_id' => $fresh->id,
                'new_status' => $fresh->status->value,
                'resolved_by_firm_user_id' => $resolvedByFirmUserId,
                'resolution_approved_by_firm_user_id' => $resolutionApprovedByFirmUserId,
            ]);
        } elseif ($newStatus === ConflictStatus::Expired) {
            $this->events->record($fresh->firm, 'integration_conflict.expired', $fresh, null, [
                'integration_conflict_id' => $fresh->id,
                'resource_type' => $fresh->resource_type,
            ]);
        }

        return $fresh;
    }

    /**
     * Checkpoint 10 addition (frozen-design-post-security-review.md §7;
     * agent-10h-architecture-security-review.md §6). The first half of
     * the two-actor dual-approval flow required for privileged/flagged
     * conflicts, since transitionStatus()'s own inline distinctness
     * check (lines above) makes a naive single-actor resolved-shaped
     * transition structurally impossible for those rows.
     *
     * Requires $conflict->isOpen() (same precondition as
     * transitionStatus()) and $proposedOutcome->isResolvedShaped()
     * (rejects AwaitingReview itself as a "proposed outcome" — it is not
     * a real resolution shape). Writes status = AwaitingReview (the
     * already-tested, non-resolved-shaped, no-actor-required transition
     * shape — see IntegrationConflictServiceTest's
     * test_transition_status_allows_detected_to_awaiting_review_with_no_actor_required)
     * plus resolved_by_firm_user_id = $proposingFirmUserId —
     * DELIBERATELY bypassing transitionStatus()'s resolved-shape branch
     * for this proposal step only, since the row remains
     * AwaitingReview/open, not resolved, after this call.
     * transitionStatus()'s own distinctness check remains the sole,
     * unmodified, un-bypassable enforcement for the LATER approval step
     * (Actor B's "Approve Resolution" action, which calls
     * transitionStatus() directly, unchanged).
     *
     * Only applicable to privileged/flagged conflicts — for
     * non-privileged, non-flagged conflicts, a single actor continues to
     * call transitionStatus() directly, unchanged.
     *
     * Actor authority (assertCanConfigure()) and entitlement
     * (assertEnabled()) are checked by the CALLER before invocation,
     * never inside this method — mirrors the frozen design's identical
     * ruling for requeue()/requeueFromFailedPermanent().
     */
    public function proposeResolution(
        IntegrationConflict $conflict,
        ConflictStatus $proposedOutcome,
        int $proposingFirmUserId,
        ?string $resolutionNote = null,
    ): IntegrationConflict {
        if (! $conflict->isOpen()) {
            throw new RuntimeException(
                "Conflict {$conflict->id} cannot propose a resolution: it is not currently open ".
                "(current status: {$conflict->status->value})."
            );
        }

        if (! $proposedOutcome->isResolvedShaped()) {
            throw new RuntimeException(
                "Conflict {$conflict->id} cannot propose {$proposedOutcome->value}: it is not a resolved-shaped outcome."
            );
        }

        $conflict->update([
            'status' => ConflictStatus::AwaitingReview,
            'resolved_by_firm_user_id' => $proposingFirmUserId,
            'resolution_note' => $resolutionNote ?? $conflict->resolution_note,
        ]);

        $fresh = $conflict->fresh();

        $proposingActor = FirmUser::query()->find($proposingFirmUserId)?->user;

        $this->events->record($fresh->firm, 'integration_conflict.resolution_proposed', $fresh, $proposingActor, [
            'integration_conflict_id' => $fresh->id,
            'proposed_outcome' => $proposedOutcome->value,
            'resolved_by_firm_user_id' => $proposingFirmUserId,
        ]);

        return $fresh;
    }
}
