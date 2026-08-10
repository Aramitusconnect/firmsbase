<?php

namespace App\Console\Commands;

use App\Enums\FirmActivationStatus;
use App\Enums\MatterStatus;
use App\Models\Firm;
use App\Models\Matter;
use App\Models\MatterBudget;
use App\Services\MatterBudget\MatterBudgetAlertService;
use App\Services\MatterBudget\MatterBudgetAnalysisService;
use App\Services\TenantContextService;
use Illuminate\Console\Command;

/**
 * automation:sweep:matter-budgets — Predictive Matter Budget Alerts,
 * item 10/14. Recomputes MatterBudgetAnalysis and evaluates
 * MatterBudgetAlertService for every Matter that has a budget
 * configured, for every activated firm. Never touches a Matter with no
 * budget at all (recompute() itself returns null — "No Budget
 * Configured" is a UI-rendering concern, not a sweep concern) and
 * never a Closed/Archived matter (nothing left to track).
 *
 * Reuses the exact same "enumerate the non-RLS firms table, wrap each
 * firm's own work in runWithFirmContext()" shape as
 * SweepInvoiceOverdueEventsCommand/SweepDeadlineEventsCommand — this is
 * the third sweep in that same family, not a new pattern.
 */
final class SweepMatterBudgetAlertsCommand extends Command
{
    protected $signature = 'automation:sweep:matter-budgets';

    protected $description = 'Recomputes matter budget analyses and raises deduplicated threshold-crossing alerts, for every activated firm.';

    public function __construct(
        private readonly MatterBudgetAnalysisService $analysisService,
        private readonly MatterBudgetAlertService $alertService,
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
        });
    }

    private function sweepMatter(int $matterId): void
    {
        $matter = Matter::query()->find($matterId);

        if ($matter === null || in_array($matter->status, [MatterStatus::Closed, MatterStatus::Archived], true)) {
            return;
        }

        $budget = MatterBudget::query()
            ->where('matter_id', $matter->id)
            ->orderByDesc('version')
            ->first();

        if ($budget === null) {
            return;
        }

        $analysis = $this->analysisService->recompute($matter);

        if ($analysis === null) {
            return;
        }

        $this->alertService->evaluate($matter, $budget, $analysis);
    }
}
