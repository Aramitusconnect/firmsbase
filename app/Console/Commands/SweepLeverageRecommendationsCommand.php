<?php

namespace App\Console\Commands;

use App\Enums\FirmActivationStatus;
use App\Enums\MatterStatus;
use App\Models\Firm;
use App\Models\Matter;
use App\Models\MatterBudget;
use App\Models\MatterLeverageRecommendation;
use App\Services\Leverage\LeverageRecommendationLifecycleService;
use App\Services\Leverage\LeverageRecommendationService;
use App\Services\TenantContextService;
use Illuminate\Console\Command;

/**
 * automation:sweep:leverage-recommendations — Leverage Ratio
 * Optimizer, item 22/24. Evaluates LeverageRecommendationService for
 * every Matter that has a budget configured, for every activated firm
 * — the exact same "enumerate matter_budgets' distinct matter_id
 * within a firm context" shape as SweepMatterBudgetAlertsCommand,
 * since a Leverage recommendation always needs the same
 * MatterBudgetAnalysis a budget alert needs. Never touches a Closed/
 * Archived matter.
 *
 * Also marks any recommendation still Open/Acknowledged past the
 * staleness window as Stale (LeverageRecommendationLifecycleService's
 * own docblock: "called by the sweep command, not exposed as a direct
 * UI action") — a recommendation nobody acted on for this long is a
 * signal that it stopped being actionable, not something to keep
 * surfacing forever.
 */
final class SweepLeverageRecommendationsCommand extends Command
{
    protected $signature = 'automation:sweep:leverage-recommendations';

    protected $description = 'Evaluates staffing-leverage recommendations and marks stale ones, for every activated firm.';

    private const STALENESS_DAYS = 30;

    public function __construct(
        private readonly LeverageRecommendationService $recommendationService,
        private readonly LeverageRecommendationLifecycleService $lifecycleService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        Firm::query()
            ->where('activation_status', FirmActivationStatus::Activated)
            ->cursor()
            ->each(fn (Firm $firm) => $this->sweepFirm($firm));

        return self::SUCCESS;
    }

    private function sweepFirm(Firm $firm): void
    {
        (new TenantContextService)->runWithFirmContext($firm, function () use ($firm) {
            $matterIds = MatterBudget::query()
                ->where('firm_id', $firm->id)
                ->distinct()
                ->pluck('matter_id');

            foreach ($matterIds as $matterId) {
                $this->sweepMatter($matterId);
            }

            $this->markStaleRecommendations($firm);
        });
    }

    private function sweepMatter(int $matterId): void
    {
        $matter = Matter::query()->find($matterId);

        if ($matter === null || in_array($matter->status, [MatterStatus::Closed, MatterStatus::Archived], true)) {
            return;
        }

        $this->recommendationService->evaluate($matter);
    }

    private function markStaleRecommendations(Firm $firm): void
    {
        MatterLeverageRecommendation::query()
            ->where('firm_id', $firm->id)
            ->whereIn('status', ['open', 'acknowledged'])
            ->where('created_at', '<=', now()->subDays(self::STALENESS_DAYS))
            ->cursor()
            ->each(fn (MatterLeverageRecommendation $r) => $this->lifecycleService->markStale($r));
    }
}
