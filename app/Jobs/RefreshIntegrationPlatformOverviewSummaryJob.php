<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Firm;
use App\Services\IntegrationPlatformOverviewSummaryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * RefreshIntegrationPlatformOverviewSummaryJob — Checkpoint 11 (frozen-
 * design-post-security-review.md §5). The per-firm, per-tick unit of
 * queued work that refreshes one firm's
 * `integration_platform_overview_summaries` row, mirroring
 * App\Jobs\OutboxDispatchJob's exact two-layer shape:
 * App\Console\Commands\RefreshIntegrationPlatformOverviewSummariesCommand
 * (Layer 1, a plain, non-tenant scheduled command) enumerates activated
 * firms and dispatches one instance of this job per firm id.
 *
 * Constructor carries a scalar firm id only — never a Firm model, never
 * anything pre-fetched at dispatch time — the real Firm row is resolved
 * fresh inside handle().
 */
final class RefreshIntegrationPlatformOverviewSummaryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $firmId,
    ) {
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('platform-overview-refresh:'.$this->firmId))->releaseAfter(60)->expireAfter(900),
        ];
    }

    public function handle(IntegrationPlatformOverviewSummaryService $summaryService): void
    {
        $firm = Firm::query()->find($this->firmId);

        if ($firm === null) {
            // The firm was deleted between enumeration and this job
            // running — nothing to refresh; the summary row itself
            // cascade-deletes with its parent firm regardless.
            return;
        }

        $summaryService->refreshForFirm($firm);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('RefreshIntegrationPlatformOverviewSummaryJob: failed to refresh the platform overview summary for a firm.', [
            'firm_id' => $this->firmId,
            'exception' => $exception?->getMessage(),
        ]);
    }
}
