<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Actions\Platform\ToggleAiKillSwitchAction;
use App\Marketplace\Services\MarketplaceAiUsageReportingService;
use App\Marketplace\Services\MarketplaceAnalyticsReportingService;
use App\Models\PlatformAdmin;
use App\Services\AiModeResolutionService;
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
use Illuminate\Support\Str;

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
 * Firm-level in-matter AI spend/usage (ai_usage_events) is
 * deliberately NOT shown here — that table is FORCE RLS/tenant-owned
 * with no cross-tenant escape hatch at all, and a correct cross-tenant
 * aggregate read needs its own reviewed reporting mechanism this
 * checkpoint does not build (a known, disclosed gap, not a silent
 * omission).
 *
 * SuperAdmin console professionalization mission (MYAT8, section 10):
 * adds a genuinely available "MyAttorney AI Usage" section (calls,
 * tokens, provider/model mix) sourced from marketplace_ai_usage_events
 * via MarketplaceAiUsageReportingService — see that class's own
 * docblock for exactly why this is legitimately readable here
 * (RLS-scoped to firm_id IS NULL rows only, i.e. pre-Firm/pre-
 * conversion usage, never a firm's own in-matter activity) — plus an
 * explicit "Not Currently Available" section documenting the three
 * genuine architectural gaps this mission's own discovery pass found
 * (AI call failure/latency tracking, firm-level in-matter AI
 * inspection, and a cross-tenant human-oversight/AI-approval audit
 * trail) rather than fabricating any of them. Closing those three for
 * real would each require a new migration/table or a new
 * cross-tenant-safe reporting mechanism — out of scope per this
 * mission's own Section 26 (no casual database changes; STOP and
 * report instead of building around it).
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
        $aiUsageReporting = app(MarketplaceAiUsageReportingService::class);

        return $schema->components([
            $this->killSwitchSection(),
            $this->funnelSection($reporting, $since),
            $this->aiUsageSection($aiUsageReporting, $since),
            $this->notAvailableSection(),
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

    private function aiUsageSection(MarketplaceAiUsageReportingService $reporting, Carbon $since): Section
    {
        $calls = $reporting->callsSince($since);
        $tokens = $reporting->tokensSince($since);
        $byProvider = $reporting->byProviderSince($since);
        $byModel = $reporting->byModelSince($since);

        return Section::make("MyAttorney AI Usage — Last {$this->windowLabel()}")
            ->description(
                'Pre-Firm/pre-conversion MyAttorney AI calls only (classification, conversational intake). '.
                'A firm\'s own in-matter AI activity is tenant-isolated and cannot be aggregated here — see '.
                '"Not Currently Available" below.'
            )
            ->schema([
                Text::make("Calls: {$calls}"),
                Text::make("Tokens in: {$tokens['in']}"),
                Text::make("Tokens out: {$tokens['out']}"),
                UnorderedList::make(
                    $byProvider->isEmpty()
                        ? ['No AI calls recorded in this window.']
                        : $byProvider->map(fn (array $row): string => Str::headline($row['provider'])." — {$row['calls']} call(s)")->all()
                )->columnSpanFull(),
                UnorderedList::make(
                    $byModel->isEmpty()
                        ? ['No AI calls recorded in this window.']
                        : $byModel->map(fn (array $row): string => "{$row['model']} — {$row['calls']} call(s)")->all()
                )->columnSpanFull(),
            ])
            ->columns(3);
    }

    private function notAvailableSection(): Section
    {
        return Section::make('Not Currently Available')
            ->description('Documented rather than estimated — these would each require a new migration or cross-tenant-safe reporting mechanism this mission does not build.')
            ->schema([
                Text::make('AI call failure rate / latency: not available — no success/failure, error, or timing column exists on any AI usage table.'),
                Text::make('Firm-level in-matter AI inspection: not available — ai_usage_events (in-matter AI activity) is FORCE RLS with no cross-tenant escape hatch; only pre-Firm/pre-conversion usage is visible here.'),
                Text::make('Cross-tenant human-oversight audit trail: not available — AiApprovalWorkflowService\'s AI-proposed/human-approved records (ai_approval_requests) are firm-scoped with no SuperAdmin-visible aggregate.'),
            ])
            ->collapsed();
    }

    private function windowLabel(): string
    {
        return self::WINDOW_DAYS.' days';
    }
}
