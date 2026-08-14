<?php

declare(strict_types=1);

namespace App\Marketplace\Services;

use App\Marketplace\Models\MarketplaceAiUsageEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * MarketplaceAiUsageReportingService — SuperAdmin console
 * professionalization mission (MYAT8). The read-only aggregate
 * counterpart to MarketplaceAiUsageRecorderService, mirroring
 * MarketplaceAnalyticsReportingService's own established shape (a
 * separate reporting class, never adding query surface to the write
 * path). Deliberately scoped to pre-Firm/pre-conversion MyAttorney AI
 * usage ONLY: marketplace_ai_usage_events' own RLS policy (see its
 * migration) only ever exposes firm_id IS NULL rows to a context-free
 * session, which is exactly what every request through this Admin
 * panel is — so these aggregates can never include, and never claim
 * to include, a firm's own in-matter AI activity (ai_usage_events),
 * which is FORCE RLS with no cross-tenant escape hatch at all. This
 * class does not attempt to bypass that; it queries only what a
 * context-free session already legitimately sees.
 */
class MarketplaceAiUsageReportingService
{
    public function callsSince(Carbon $since): int
    {
        return MarketplaceAiUsageEvent::query()
            ->where('created_at', '>=', $since)
            ->count();
    }

    /**
     * @return array{in: int, out: int}
     */
    public function tokensSince(Carbon $since): array
    {
        $row = MarketplaceAiUsageEvent::query()
            ->where('created_at', '>=', $since)
            ->selectRaw('coalesce(sum(tokens_in), 0) as tokens_in, coalesce(sum(tokens_out), 0) as tokens_out')
            ->first();

        return [
            'in' => (int) ($row->tokens_in ?? 0),
            'out' => (int) ($row->tokens_out ?? 0),
        ];
    }

    /**
     * @return Collection<int, array{provider: string, calls: int}>
     */
    public function byProviderSince(Carbon $since): Collection
    {
        return MarketplaceAiUsageEvent::query()
            ->where('created_at', '>=', $since)
            ->select('provider', DB::raw('count(*) as calls'))
            ->groupBy('provider')
            ->orderByDesc('calls')
            ->get()
            ->map(fn ($row) => ['provider' => $row->provider->value, 'calls' => (int) $row->calls]);
    }

    /**
     * @return Collection<int, array{model: string, calls: int}>
     */
    public function byModelSince(Carbon $since): Collection
    {
        return MarketplaceAiUsageEvent::query()
            ->where('created_at', '>=', $since)
            ->select('model', DB::raw('count(*) as calls'))
            ->groupBy('model')
            ->orderByDesc('calls')
            ->get()
            ->map(fn ($row) => ['model' => $row->model, 'calls' => (int) $row->calls]);
    }
}
