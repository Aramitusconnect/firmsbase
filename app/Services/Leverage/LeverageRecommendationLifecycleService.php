<?php

namespace App\Services\Leverage;

use App\Enums\MatterLeverageRecommendationStatus;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\MatterLeverageRecommendation;
use App\Services\MatterBudget\MatterBudgetAccessPolicyService;
use App\Services\TenantContextService;
use Illuminate\Support\Facades\DB;

/**
 * LeverageRecommendationLifecycleService — Leverage Ratio Optimizer,
 * item 24. The ONLY writer of a recommendation's own status/
 * acknowledgement/dismissal/resolution fields — LeverageRecommendationService
 * only ever writes the fact/evidence portion at creation time. Row-
 * locked, same discipline as AutomationApprovalService. History is
 * never deleted: dismiss()/resolve() both stamp who and when, never
 * clear a prior acknowledgement.
 */
class LeverageRecommendationLifecycleService
{
    public function __construct(private readonly MatterBudgetAccessPolicyService $accessPolicy) {}

    public function acknowledge(Firm $firm, MatterLeverageRecommendation $recommendation, FirmUser $actor): MatterLeverageRecommendation
    {
        $this->assertAuthorized($actor);
        $this->assertBelongsToFirm($firm, $recommendation, $actor);

        return (new TenantContextService)->runWithFirmContext($firm, function () use ($recommendation, $actor) {
            return DB::transaction(function () use ($recommendation, $actor) {
                $locked = MatterLeverageRecommendation::query()->whereKey($recommendation->id)->lockForUpdate()->firstOrFail();

                if ($locked->status !== MatterLeverageRecommendationStatus::Open) {
                    throw new \RuntimeException('Only an Open recommendation may be acknowledged.');
                }

                $locked->update([
                    'status' => MatterLeverageRecommendationStatus::Acknowledged,
                    'acknowledged_by_firm_user_id' => $actor->id,
                    'acknowledged_at' => now(),
                ]);

                return $locked->fresh();
            });
        });
    }

    public function dismiss(Firm $firm, MatterLeverageRecommendation $recommendation, FirmUser $actor, ?string $reason = null): MatterLeverageRecommendation
    {
        $this->assertAuthorized($actor);
        $this->assertBelongsToFirm($firm, $recommendation, $actor);

        return (new TenantContextService)->runWithFirmContext($firm, function () use ($recommendation, $actor, $reason) {
            return DB::transaction(function () use ($recommendation, $actor, $reason) {
                $locked = MatterLeverageRecommendation::query()->whereKey($recommendation->id)->lockForUpdate()->firstOrFail();

                if (! $locked->isOpen()) {
                    throw new \RuntimeException('This recommendation is not open or acknowledged.');
                }

                $locked->update([
                    'status' => MatterLeverageRecommendationStatus::Dismissed,
                    'dismissed_by_firm_user_id' => $actor->id,
                    'dismissed_at' => now(),
                    'resolution_notes' => $reason,
                ]);

                return $locked->fresh();
            });
        });
    }

    public function resolve(Firm $firm, MatterLeverageRecommendation $recommendation, FirmUser $actor, ?string $notes = null): MatterLeverageRecommendation
    {
        $this->assertAuthorized($actor);
        $this->assertBelongsToFirm($firm, $recommendation, $actor);

        return (new TenantContextService)->runWithFirmContext($firm, function () use ($recommendation, $notes) {
            return DB::transaction(function () use ($recommendation, $notes) {
                $locked = MatterLeverageRecommendation::query()->whereKey($recommendation->id)->lockForUpdate()->firstOrFail();

                if (! $locked->isOpen()) {
                    throw new \RuntimeException('This recommendation is not open or acknowledged.');
                }

                $locked->update([
                    'status' => MatterLeverageRecommendationStatus::Resolved,
                    'resolution_notes' => $notes,
                ]);

                return $locked->fresh();
            });
        });
    }

    /**
     * Marks every recommendation still Open/Acknowledged after a Firm-
     * configurable staleness window as Stale — never deleted, simply a
     * signal that nobody acted on it in time. Called by the sweep
     * command, not exposed as a direct UI action.
     */
    public function markStale(MatterLeverageRecommendation $recommendation): void
    {
        if (! $recommendation->isOpen()) {
            return;
        }

        $recommendation->update(['status' => MatterLeverageRecommendationStatus::Stale]);
    }

    private function assertAuthorized(FirmUser $actor): void
    {
        if (! $this->accessPolicy->canReviseMatterBudget($actor->role)) {
            throw new \RuntimeException('This user is not authorized to act on staffing-leverage recommendations.');
        }
    }

    private function assertBelongsToFirm(Firm $firm, MatterLeverageRecommendation $recommendation, FirmUser $actor): void
    {
        if ((int) $recommendation->firm_id !== (int) $firm->id || (int) $actor->firm_id !== (int) $firm->id) {
            throw new \RuntimeException('This recommendation does not belong to this firm.');
        }
    }
}
