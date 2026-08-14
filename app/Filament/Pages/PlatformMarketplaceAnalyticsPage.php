<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Marketplace\Services\MarketplaceAnalyticsReportingService;
use App\Models\PlatformAdmin;
use App\Services\PlatformStaffAccessPolicyService;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\UnorderedList;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * PlatformMarketplaceAnalyticsPage — Mission 2 (MyAttorney Marketplace
 * Core), checkpoint 13. Rebuilt by the SuperAdmin console
 * professionalization mission (MYAT7) into a real funnel with a
 * 7/30/90/Custom date range (same live-filter pattern established by
 * PlatformMarketplaceOverviewPage/ExpenseReportPage — HasSchemas +
 * InteractsWithSchemas + a separate form() embedded via
 * EmbeddedSchema::make('form')).
 *
 * Every rate/breakdown here is computed from data the event model
 * genuinely records (MarketplaceAnalyticsEventType's own closed
 * vocabulary — confirmed by this mission's own discovery pass: only
 * FirmProfileViewed/AttorneyProfileViewed/SearchPerformed plus five
 * intake-funnel stage events, no click-through, no zero-result flag,
 * no free-text search term, no per-prospect timing). Metrics the
 * spec's own section 9A/9B asks for that this event model cannot
 * support are explicitly labeled "not available" in the Gaps section
 * rather than estimated or fabricated.
 */
