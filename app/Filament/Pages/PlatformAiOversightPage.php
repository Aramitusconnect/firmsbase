<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Actions\Platform\ToggleAiKillSwitchAction;
use App\Marketplace\Services\MarketplaceAnalyticsReportingService;
use App\Models\PlatformAdmin;
use App\Services\AiModeResolutionService;
use App\Services\PlatformStaffAccessPolicyService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * PlatformAiOversightPage — Mission 3 (MyAttorney Conversion + AI
 * Intake), checkpoint 14. Closes two confirmed gaps this checkpoint's
 * own audit found: (1) no genuine SuperAdmin-facing surface existed
 * anywhere for MyAttorney intake oversight, and (2) the platform AI
 * kill switch (AiModeResolutionService::platformKillSwitchEngaged())
 * had no UI to view or toggle it at all — AiPolicySettingResource's
 * generic row-edit action can only ever act on an already-existing
 * row, and nothing ever seeded one before this checkpoint's own
 * migration.
 *
 * Same `Platform*Page` shape as PlatformMarketplaceAnalyticsPage: a
 * dedicated navigable page, read-only aggregate summaries (reusing
 * that same checkpoint's MarketplaceAnalyticsReportingService — no
 * parallel analytics system, and the same privacy bar: no prospect
 * name/email/phone/structured_data, ever), with ONE mutating action
 * (the kill switch toggle). This mission's own final instruction
 * ("after Mission 3 passes: STOP — do not publicly launch MyAttorney,
 * do not auto-enable production AI") is exactly why this page exists:
 * a real, visible lever and real usage numbers for that eventual human
 * decision. Deliberately does NOT itself change the platform's
 * existing absent-row-means-enabled kill-switch default (see
 * AiModeResolutionService::platformKillSwitchEngaged()'s own
 * docblock) — an earlier version of this checkpoint tried seeding the
 * switch to disabled-by-default and broke ~40 pre-existing AI tests
 * across the codebase that already depend on that default, confirming
 * it is load-bearing platform-wide behavior, not this mission's own
 * to silently invert. This page adds the missing lever; a human still
 * decides whether to pull it.
 *
 * Firm-level AI spend/usage (ai_usage_events) is deliberately NOT
 * shown here — that table is FORCE RLS/tenant-owned, and a correct
 * cross-tenant aggregate read needs its own reviewed reporting
 * mechanism this checkpoint does not build (a known, disclosed gap,
 * not a silent omission).
 */
class PlatformAiOversightPage extends Page
{
    protected string $view = 'filament-panels::pages.page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCpuChip;

    protected static ?string $navigationLabel = 'AI Oversight';

    protected static ?string $title = 'AI Oversight';

    protected static string|\UnitEnum|null $navigationGroup = 'MyAttorney Marketplace';

    private const WINDOW_DAYS = 30;

    public static function canAccess(): bool
    {
        $admin = Auth::guard('platform_admin')->user();

        if (! $admin instanceof PlatformAdmin) {
            return false;
        }

        return app(PlatformStaffAccessPolicyService::class)->canAccessAiPolicySettings($admin)->allowed;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    protected function getHeaderActions(): array
    {
        return [
            ToggleAiKillSwitchAction::make(),
        ];
    }

    public function content(Schema $schema): Schema
    {
        $since = Carbon::now()->subDays(self::WINDOW_DAYS);
        $reporting = app(MarketplaceAnalyticsReportingService::class);

        return $schema->components([
            $this->killSwitchSection(),
            $this->funnelSection($reporting, $since),
        ]);
    }

    private function killSwitchSection(): Section
    {
        $engaged = app(AiModeResolutionService::class)->platformKillSwitchEngaged();

        return Section::make('Platform AI Kill Switch')
            ->description(
                'The single platform-wide gate every AI call in the system is checked against first, before any '.
                "firm's own mode/entitlement/keys."
            )
            ->schema([
                Text::make($engaged ? 'Status: DISABLED — no AI call in the system will run.' : 'Status: Enabled — firm-level opt-in and entitlement gates still apply.'),
            ]);
    }

    private function funnelSection(MarketplaceAnalyticsReportingService $reporting, Carbon $since): Section
    {
        $started = $reporting->totalIntakesStartedSince($since);
        $submitted = $reporting->totalIntakesSubmittedSince($since);
        $accepted = $reporting->totalIntakesAcceptedSince($since);
        $declined = $reporting->totalIntakesDeclinedSince($since);
        $converted = $reporting->totalIntakesConvertedSince($since);
        $conversionRate = $started > 0 ? round(($converted / $started) * 100, 1) : 0.0;

        return Section::make("MyAttorney Intake Funnel — Last {$this->windowLabel()}")
            ->description('Aggregate counts only — no prospect name/email/phone/structured_data is ever recorded in this system, matching Marketplace Analytics\' own privacy bar.')
            ->schema([
                Text::make("Started: {$started}"),
                Text::make("Submitted: {$submitted}"),
                Text::make("Accepted: {$accepted}"),
                Text::make("Declined: {$declined}"),
                Text::make("Converted: {$converted}"),
                Text::make("Conversion rate (started → converted): {$conversionRate}%"),
            ]);
    }

    private function windowLabel(): string
    {
        return self::WINDOW_DAYS.' days';
    }
}
