<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Marketplace\Services\MarketplaceAnalyticsReportingService;
use App\Models\PlatformAdmin;
use App\Services\PlatformStaffAccessPolicyService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\UnorderedList;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * PlatformMarketplaceAnalyticsPage — Mission 2 (MyAttorney Marketplace
 * Core), checkpoint 13. Same `Platform*Page` shape as
 * PlatformSecurityDashboardPage: a dedicated navigable page, its own
 * service (MarketplaceAnalyticsReportingService), its own capability
 * gate (PlatformStaffAccessPolicyService::canViewMarketplaceAnalytics()).
 *
 * Deliberately read-only aggregate counts/top-N lists only — there is
 * no per-row event to drill into (see MarketplaceAnalyticsEvent's own
 * docblock: no actor, no IP, nothing identifying), so unlike
 * PlatformSecurityDashboardPage this page has no embedded table at
 * all, only Section/UnorderedList summaries.
 */
class PlatformMarketplaceAnalyticsPage extends Page
{
    protected string $view = 'filament-panels::pages.page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $navigationLabel = 'Marketplace Analytics';

    protected static ?string $title = 'Marketplace Analytics';

    protected static string|\UnitEnum|null $navigationGroup = 'MyAttorney Marketplace';

    private const WINDOW_DAYS = 30;

    public static function canAccess(): bool
    {
        $admin = Auth::guard('platform_admin')->user();

        if (! $admin instanceof PlatformAdmin) {
            return false;
        }

        return app(PlatformStaffAccessPolicyService::class)->canViewMarketplaceAnalytics($admin)->allowed;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function content(Schema $schema): Schema
    {
        $since = Carbon::now()->subDays(self::WINDOW_DAYS);
        $reporting = app(MarketplaceAnalyticsReportingService::class);

        return $schema->components([
            Section::make("Last {$this->windowLabel()}")
                ->schema([
                    Text::make(fn (): string => 'Profile views: '.$reporting->totalViewsSince($since)),
                    Text::make(fn (): string => 'Searches performed: '.$reporting->totalSearchesSince($since)),
                ]),
            $this->topViewedFirmsSection($reporting, $since),
            $this->topViewedAttorneysSection($reporting, $since),
            $this->topSearchedPracticeAreasSection($reporting, $since),
            $this->topSearchedCitiesSection($reporting, $since),
        ]);
    }

    private function windowLabel(): string
    {
        return self::WINDOW_DAYS.' days';
    }

    private function topViewedFirmsSection(MarketplaceAnalyticsReportingService $reporting, Carbon $since): Section
    {
        return Section::make('Most Viewed Firms')
            ->schema([
                UnorderedList::make(function () use ($reporting, $since): array {
                    $rows = $reporting->topViewedFirms($since);

                    if ($rows->isEmpty()) {
                        return ['No firm profile views recorded yet.'];
                    }

                    return $rows->map(fn (array $row): string => "{$row['firm']->display_name} — {$row['views']} view(s)")->all();
                }),
            ])
            ->collapsible();
    }

    private function topViewedAttorneysSection(MarketplaceAnalyticsReportingService $reporting, Carbon $since): Section
    {
        return Section::make('Most Viewed Attorneys')
            ->schema([
                UnorderedList::make(function () use ($reporting, $since): array {
                    $rows = $reporting->topViewedAttorneys($since);

                    if ($rows->isEmpty()) {
                        return ['No attorney profile views recorded yet.'];
                    }

                    return $rows->map(fn (array $row): string => "{$row['attorney']->name} — {$row['views']} view(s)")->all();
                }),
            ])
            ->collapsible();
    }

    private function topSearchedPracticeAreasSection(MarketplaceAnalyticsReportingService $reporting, Carbon $since): Section
    {
        return Section::make('Most Searched Practice Areas')
            ->schema([
                UnorderedList::make(function () use ($reporting, $since): array {
                    $rows = $reporting->topSearchedPracticeAreas($since);

                    if ($rows->isEmpty()) {
                        return ['No practice-area searches recorded yet.'];
                    }

                    return $rows->map(fn (array $row): string => "{$row['practice_area_slug']} — {$row['searches']} search(es)")->all();
                }),
            ])
            ->collapsible();
    }

    private function topSearchedCitiesSection(MarketplaceAnalyticsReportingService $reporting, Carbon $since): Section
    {
        return Section::make('Most Searched Cities')
            ->schema([
                UnorderedList::make(function () use ($reporting, $since): array {
                    $rows = $reporting->topSearchedCities($since);

                    if ($rows->isEmpty()) {
                        return ['No city searches recorded yet.'];
                    }

                    return $rows->map(fn (array $row): string => "{$row['city']} — {$row['searches']} search(es)")->all();
                }),
            ])
            ->collapsible();
    }
}
