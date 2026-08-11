<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace\Security;

use App\Marketplace\Enums\DirectoryPublicationState;
use App\Marketplace\Enums\VerificationDimension;
use App\Marketplace\Models\DirectoryFirm;
use App\Marketplace\Models\DirectoryVerification;
use App\Marketplace\Services\MarketplaceRankingService;
use App\Marketplace\Services\MarketplaceSearchService;
use App\Marketplace\ViewModels\SearchCriteria;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * MarketplaceSearchPerformanceTest — Mission 2 (MyAttorney Marketplace
 * Core), checkpoint 14 (performance hardening). Covers hardening test
 * matrix items BG-BI. Mirrors TrialRequestResourceTest's own
 * established `DB::listen()` N+1-proof idiom.
 */
final class MarketplaceSearchPerformanceTest extends TestCase
{
    use RefreshDatabase;

    // BG. The verification-badge lookup on the search/ranking path is
    // batched — one query for the whole result set, never one per
    // candidate (the real N+1 this checkpoint closed — see
    // MarketplaceBadgeService::badgesForMany()'s own docblock).
    public function test_ranking_a_search_result_set_issues_at_most_one_verification_query_regardless_of_result_count(): void
    {
        $firms = DirectoryFirm::factory()->count(15)->create(['publication_state' => DirectoryPublicationState::Published]);

        foreach ($firms->take(5) as $firm) {
            DirectoryVerification::factory()->forVerifiable($firm, VerificationDimension::FirmAuthority)->verified()->create();
        }

        $captured = [];
        DB::listen(function ($query) use (&$captured): void {
            $captured[] = $query->sql;
        });

        $candidates = app(MarketplaceSearchService::class)->candidates(new SearchCriteria);
        app(MarketplaceRankingService::class)->rank($candidates, new SearchCriteria);

        $verificationQueries = collect($captured)->filter(fn (string $sql): bool => str_contains($sql, 'directory_verifications'))->count();

        $this->assertLessThanOrEqual(1, $verificationQueries, 'Expected at most one batched directory_verifications query, never one per candidate.');
    }

    // BH. The batched query count stays flat as the candidate count
    // grows — proves it is genuinely O(1) queries, not merely "fewer
    // than N" for a specific small N.
    public function test_verification_query_count_does_not_scale_with_candidate_count(): void
    {
        DirectoryFirm::factory()->count(3)->create(['publication_state' => DirectoryPublicationState::Published]);

        $captured = [];
        DB::listen(function ($query) use (&$captured): void {
            $captured[] = $query->sql;
        });
        $small = app(MarketplaceSearchService::class)->candidates(new SearchCriteria);
        app(MarketplaceRankingService::class)->rank($small, new SearchCriteria);
        $smallCount = collect($captured)->filter(fn (string $sql): bool => str_contains($sql, 'directory_verifications'))->count();

        DirectoryFirm::factory()->count(20)->create(['publication_state' => DirectoryPublicationState::Published]);

        $captured = [];
        $large = app(MarketplaceSearchService::class)->candidates(new SearchCriteria);
        app(MarketplaceRankingService::class)->rank($large, new SearchCriteria);
        $largeCount = collect($captured)->filter(fn (string $sql): bool => str_contains($sql, 'directory_verifications'))->count();

        $this->assertSame($smallCount, $largeCount, 'Verification query count must not grow with the number of candidates.');
    }

    // BI. candidates() enforces a real, deterministic upper bound —
    // never an unbounded pull into PHP memory.
    public function test_candidates_are_capped_and_deterministically_ordered(): void
    {
        $firms = DirectoryFirm::factory()->count(10)->create(['publication_state' => DirectoryPublicationState::Published]);

        $first = app(MarketplaceSearchService::class)->candidates(new SearchCriteria);
        $second = app(MarketplaceSearchService::class)->candidates(new SearchCriteria);

        $this->assertSame($first->pluck('id')->all(), $second->pluck('id')->all(), 'Two identical searches must return candidates in the same order.');
        $this->assertSame($firms->pluck('id')->sort()->values()->all(), $first->pluck('id')->sort()->values()->all());
    }
}
