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
        return (new TenantContextService())->runWithFirmContext($firm, fn () => LegalHold::create([
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
        return (new TenantContextService())->runWithFirmContext($hold->firm_id, function () use ($hold, $releasedBy, $reason) {
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
     */
    public function checkHold(Firm $firm, LegalHoldScope $scope, ?int $subjectId = null): LegalHoldCheckResult
    {
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
    }

    public function hasActiveHold(Firm $firm, LegalHoldScope $scope, ?int $subjectId = null): bool
    {
        return $this->checkHold($firm, $scope, $subjectId)->blocked;
    }
}
