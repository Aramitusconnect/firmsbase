<?php

declare(strict_types=1);

namespace App\Http\Controllers\MyAttorney;

use App\Http\Controllers\Controller;
use App\Marketplace\Enums\ConsultationMode;
use App\Marketplace\Services\MarketplaceRankingService;
use App\Marketplace\Services\MarketplaceSearchService;
use App\Marketplace\ViewModels\SearchCriteria;
use App\Models\Language;
use App\Models\PracticeArea;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;

/**
 * HomeController — Mission 2 (MyAttorney Marketplace Core), checkpoint
 * 5. The real search entry point, replacing checkpoint 4's "search
 * coming soon" placeholder. A blank query returns nothing (section
 * 45: never generate/browse every listing implicitly on the bare
 * landing page) — a real query is required to see results, keeping
 * this a search page, not an unfiltered full-directory dump.
 */
class HomeController extends Controller
{
    private const RESULTS_PER_PAGE = 20;

    public function index(Request $request, MarketplaceSearchService $search, MarketplaceRankingService $ranking): View
    {
        $hasQuery = $request->query->count() > 0;
        $criteria = SearchCriteria::fromArray($request->query());
        $allResults = $hasQuery ? $ranking->rank($search->candidates($criteria), $criteria) : [];

        $page = max(1, (int) $request->query('page', 1));
        $results = new LengthAwarePaginator(
            array_slice($allResults, ($page - 1) * self::RESULTS_PER_PAGE, self::RESULTS_PER_PAGE),
            count($allResults),
            self::RESULTS_PER_PAGE,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        return view('myattorney.home', [
            'criteria' => $criteria,
            'hasQuery' => $hasQuery,
            'results' => $results,
            'practiceAreas' => PracticeArea::query()->where('is_marketplace_visible', true)->orderBy('sort_order')->get(),
            'languages' => Language::query()->where('is_active', true)->orderBy('name')->get(),
            'consultationModes' => ConsultationMode::cases(),
        ]);
    }
}
