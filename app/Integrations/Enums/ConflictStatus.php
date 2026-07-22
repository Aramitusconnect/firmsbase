<?php

declare(strict_types=1);

namespace App\Integrations\Enums;

/**
 * ConflictStatus — lifecycle state of an `integration_conflicts` row
 * (Checkpoint 6, frozen-design-post-review.md §8;
 * agent-6f-mapping-conflict-design.md §4.1). Plain string column, no
 * DB-level enum type — the literal string values are ALSO referenced
 * directly inside this table's migration CHECK constraints (see
 * database/migrations/2026_09_05_054001_create_integration_conflicts_table.php),
 * so this enum's backed values and the migration's SQL string literals
 * must always be kept in lockstep by hand; there is no DB-level enum
 * type to enforce it structurally.
 *
 * All 7 cases exist in the schema now so a future resolution workflow
 * (Checkpoint 10/11) never needs a schema-changing migration to add a
 * state it already anticipated — but Checkpoint 6 code paths
 * (IntegrationConflictService::recordDetection()) ONLY ever write
 * `Detected`. No resolution workflow exists in this checkpoint.
 *
 * `Expired` is the ONE fully-automated terminal state (administrative
 * closure, no human actor) — deliberately excluded from the
 * "resolution requires a real actor" CHECK constraint and structurally
 * blocked outright for any row with `requires_manual_review = true`
 * (silent expiry is itself a form of un-audited auto-resolution).
 */
enum ConflictStatus: string
{
    case Detected = 'detected';
    case AwaitingReview = 'awaiting_review';
    case ResolvedLocalWins = 'resolved_local_wins';
    case ResolvedRemoteWins = 'resolved_remote_wins';
    case ResolvedMerged = 'resolved_merged';
    case Ignored = 'ignored';
    case Expired = 'expired';

    /**
     * The two non-terminal states — matches the partial unique index
     * predicate `WHERE status IN ('detected', 'awaiting_review')` on
     * integration_conflicts exactly (the "one open conflict per mapped
     * local record" constraint).
     *
     * @return array<int, self>
     */
    public static function openStates(): array
    {
        return [self::Detected, self::AwaitingReview];
    }

    /**
     * The four resolved-SHAPED terminal statuses referenced by every
     * dual-approval CHECK constraint on this table. Deliberately
     * excludes `Expired` (fully-automated, no actor) even though it is
     * also terminal.
     *
     * @return array<int, self>
     */
    public static function resolvedShapedStates(): array
    {
        return [self::ResolvedLocalWins, self::ResolvedRemoteWins, self::ResolvedMerged, self::Ignored];
    }

    public function isOpen(): bool
    {
        return in_array($this, self::openStates(), true);
    }

    public function isResolvedShaped(): bool
    {
        return in_array($this, self::resolvedShapedStates(), true);
    }
}
