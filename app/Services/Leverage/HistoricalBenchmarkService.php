<?php

namespace App\Services\Leverage;

use App\Enums\MatterStatus;
use App\Models\Firm;
use App\Models\Matter;
use App\Models\MatterType;

/**
 * HistoricalBenchmarkService — Leverage Ratio Optimizer, items 15-16.
 * Deterministic, Firm-specific benchmarks only — reuses
 * LeverageAnalysisService's own per-Matter output rather than
 * recomputing shares/cost independently (no second profitability
 * engine, per the master spec's own item 2). NEVER combines data
 * across Firms: every query here is scoped to the single given Firm,
 * both a privacy and a product-quality boundary the spec states
 * explicitly (item 16).
 */
class HistoricalBenchmarkService
{
    private const MIN_SAMPLE_SIZE = 5;

    public function __construct(private readonly LeverageAnalysisService $leverageAnalysis) {}

    /**
     * @return array<string, mixed>
     */
    public function benchmarkForMatterType(Firm $firm, MatterType $matterType): array
    {
        $comparableMatters = Matter::query()
            ->where('firm_id', $firm->id)
            ->where('matter_type_id', $matterType->id)
            ->where('status', MatterStatus::Closed)
            ->whereNotNull('opened_at')
            ->whereNotNull('closed_at')
            ->get();

        $analyzed = $comparableMatters
            ->map(fn (Matter $matter) => [
                'matter' => $matter,
                'analysis' => $this->leverageAnalysis->analyze($matter),
            ])
            ->filter(fn (array $row) => $row['analysis']['has_budget'] && $row['analysis']['has_recorded_hours']);

        if ($analyzed->count() < self::MIN_SAMPLE_SIZE) {
            return [
                'matter_type_id' => $matterType->id,
                'sample_size' => $analyzed->count(),
                'minimum_sample_size' => self::MIN_SAMPLE_SIZE,
                'sufficient_sample' => false,
            ];
        }

        $margins = $analyzed->pluck('analysis.current_margin_percent')->filter(fn ($v) => $v !== null);

        return [
            'matter_type_id' => $matterType->id,
            'sample_size' => $analyzed->count(),
            'minimum_sample_size' => self::MIN_SAMPLE_SIZE,
            'sufficient_sample' => true,
            'average_attorney_share_percent' => round($analyzed->avg('analysis.attorney_share_percent'), 1),
            'average_support_share_percent' => round($analyzed->avg('analysis.support_share_percent'), 1),
            'average_labor_cost_cents' => (int) round($analyzed->avg('analysis.total_labor_cost_cents')),
            'average_margin_percent' => $margins->isNotEmpty() ? round($margins->avg(), 1) : null,
            'average_duration_days' => (int) round($analyzed->avg(fn (array $row) => $row['matter']->opened_at->diffInDays($row['matter']->closed_at))),
        ];
    }
}