class PlatformMarketplaceAnalyticsPage extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected string $view = 'filament-panels::pages.page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $navigationLabel = 'Marketplace Analytics';

    protected static ?string $title = 'Marketplace Analytics';

    protected static string|\UnitEnum|null $navigationGroup = 'MyAttorney Marketplace';

    public ?array $data = [];

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

    public function mount(): void
    {
        $this->form->fill(['range' => '30', 'from' => null, 'to' => null]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Select::make('range')
                    ->label('Date range')
                    ->options(['7' => 'Last 7 days', '30' => 'Last 30 days', '90' => 'Last 90 days', 'custom' => 'Custom'])
                    ->default('30')
                    ->live()
                    ->selectablePlaceholder(false),
                DatePicker::make('from')->label('From')->native(false)->live()
                    ->visible(fn (callable $get): bool => $get('range') === 'custom'),
                DatePicker::make('to')->label('To')->native(false)->live()
                    ->visible(fn (callable $get): bool => $get('range') === 'custom'),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        $reporting = app(MarketplaceAnalyticsReportingService::class);
        $since = $this->since();

        return $schema->components([
            EmbeddedSchema::make('form'),
            $this->summarySection($reporting, $since),
            $this->funnelSection($reporting, $since),
            $this->searchIntelligenceSection($reporting, $since),
            $this->directoryPerformanceSection($reporting, $since),
            $this->topViewedFirmsSection($reporting, $since),
            $this->topViewedAttorneysSection($reporting, $since),
            $this->gapsSection(),
        ]);
    }

    private function since(): Carbon
    {
        $data = $this->data ?? [];
        $range = $data['range'] ?? '30';

        return match ($range) {
            '7' => Carbon::now()->subDays(7),
            '90' => Carbon::now()->subDays(90),
            'custom' => filled($data['from'] ?? null)
                ? Carbon::parse($data['from'])->startOfDay()
                : Carbon::now()->subDays(30),
            default => Carbon::now()->subDays(30),
        };
    }

    private function rangeLabel(): string
    {
        $data = $this->data ?? [];

        return match ($data['range'] ?? '30') {
            '7' => 'Last 7 days',
            '90' => 'Last 90 days',
            'custom' => 'Custom range',
            default => 'Last 30 days',
        };
    }

    private function windowDays(): int
    {
        return (int) $this->since()->diffInDays(Carbon::now());
    }

    private function summarySection(MarketplaceAnalyticsReportingService $reporting, Carbon $since): Section
    {
        $views = $reporting->totalViewsSince($since);
        $searches = $reporting->totalSearchesSince($since);

        $windowDays = max(1, $this->windowDays());
        $previousFrom = (clone $since)->subDays($windowDays);
        $previousTo = (clone $since)->subSecond();
        $previousViews = $reporting->totalViewsBetween($previousFrom, $previousTo);
        $previousSearches = $reporting->totalSearchesBetween($previousFrom, $previousTo);

        return Section::make("Summary — {$this->rangeLabel()}")
            ->schema([
                Text::make('Profile views: '.$views.' '.$this->deltaLabel($views, $previousViews)),
                Text::make('Searches performed: '.$searches.' '.$this->deltaLabel($searches, $previousSearches)),
            ])
            ->description('Compared to the equal-length period immediately before this range.');
    }

    private function deltaLabel(int $current, int $previous): string
    {
        if ($previous === 0) {
            return $current > 0 ? '(new activity — no prior-period data)' : '';
        }

        $delta = round((($current - $previous) / $previous) * 100, 1);
        $sign = $delta >= 0 ? '+' : '';

        return "({$sign}{$delta}% vs. prior period)";
    }

    private function funnelSection(MarketplaceAnalyticsReportingService $reporting, Carbon $since): Section
    {
        $searches = $reporting->totalSearchesSince($since);
        $views = $reporting->totalViewsSince($since);
        $started = $reporting->totalIntakesStartedSince($since);
        $submitted = $reporting->totalIntakesSubmittedSince($since);
        $accepted = $reporting->totalIntakesAcceptedSince($since);
        $converted = $reporting->totalIntakesConvertedSince($since);

        $profileToIntakeRate = $views > 0 ? round(($started / $views) * 100, 1) : 0.0;
        $completionRate = $started > 0 ? round(($submitted / $started) * 100, 1) : 0.0;
        $acceptanceRate = $submitted > 0 ? round(($accepted / $submitted) * 100, 1) : 0.0;
        $conversionRate = $started > 0 ? round(($converted / $started) * 100, 1) : 0.0;
        $abandonmentRate = $started > 0 ? round((($started - $submitted) / $started) * 100, 1) : 0.0;

        return Section::make("Core Funnel — {$this->rangeLabel()}")
            ->schema([
                Text::make("Searches: {$searches}"),
                Text::make("Profile views: {$views}"),
                Text::make("Intakes started: {$started}"),
                Text::make("Intakes submitted: {$submitted}"),
                Text::make("Firm accepted: {$accepted}"),
                Text::make("Converted: {$converted}"),
                Text::make("Profile-to-intake rate (views → started): {$profileToIntakeRate}%"),
                Text::make("Intake completion rate (started → submitted): {$completionRate}%"),
                Text::make("Acceptance rate (submitted → accepted): {$acceptanceRate}%"),
                Text::make("Abandonment rate (started, never submitted): {$abandonmentRate}%"),
                Text::make("Conversion rate (started → converted): {$conversionRate}%"),
            ])
            ->columns(2);
    }

    private function searchIntelligenceSection(MarketplaceAnalyticsReportingService $reporting, Carbon $since): Section
    {
        return Section::make('Search Intelligence — Demand vs. Supply by Practice Area')
            ->description('Most-searched practice areas alongside how many currently-published firms actually offer them.')
            ->schema([
                UnorderedList::make(function () use ($reporting, $since): array {
                    $rows = $reporting->demandVsSupplyByPracticeArea($since);

                    if ($rows->isEmpty()) {
                        return ['No practice-area searches recorded yet.'];
                    }

                    return $rows->map(function (array $row): string {
                        $supplyNote = $row['published_firms'] === 0 ? ' — ⚠ no published firms offer this' : '';

                        return "{$row['practice_area_slug']} — {$row['searches']} search(es), {$row['published_firms']} published firm(s){$supplyNote}";
                    })->all();
                }),
            ])
            ->collapsible();
    }

    private function directoryPerformanceSection(MarketplaceAnalyticsReportingService $reporting, Carbon $since): Section
    {
        $claim = $reporting->firmViewsByClaimStatus($since);
        $member = $reporting->firmViewsByMemberStatus($since);
        $accepting = $reporting->firmViewsByAcceptingInquiriesStatus($since);

        return Section::make('Directory Performance')
            ->description('Firm profile views this period, grouped by the firm\'s CURRENT status (not status at the time of the view).')
            ->columns(3)
            ->schema([
                Text::make("Claimed firm views: {$claim['true']}"),
                Text::make("Unclaimed firm views: {$claim['false']}"),
                Text::make("FirmsVault member views: {$member['true']}"),
                Text::make("Non-member views: {$member['false']}"),
                Text::make("Accepting-inquiries views: {$accepting['true']}"),
                Text::make("Not-accepting views: {$accepting['false']}"),
            ]);
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

    private function gapsSection(): Section
    {
        return Section::make('Metrics Not Currently Available')
            ->description('Documented rather than estimated — the event model does not currently collect the data these would require.')
            ->schema([
                Text::make('Search-to-profile click-through rate: not available — no event links a specific search to the profile view that followed it.'),
                Text::make('Zero-result search rate: not available — result counts are never recorded on a search event.'),
                Text::make('Top free-text search terms: not available — the visitor\'s typed query is deliberately never stored (privacy — see MarketplaceAnalyticsService\'s own docblock).'),
                Text::make('Search reformulations: not available — no session/visitor linkage exists between successive searches.'),
                Text::make('Average time to conversion: not available — no per-prospect identifier is retained across funnel-stage events (aggregate-only by design).'),
            ])
            ->collapsed();
    }
}
