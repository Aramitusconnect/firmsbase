<?php

declare(strict_types=1);

namespace App\Marketplace\Services;

use App\Marketplace\Enums\MarketplaceAnalyticsEventType;
use App\Marketplace\Models\DirectoryAttorney;
use App\Marketplace\Models\DirectoryFirm;
use App\Marketplace\Models\MarketplaceAnalyticsEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * MarketplaceAnalyticsReportingService — Mission 2 (MyAttorney
 * Marketplace Core), checkpoint 13. The read-only aggregate-query
 * counterpart to MarketplaceAnalyticsService — kept as a separate
 * service (the public write path stays minimal and auditable; this
 * one is free to grow more query surface over time without touching
 * the write path at all). Every method aggregates across ALL rows;
 * none ever returns a single raw event row to a caller — there is
 * nothing per-row worth exposing (no actor, no IP) and this keeps the
 * Admin surface honestly "aggregate reporting," not an event browser.
 */
class MarketplaceAnalyticsReportingService
{
    public function totalViewsSince(Carbon $since): int
    {
        return MarketplaceAnalyticsEvent::query()
            ->whereIn('event_type', [MarketplaceAnalyticsEventType::FirmProfileViewed, MarketplaceAnalyticsEventType::AttorneyProfileViewed])
            ->where('occurred_at', '>=', $since)
            ->count();
    }

    public function totalSearchesSince(Carbon $since): int
    {
        return MarketplaceAnalyticsEvent::query()
            ->where('event_type', MarketplaceAnalyticsEventType::SearchPerformed)
            ->where('occurred_at', '>=', $since)
            ->count();
    }

    /**
     * Mission 3 (MyAttorney Conversion + AI Intake), checkpoint 14.
     */
    public function totalIntakesStartedSince(Carbon $since): int
    {
        return $this->countByEventType(MarketplaceAnalyticsEventType::IntakeStarted, $since);
    }

    public function totalIntakesSubmittedSince(Carbon $since): int
    {
        return $this->countByEventType(MarketplaceAnalyticsEventType::IntakeSubmitted, $since);
    }

    public function totalIntakesAcceptedSince(Carbon $since): int
    {
        return $this->countByEventType(MarketplaceAnalyticsEventType::IntakeAccepted, $since);
    }

    public function totalIntakesDeclinedSince(Carbon $since): int
    {
        return $this->countByEventType(MarketplaceAnalyticsEventType::IntakeDeclined, $since);
    }

    public function totalIntakesConvertedSince(Carbon $since): int
    {
        return $this->countByEventType(MarketplaceAnalyticsEventType::IntakeConverted, $since);
    }

    private function countByEventType(MarketplaceAnalyticsEventType $type, Carbon $since): int
    {
        return MarketplaceAnalyticsEvent::query()
            ->where('event_type', $type)
            ->where('occurred_at', '>=', $since)
            ->count();
    }

    /**
     * @return Collection<int, array{firm: DirectoryFirm, views: int}>
     */
    public function topViewedFirms(Carbon $since, int $limit = 10): Collection
    {
        $counts = MarketplaceAnalyticsEvent::query()
            ->where('event_type', MarketplaceAnalyticsEventType::FirmProfileViewed)
            ->where('subject_type', DirectoryFirm::class)
            ->where('occurred_at', '>=', $since)
            ->select('subject_id', DB::raw('count(*) as views'))
            ->groupBy('subject_id')
            ->orderByDesc('views')
            ->limit($limit)
            ->get();

        $firms = DirectoryFirm::query()->whereIn('id', $counts->pluck('subject_id'))->get()->keyBy('id');

        return $counts
            ->map(fn ($row) => $firms->has((int) $row->subject_id)
                ? ['firm' => $firms[(int) $row->subject_id], 'views' => (int) $row->views]
                : null)
            ->filter()
            ->values();
    }

    /**
     * @return Collection<int, array{attorney: DirectoryAttorney, views: int}>
     */
    public function topViewedAttorneys(Carbon $since, int $limit = 10): Collection
    {
        $counts = MarketplaceAnalyticsEvent::query()
            ->where('event_type', MarketplaceAnalyticsEventType::AttorneyProfileViewed)
            ->where('subject_type', DirectoryAttorney::class)
            ->where('occurred_at', '>=', $since)
            ->select('subject_id', DB::raw('count(*) as views'))
            ->groupBy('subject_id')
            ->orderByDesc('views')
            ->limit($limit)
            ->get();

        $attorneys = DirectoryAttorney::query()->whereIn('id', $counts->pluck('subject_id'))->get()->keyBy('id');

        return $counts
            ->map(fn ($row) => $attorneys->has((int) $row->subject_id)
                ? ['attorney' => $attorneys[(int) $row->subject_id], 'views' => (int) $row->views]
                : null)
            ->filter()
            ->values();
    }

    /**
     * @return Collection<int, array{practice_area_slug: string, searches: int}>
     */
    public function topSearchedPracticeAreas(Carbon $since, int $limit = 10): Collection
    {
        return $this->topSearchDimension('practice_area_slug', $since, $limit)
            ->map(fn ($row) => ['practice_area_slug' => $row->value, 'searches' => (int) $row->searches]);
    }

    /**
     * @return Collection<int, array{city: string, searches: int}>
     */
    public function topSearchedCities(Carbon $since, int $limit = 10): Collection
    {
        return $this->topSearchDimension('city', $since, $limit)
            ->map(fn ($row) => ['city' => $row->value, 'searches' => (int) $row->searches]);
    }

    /**
     * @return Collection<int, object{value: string, searches: int}>
     */
    private function topSearchDimension(string $key, Carbon $since, int $limit): Collection
    {
        return MarketplaceAnalyticsEvent::query()
            ->where('event_type', MarketplaceAnalyticsEventType::SearchPerformed)
            ->where('occurred_at', '>=', $since)
            ->whereRaw("dimensions->>'{$key}' IS NOT NULL")
            ->select(DB::raw("dimensions->>'{$key}' as value"), DB::raw('count(*) as searches'))
            ->groupBy('value')
            ->orderByDesc('searches')
            ->limit($limit)
            ->get();
    }
}
