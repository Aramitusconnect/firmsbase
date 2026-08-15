<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\PlatformAdmin;
use App\Services\GovernanceOverviewMetricsService;
use App\Services\PlatformStaffAccessPolicyService;
use App\ValueObjects\GovernanceAttentionItem;
use App\ValueObjects\GovernanceMetric;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * PlatformGovernanceOverviewPage — the Governance command centre: one
 * place to see retention, legal hold, deletion/disposition, export,
 * import, data migration, and offboarding state across every firm, plus
 * an explicit "Requires Attention" list.
 *
 * READ-ONLY. This page performs no governance mutation of any kind; it
 * links to the resource pages where governed actions already live.
 *
 * Every number shown here comes from GovernanceOverviewMetricsService,
 * which returns either a real count or an explicit non-value. This page
 * renders "Not monitored" and "Not supported" verbatim rather than
 * collapsing them to 0 or an em dash — an operator making a compliance
 * decision has to be able to tell a measured zero apart from an absent
 * measurement. The four cases that matters for on current HEAD are
 * retention sweep history (flat-log only), legal hold review/expiry (no
 * such concept), export downloads (no file is produced), and deletion
 * execution (terminal state is ReadyForExecution — nothing is ever
 * physically deleted). Each carries its own inline explanation.
 *
 * Authorization mirrors every other Governance surface exactly:
 * PlatformStaffAccessPolicyService::canAccessGovernance(). The service
 * re-asserts it server-side rather than trusting this page's own
 * canAccess() gate.
 */
class PlatformGovernanceOverviewPage extends Page
{
    protected string $view = 'filament-panels::pages.page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?string $navigationLabel = 'Governance Overview';

    protected static string|\UnitEnum|null $navigationGroup = 'Governance';

    /**
     * Sorts above every other Governance destination. Only the
     * Governance group is reordered — no other navigation group is
     * touched by this mission.
     */
    protected static ?int $navigationSort = -100;

    protected static ?string $title = 'Governance Overview';

    public static function canAccess(): bool
    {
        $admin = Auth::guard('platform_admin')->user();

        if (! $admin instanceof PlatformAdmin) {
            return false;
        }

        return app(PlatformStaffAccessPolicyService::class)->canAccessGovernance($admin)->allowed;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function content(Schema $schema): Schema
    {
        $admin = Auth::guard('platform_admin')->user();

        if (! $admin instanceof PlatformAdmin) {
            return $schema->components([
                Section::make('Not available')
                    ->schema([Text::make('You are not signed in as a platform administrator.')]),
            ]);
        }

        $summary = app(GovernanceOverviewMetricsService::class)->summary($admin);

        return $schema->components([
            $this->attentionSection($summary['attention']),
            $this->metricSection(
                'Retention',
                'Category configuration from the read-only retention registry. Sweep execution history is not '
                .'queryable on this deployment — see the note below.',
                $summary['retention'],
                '/admin/retention',
            ),
            $this->metricSection(
                'Legal Holds',
                'A hold blocks deletion and disposition. It never lapses on its own — it stays active until a '
                .'governed release is performed.',
                $summary['legal_holds'],
                '/admin/legal-holds',
            ),
            $this->metricSection(
                'Deletion / Disposition Requests',
                'Requests move through export clearance, retention clearance, legal hold clearance, and dual '
                .'approval. The terminal state is Ready for Execution — no disposition is physically executed.',
                $summary['deletion'],
                '/admin/deletion-requests',
            ),
            $this->metricSection(
                'Export Requests',
                'Export jobs are governance request records. No downloadable archive is generated.',
                $summary['exports'],
                '/admin/export-jobs',
            ),
            $this->metricSection(
                'Governance Imports',
                'Upload, validate, preview, confirm, apply — the canonical import pipeline. Distinct from the '
                .'MyAttorney directory import pipeline, which this page does not cover.',
                $summary['imports'],
                '/admin/import-batches',
            ),
            $this->metricSection(
                'Data Migration Projects',
                'Customer/tenant data migration into FirmsVault. These are NOT deployment fleet migrations.',
                $summary['migrations'],
                '/admin/migration-projects',
            ),
            $this->metricSection(
                'Offboarding Requests',
                'Offboarding cannot reach Ready for Deletion while an active firm-scope legal hold applies.',
                $summary['offboarding'],
                '/admin/offboarding-requests',
            ),
        ]);
    }

    /**
     * @param  array<int, GovernanceAttentionItem>  $items
     */
    private function attentionSection(array $items): Section
    {
        $actionable = array_filter(
            $items,
            static fn (GovernanceAttentionItem $item): bool => $item->severity !== GovernanceAttentionItem::SEVERITY_UNEVALUATED,
        );

        $components = [];

        if ($actionable === []) {
            $components[] = Text::make(
                'No governance issues currently require action. Every monitored condition below was evaluated '
                .'and came back clear.'
            )->color('success');
        }

        foreach ($items as $item) {
            $count = $item->count === null ? '' : sprintf(' (%d)', $item->count);

            $components[] = Text::make(
                sprintf('[%s] %s%s — %s', $item->severityLabel(), $item->condition, $count, $item->detail)
            )->color($item->color());
        }

        return Section::make('Requires Attention')
            ->icon(Heroicon::OutlinedExclamationTriangle)
            ->description(
                'Conditions that were actually evaluated. Items marked "Not evaluated" are structural gaps in '
                .'the current schema that no query can clear — they are listed every time rather than omitted, '
                .'because "we did not look" and "we looked and it was fine" are different answers.'
            )
            ->schema($components);
    }

    /**
     * @param  array<int, GovernanceMetric>  $metrics
     */
    private function metricSection(string $heading, string $description, array $metrics, string $url): Section
    {
        $components = [];

        foreach ($metrics as $metric) {
            $components[] = Text::make(sprintf('%s: %s', $metric->label, $metric->display()))
                ->color($metric->isAvailable() ? null : 'gray');

            if ($metric->explanation !== null) {
                $components[] = Text::make($metric->explanation)->color('gray')->size('sm');
            }
        }

        $components[] = Text::make(sprintf('Open %s: %s', $heading, $url))->color('primary');

        return Section::make($heading)
            ->description($description)
            ->collapsible()
            ->schema($components);
    }
}
