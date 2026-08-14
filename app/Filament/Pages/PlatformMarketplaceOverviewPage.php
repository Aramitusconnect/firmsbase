<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Resources\DirectoryClaimResource;
use App\Filament\Resources\DirectoryCorrectionRequestResource;
use App\Filament\Resources\DirectoryFirmResource;
use App\Filament\Resources\DirectoryImportBatchResource;
use App\Marketplace\Enums\ClaimState;
use App\Marketplace\Enums\CorrectionState;
use App\Marketplace\Enums\DirectoryImportBatchStatus;
use App\Marketplace\Enums\DirectoryPublicationState;
use App\Marketplace\Enums\VerificationDimension;
use App\Marketplace\Enums\VerificationState;
use App\Marketplace\Models\DirectoryAttorney;
use App\Marketplace\Models\DirectoryClaim;
use App\Marketplace\Models\DirectoryCorrectionRequest;
use App\Marketplace\Models\DirectoryFirm;
use App\Marketplace\Models\DirectoryImportBatch;
use App\Marketplace\Models\DirectoryVerification;
use App\Marketplace\Services\MarketplaceAnalyticsReportingService;
use App\Models\PlatformAdmin;
use App\Services\AiModeResolutionService;
use App\Services\PlatformStaffAccessPolicyService;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;

/**
 * PlatformMarketplaceOverviewPage — MyAttorney SuperAdmin console
 * professionalization mission. Sits at the top of the "MyAttorney
 * Marketplace" navigation group (forced there via a low
 * $navigationSort — every other class in this group leaves it unset,
 * so this is the only page with an explicit value) and gives a
 * SuperAdmin one screen of real marketplace health: directory
 * composition, operations backlog, the search→conversion funnel, and
 * AI status — each card linking through to the underlying resource
 * for drilldown, per the mission's own instruction not to build a
 * dead-end dashboard.
 *
 * Same `Platform*Page` shape as PlatformMarketplaceAnalyticsPage /
 * PlatformAiOversightPage (no custom Blade view, content() built from
 * Section/Text), extended with ExpenseReportPage's own established
 * live-filter pattern (HasSchemas + InteractsWithSchemas + a separate
 * form() bound via statePath('data'), embedded into content() via
 * EmbeddedSchema::make('form')) for the 7/30/90/Custom date range —
 * the first Page in this codebase's MyAttorney Marketplace group to
 * need one.
 *
 * AI call/failure/provider-health metrics are deliberately reported as
 * "not available" rather than fabricated: `ai_usage_events` is
 * FORCE-RLS/tenant-owned and this mission does not build a new
 * reviewed cross-tenant aggregate for it (same disclosed gap
 * PlatformAiOversightPage's own docblock already documents).
 */
