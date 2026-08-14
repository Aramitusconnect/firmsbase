<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Resources\DirectoryImportBatchResource;
use App\Filament\Resources\PracticeAreaResource;
use App\Models\PlatformAdmin;
use App\Services\AiModeResolutionService;
use App\Services\PlatformStaffAccessPolicyService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;

/**
 * PlatformMarketplaceSettingsPage — SuperAdmin console
 * professionalization mission (MYAT9, section 11). Deliberately
 * READ-ONLY: this mission's own discovery pass found exactly two
 * genuine backing config values for MyAttorney marketplace behavior —
 * config/marketplace.php's analytics_retention_days and
 * intake_retention_days, both env()-driven with no database-backed
 * store anywhere — plus the platform AI kill switch, which already
 * has its own dedicated, audited Edit/Save-equivalent UI
 * (PlatformAiOversightPage / ToggleAiKillSwitchAction). Section 11
 * requires "only controls backed by existing domain configuration,
 * deliberate Edit/Save flows, never invent business rules without
 * backing config" — since neither retention value has any writable
 * store to Edit/Save against (changing them today requires an actual
 * deployment env-var change, not a database row), building a fake
 * form here that silently fails to persist would be dishonest UI, not
 * a real control. This page instead surfaces the CURRENT effective
 * values plainly, links to the AI kill switch's real control surface,
 * and links to Practice Area management (already a full CRUD
 * resource — PracticeAreaResource — reused here rather than
 * duplicated, per section 12's own "link/reuse if it already exists"
 * instruction). Search ranking has no admin-facing config at all by
 * design (MarketplaceSearchService's own deterministic, non-pay-to-
 * rank algorithm — see section 12's own "never add pay-to-rank"
 * constraint), so no link is offered for it here.
 */
class PlatformMarketplaceSettingsPage extends Page
{
    protected string $view = 'filament-panels::pages.page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?string $navigationLabel = 'Marketplace Settings';

    protected static ?string $title = 'Marketplace Settings';

    protected static string|\UnitEnum|null $navigationGroup = 'MyAttorney Marketplace';

    protected static ?int $navigationSort = 100;

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
        return $schema->components([
            $this->aiSection(),
            $this->retentionSection(),
            $this->relatedManagementSection(),
        ]);
    }

    private function aiSection(): Section
    {
        $engaged = app(AiModeResolutionService::class)->platformKillSwitchEngaged();

        return Section::make('Platform AI')
            ->schema([
                Text::make($engaged ? 'Platform AI status: Disabled (kill switch engaged)' : 'Platform AI status: Enabled'),
                Text::make($this->link(PlatformAiOversightPage::getUrl(), 'Manage in AI Oversight →')),
            ]);
    }

    private function retentionSection(): Section
    {
        return Section::make('Data Retention')
            ->description(
                'Configured via environment variables at deployment time, not editable in this UI — there is no '.
                'database-backed store for these values to edit against. Shown here so the effective window is '.
                'always visible without checking deployment config directly.'
            )
            ->schema([
                Text::make('Marketplace analytics events retained for: '.config('marketplace.analytics_retention_days').' days'),
                Text::make('Never-converted intake PII retained for: '.config('marketplace.intake_retention_days').' days'),
            ]);
    }

    private function relatedManagementSection(): Section
    {
        $links = [
            Text::make($this->link(DirectoryImportBatchResource::getUrl('index'), 'Manage Directory Import Batches →')),
        ];

        if (PracticeAreaResource::canAccess()) {
            array_unshift($links, Text::make($this->link(PracticeAreaResource::getUrl('index'), 'Manage Practice Area Catalog →')));
        }

        return Section::make('Related Management')->schema($links);
    }

    private function link(string $url, string $label): HtmlString
    {
        return new HtmlString('<a href="'.e($url).'" class="fi-link text-primary-600 hover:underline">'.e($label).'</a>');
    }
}
