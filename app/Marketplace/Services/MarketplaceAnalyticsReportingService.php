<?php

declare(strict_types=1);

namespace App\Marketplace\Services;

use App\Marketplace\Enums\DirectoryPublicationState;
use App\Marketplace\Enums\MarketplaceAnalyticsEventType;
use App\Marketplace\Models\DirectoryAttorney;
use App\Marketplace\Models\DirectoryFirm;
use App\Marketplace\Models\MarketplaceAnalyticsEvent;
use App\Models\PracticeArea;
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

    /**
     * SuperAdmin console professionalization mission (MYAT7). Bounded-
     * window counterparts to totalViewsSince()/totalSearchesSince(),
     * added so the Analytics page can compare a period against an
     * equal-length prior period without changing either existing
     * method's open-ended ">= $since" contract (other callers —
     * PlatformAiOversightPage's own funnel section — depend on that
     * shape staying exactly as-is).
     */
    public function totalViewsBetween(Carbon $from, Carbon $to): int
    {
        return MarketplaceAnalyticsEvent::query()
            ->whereIn('event_type', [MarketplaceAnalyticsEventType::FirmProfileViewed, MarketplaceAnalyticsEventType::AttorneyProfileViewed])
            ->whereBetween('occurred_at', [$from, $to])
            ->count();
    }

    public function totalSearchesBetween(Carbon $from, Carbon $to): int
    {
        return MarketplaceAnalyticsEvent::query()
            ->where('event_type', MarketplaceAnalyticsEventType::SearchPerformed)
            ->whereBetween('occurred_at', [$from, $to])
            ->count();
    }

    /**
     * Directory-performance breakdown (section 9C): firm profile views
     * grouped by the viewed firm's CURRENT status flag — deliberately
     * "current" rather than "status at the time of the view" (this
     * event stream never stored a snapshot of the firm's status at
     * view time, only subject_id, so this answers "of today's claimed
     * firms, how many views did they get in this window" rather than
     * "how many views happened while a firm was claimed").
     *
     * @return array{true: int, false: int}
     */
    public function firmViewsByClaimStatus(Carbon $since): array
    {
        return $this->firmViewsGroupedByBooleanColumn('is_claimed', $since);
    }

    /**
     * @return array{true: int, false: int}
     */
    public function firmViewsByMemberStatus(Carbon $since): array
    {
        return $this->firmViewsGroupedByBooleanColumn('is_marketplace_member', $since);
    }

    /**
     * @return array{true: int, false: int}
     */
    public function firmViewsByAcceptingInquiriesStatus(Carbon $since): array
    {
        return $this->firmViewsGroupedByBooleanColumn('accepting_inquiries', $since);
    }

    /**
     * @return array{true: int, false: int}
     */
    private function firmViewsGroupedByBooleanColumn(string $column, Carbon $since): array
    {
        $table = (new MarketplaceAnalyticsEvent)->getTable();

        $rows = DB::table($table)
            ->join('directory_firms', 'directory_firms.id', '=', "{$table}.subject_id")
            ->where("{$table}.event_type", MarketplaceAnalyticsEventType::FirmProfileViewed->value)
            ->where("{$table}.subject_type", DirectoryFirm::class)
            ->where("{$table}.occurred_at", '>=', $since)
            ->select("directory_firms.{$column} as flag", DB::raw('count(*) as views'))
            ->groupBy('flag')
            ->get();

        $trueViews = (int) ($rows->first(fn ($row) => (bool) $row->flag === true)->views ?? 0);
        $falseViews = (int) ($rows->first(fn ($row) => (bool) $row->flag === false)->views ?? 0);

        return ['true' => $trueViews, 'false' => $falseViews];
    }

    /**
     * Search intelligence (section 9B): searched practice areas next
     * to how many currently-Published firms actually offer that
     * practice area — lets a SuperAdmin spot demand the directory
     * cannot currently serve. Deliberately a NEW query, not a reuse of
     * private topSearchDimension() (kept private/unchanged for the
     * existing topSearchedPracticeAreas() contract) — this one also
     * needs the practice area's real id to count supply, which the
     * dimension-only query never resolves.
     *
     * @return Collection<int, array{practice_area_slug: string, searches: int, published_firms: int}>
     */
    public function demandVsSupplyByPracticeArea(Carbon $since, int $limit = 10): Collection
    {
        $demand = MarketplaceAnalyticsEvent::query()
            ->where('event_type', MarketplaceAnalyticsEventType::SearchPerformed)
            ->where('occurred_at', '>=', $since)
            ->whereRaw("dimensions->>'practice_area_slug' IS NOT NULL")
            ->select(DB::raw("dimensions->>'practice_area_slug' as slug"), DB::raw('count(*) as searches'))
            ->groupBy('slug')
            ->orderByDesc('searches')
            ->limit($limit)
            ->get();

        return $demand->map(function ($row) {
            $practiceArea = PracticeArea::query()->where('slug', $row->slug)->first();

            $publishedFirms = $practiceArea !== null
                ? DirectoryFirm::query()
                    ->where('publication_state', DirectoryPublicationState::Published)
                    ->whereHas('practiceAreas', fn ($query) => $query->where('practice_areas.id', $practiceArea->id))
                    ->count()
                : 0;

            return [
                'practice_area_slug' => $row->slug,
                'searches' => (int) $row->searches,
                'published_firms' => $publishedFirms,
            ];
        });
    }
}
