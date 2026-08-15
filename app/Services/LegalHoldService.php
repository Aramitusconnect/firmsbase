<?php

namespace App\Services;

use App\Enums\LegalHoldScope;
use App\Enums\LegalHoldStatus;
use App\Models\Client;
use App\Models\Document;
use App\Models\Firm;
use App\Models\LegalHold;
use App\Models\Matter;
use App\ValueObjects\LegalHoldCheckResult;

/**
 * LegalHoldService — hasActiveHold()/checkHold() is the single check
 * every other Phase 17 service must call before allowing deletion or
 * key destruction. A hold does NOT block export or archive (Master Plan
 * edge-case table, page 50) — callers that need to allow export under
 * hold simply do not call this service at all for that path.
 */
class LegalHoldService
{
    public function place(
        Firm $firm,
        LegalHoldScope $scope,
        string $reason,
        object $placedBy,
        ?Client $client = null,
        ?Matter $matter = null,
        ?Document $document = null,
    ): LegalHold {
        return (new TenantContextService)->runWithFirmContext($firm, fn () => LegalHold::create([
            'firm_id' => $firm->id,
            'scope_type' => $scope,
            'client_id' => $client?->id,
            'matter_id' => $matter?->id,
            'document_id' => $document?->id,
            'reason' => $reason,
            'status' => LegalHoldStatus::Active,
            'placed_by_type' => $placedBy::class,
            'placed_by_id' => $placedBy->id,
            'placed_at' => now(),
        ]));
    }

    public function release(LegalHold $hold, object $releasedBy, string $reason): LegalHold
    {
        return (new TenantContextService)->runWithFirmContext($hold->firm_id, function () use ($hold, $releasedBy, $reason) {
            $hold->update([
                'status' => LegalHoldStatus::Released,
                'released_by_type' => $releasedBy::class,
                'released_by_id' => $releasedBy->id,
                'released_at' => now(),
                'release_reason' => $reason,
            ]);

            return $hold->fresh();
        });
    }

    /**
     * A Firm-scope active hold blocks everything under that firm
     * regardless of the more specific scope/subject being checked — a
     * hold placed at the firm level is a superset block.
     *
     * FAIL-CLOSED GUARANTEE (Governance console mission, Phase 1). This
     * method establishes its own tenant context for $firm rather than
     * trusting the caller to have established it. `legal_holds` carries
     * permanent FORCE ROW LEVEL SECURITY (see
     * 2026_08_28_960001_prepare_row_level_security_and_force_rls_on_legal_holds_table.php),
     * and an unwrapped SELECT under FORCE returns ZERO ROWS rather than
     * raising — so an absent or wrong ambient context previously turned
     * "an active hold exists" into a silent "not blocked". That is a
     * false negative on the single gate protecting every destructive
     * governance workflow, and it was empirically reproducible on this
     * service before this wrap was added: a real, Active, Firm-scope
     * hold read back as `blocked: false`.
     *
     * The prior contract ("this shared helper never self-wraps; each of
     * its callers wraps its own call") kept the three then-existing
     * destructive callers correct, but only by convention enforced in
     * code comments — every NEW caller silently inherited the fail-open
     * default, which is the wrong default for a safety gate. The wrap
     * now lives at the gate itself, so the safe behaviour is structural
     * rather than remembered.
     *
     * This narrows rather than widens visibility, and grants nothing:
     * the context established is exactly the $firm the caller already
     * passed and whose id every query below already filters on, so no
     * row becomes readable that this method would not otherwise have
     * selected. RLS remains enabled and FORCE remains on; nothing is
     * bypassed, no exception is swallowed, and an unresolvable $firm
     * propagates out as a throw (fail closed) instead of degrading to
     * "no hold".
     *
     * Nesting is safe and is the normal case: the three existing
     * callers still wrap their own calls, and runWithFirmContext()
     * snapshots and restores whatever context was active beforehand —
     * including inside an already-open outer transaction — rather than
     * unconditionally clearing it (see TenantContextService's own
     * docblock and TenantContextServiceSessionScopedNestingTest).
     */
    public function checkHold(Firm $firm, LegalHoldScope $scope, ?int $subjectId = null): LegalHoldCheckResult
    {
        return (new TenantContextService)->runWithFirmContext($firm, function () use ($firm, $scope, $subjectId) {
            $firmLevelHoldIds = LegalHold::query()
                ->where('firm_id', $firm->id)
                ->where('scope_type', LegalHoldScope::Firm->value)
                ->where('status', LegalHoldStatus::Active->value)
                ->pluck('id')
                ->all();

            if (! empty($firmLevelHoldIds)) {
                return LegalHoldCheckResult::blockedBy($firmLevelHoldIds);
            }

            if ($scope === LegalHoldScope::Firm) {
                return LegalHoldCheckResult::notBlocked();
            }

            $column = match ($scope) {
                LegalHoldScope::Client => 'client_id',
                LegalHoldScope::Matter => 'matter_id',
                LegalHoldScope::Document => 'document_id',
                LegalHoldScope::Firm => null,
            };

            $matchingHoldIds = LegalHold::query()
                ->where('firm_id', $firm->id)
                ->where('scope_type', $scope->value)
                ->where('status', LegalHoldStatus::Active->value)
                ->where($column, $subjectId)
                ->pluck('id')
                ->all();

            if (! empty($matchingHoldIds)) {
                return LegalHoldCheckResult::blockedBy($matchingHoldIds);
            }

            return LegalHoldCheckResult::notBlocked();
        });
    }

    public function hasActiveHold(Firm $firm, LegalHoldScope $scope, ?int $subjectId = null): bool
    {
        return $this->checkHold($firm, $scope, $subjectId)->blocked;
    }
}