class PlatformMarketplaceOverviewPage extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected string $view = 'filament-panels::pages.page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?string $navigationLabel = 'Marketplace Overview';

    protected static ?string $title = 'Marketplace Overview';

    protected static string|\UnitEnum|null $navigationGroup = 'MyAttorney Marketplace';

    protected static ?int $navigationSort = -100;

    /**
     * A firm below this completeness_score is surfaced as an
     * "incomplete profile" needing attention. No completeness-scoring
     * service exists yet (completeness_score is a stored column with
     * no computation service anywhere in the codebase — see this
     * mission's discovery report), so this threshold is applied
     * directly against whatever value is currently stored.
     */
    private const INCOMPLETE_COMPLETENESS_THRESHOLD = 60;

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
        $this->form->fill([
            'range' => '30',
            'from' => null,
            'to' => null,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Select::make('range')
                    ->label('Date range')
                    ->options([
                        '7' => 'Last 7 days',
                        '30' => 'Last 30 days',
                        '90' => 'Last 90 days',
                        'custom' => 'Custom',
                    ])
                    ->default('30')
                    ->live()
                    ->selectablePlaceholder(false),
                DatePicker::make('from')
                    ->label('From')
                    ->native(false)
                    ->live()
                    ->visible(fn (callable $get): bool => $get('range') === 'custom'),
                DatePicker::make('to')
                    ->label('To')
                    ->native(false)
                    ->live()
                    ->visible(fn (callable $get): bool => $get('range') === 'custom'),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedSchema::make('form'),
            $this->directorySection(),
            $this->operationsSection(),
            $this->funnelSection(),
            $this->aiSection(),
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

    private function link(string $url, string $label): HtmlString
    {
        return new HtmlString('<a href="'.e($url).'" class="fi-link text-primary-600 hover:underline">'.e($label).'</a>');
    }

    private function directorySection(): Section
    {
        $verifiedFirmCount = DirectoryVerification::query()
            ->where('verifiable_type', DirectoryFirm::class)
            ->where('dimension', VerificationDimension::FirmAuthority)
            ->where('state', VerificationState::Verified)
            ->distinct('verifiable_id')
            ->count('verifiable_id');

        return Section::make('Directory')
            ->schema([
                Text::make(fn (): string => 'Total firms: '.DirectoryFirm::query()->count()),
                Text::make(fn (): string => 'Total attorneys: '.DirectoryAttorney::query()->count()),
                Text::make(fn (): string => 'Published firms: '.DirectoryFirm::query()->where('publication_state', DirectoryPublicationState::Published)->count()),
                Text::make(fn (): string => 'Published attorneys: '.DirectoryAttorney::query()->where('publication_state', DirectoryPublicationState::Published)->count()),
                Text::make(fn (): string => 'Claimed firms: '.DirectoryFirm::query()->where('is_claimed', true)->count()),
                Text::make(fn (): string => 'Verified firms: '.$verifiedFirmCount),
                Text::make(fn (): string => 'FirmsVault members: '.DirectoryFirm::query()->where('is_marketplace_member', true)->count()),
                Text::make(fn (): string => 'Firms accepting inquiries: '.DirectoryFirm::query()->where('accepting_inquiries', true)->count()),
            ])
            ->columns(2);
    }

    private function operationsSection(): Section
    {
        return Section::make('Operations')
            ->description('Every count below links to the underlying queue.')
            ->schema([
                Text::make(fn (): HtmlString => $this->link(
                    DirectoryClaimResource::getUrl('index', ['tableFilters' => ['state' => ['value' => ClaimState::Pending->value]]]),
                    'Claims awaiting review: '.DirectoryClaim::query()->whereIn('state', [ClaimState::Pending, ClaimState::UnderReview])->count()
                )),
                Text::make(fn (): HtmlString => $this->link(
                    DirectoryCorrectionRequestResource::getUrl('index', ['tableFilters' => ['state' => ['value' => CorrectionState::Pending->value]]]),
                    'Correction/removal requests awaiting review: '.DirectoryCorrectionRequest::query()->whereIn('state', [CorrectionState::Pending, CorrectionState::UnderReview])->count()
                )),
                Text::make(fn (): HtmlString => $this->link(
                    DirectoryImportBatchResource::getUrl('index', ['tableFilters' => ['status' => ['value' => DirectoryImportBatchStatus::Cancelled->value]]]),
                    'Failed imports: '.DirectoryImportBatch::query()->where('status', DirectoryImportBatchStatus::Cancelled)->count()
                )),
                Text::make(fn (): HtmlString => $this->link(
                    DirectoryImportBatchResource::getUrl('index'),
                    'Import batches processing: '.DirectoryImportBatch::query()->whereNotIn('status', [DirectoryImportBatchStatus::Applied, DirectoryImportBatchStatus::Cancelled])->count()
                )),
                Text::make(fn (): HtmlString => $this->link(
                    DirectoryFirmResource::getUrl('index', ['tableFilters' => ['publication_state' => ['value' => DirectoryPublicationState::Draft->value]]]),
                    'Listings needing review (draft): '.DirectoryFirm::query()->where('publication_state', DirectoryPublicationState::Draft)->count()
                )),
                Text::make(fn (): HtmlString => $this->link(
                    DirectoryFirmResource::getUrl('index'),
                    'Incomplete profiles (completeness < '.self::INCOMPLETE_COMPLETENESS_THRESHOLD.'): '.DirectoryFirm::query()->where('completeness_score', '<', self::INCOMPLETE_COMPLETENESS_THRESHOLD)->count()
                )),
            ])
            ->columns(2);
    }

    private function funnelSection(): Section
    {
        $reporting = app(MarketplaceAnalyticsReportingService::class);
        $since = $this->since();

        $searches = $reporting->totalSearchesSince($since);
        $views = $reporting->totalViewsSince($since);
        $started = $reporting->totalIntakesStartedSince($since);
        $submitted = $reporting->totalIntakesSubmittedSince($since);
        $accepted = $reporting->totalIntakesAcceptedSince($since);
        $converted = $reporting->totalIntakesConvertedSince($since);
        $conversionRate = $searches > 0 ? round(($converted / $searches) * 100, 1) : 0.0;

        return Section::make('Marketplace Funnel — '.$this->rangeLabel())
            ->schema([
                Text::make("Searches: {$searches}"),
                Text::make("Profile views: {$views}"),
                Text::make("Intakes started: {$started}"),
                Text::make("Intakes submitted: {$submitted}"),
                Text::make("Firm accepted: {$accepted}"),
                Text::make("Converted: {$converted}"),
                Text::make("Conversion rate (searches → converted): {$conversionRate}%"),
            ])
            ->columns(2);
    }

    private function aiSection(): Section
    {
        $engaged = app(AiModeResolutionService::class)->platformKillSwitchEngaged();

        return Section::make('AI')
            ->description('See AI Oversight for the kill switch control and the full MyAttorney intake funnel.')
            ->schema([
                Text::make('Platform AI status: '.($engaged ? 'DISABLED (kill switch engaged)' : 'Enabled')),
                Text::make('Calls during period: not available — ai_usage_events is tenant-owned (RLS); no reviewed cross-tenant aggregate exists yet.'),
                Text::make('Failures during period: not available — same reason.'),
                Text::make('Provider health: not available — no cross-tenant provider-health aggregation exists yet.'),
                Text::make('Open AI incidents/review items: not tracked — no incident model exists in this codebase.'),
            ]);
    }
}
