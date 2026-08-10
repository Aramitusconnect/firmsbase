<?php

declare(strict_types=1);

namespace App\Filament\Firm\Pages;

use App\Services\Leverage\LeverageReportingService;
use App\Services\MatterBudget\MatterBudgetAccessPolicyService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * StaffingLeverageOverviewPage — Leverage Ratio Optimizer, item 21/26.
 * A read-only Filament Page (deliberately not a Resource — there is no
 * single underlying model, only cross-Matter/cross-staff aggregates),
 * wired directly to LeverageReportingService — nothing on this page
 * computes a staffing/cost figure itself, matching the master spec's
 * own "Do not put calculations directly inside Filament pages" rule
 * (item 21).
 *
 * Answers the master spec's own primary/secondary questions directly:
 * "Is this Matter using the right mix..." (Matter Efficiency section),
 * "Is the staffing mix hurting profitability..." (lowest-margin
 * matters, profitability-gated), "Which Matters use unusually high
 * Attorney labor..." (highest attorney share), "Which staff roles are
 * overloaded/underutilized..." (Staff Utilization), "Where are
 * workflow bottlenecks..." (Bottlenecks section).
 *
 * Labor-cost/margin figures are gated behind
 * MatterBudgetAccessPolicyService::canViewProfitability() — the same
 * two-tier gate the Matter UI's own Staffing & Leverage section uses,
 * never a second permission architecture (item 27).
 */
class StaffingLeverageOverviewPage extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $navigationLabel = 'Overview';

    protected static string|\UnitEnum|null $navigationGroup = 'Staffing & Leverage';

    protected static ?int $navigationSort = 10;

    protected static ?string $title = 'Staffing & Leverage Overview';

    public static function canAccess(): bool
    {
        return static::isFirmEntitled();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    private static function isFirmEntitled(): bool
    {
        $firmUser = Auth::user()?->activeFirmUser();

        return $firmUser !== null && app(MatterBudgetAccessPolicyService::class)->canViewOperationalBudget($firmUser->role);
    }

    private static function canViewProfitability(): bool
    {
        $firmUser = Auth::user()?->activeFirmUser();

        return $firmUser !== null && app(MatterBudgetAccessPolicyService::class)->canViewProfitability($firmUser->role);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Recommendations')
                ->schema([
                    Text::make(fn (): string => $this->recommendationsSummary())->size(TextSize::Medium),
                ]),
            Section::make('Matter Efficiency')
                ->schema([
                    Text::make(fn (): string => $this->highestAttorneyShareSummary())->size(TextSize::Medium),
                    Text::make(fn (): string => $this->lowestMarginSummary())->size(TextSize::Medium)->visible(fn (): bool => static::canViewProfitability()),
                ]),
            Section::make('Staffing Variance')
                ->schema([
                    Text::make(fn (): string => $this->staffingVarianceByMatterTypeSummary())->size(TextSize::Medium),
                ]),
            Section::make('Staff Utilization')
                ->schema([
                    Text::make(fn (): string => $this->workloadByRoleSummary())->size(TextSize::Medium),
                ]),
            Section::make('Bottlenecks')
                ->schema([
                    Text::make(fn (): string => $this->bottlenecksSummary())->size(TextSize::Medium),
                ]),
        ]);
    }

    private function recommendationsSummary(): string
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null) {
            return 'No active firm.';
        }

        $reporting = app(LeverageReportingService::class);
        $mismatchCount = $reporting->taskRoleMismatchOpenCount($firmUser->firm);
        $taskCount = $reporting->estimatedDelegationOpportunityTaskCount($firmUser->firm);

        return "{$mismatchCount} open task-role mismatch recommendation(s), covering {$taskCount} mismatched task(s) total.";
    }

    private function highestAttorneyShareSummary(): string
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null) {
            return 'No active firm.';
        }

        $matters = app(LeverageReportingService::class)->mattersWithHighestAttorneyShare($firmUser->firm, 5);

        if (empty($matters)) {
            return 'No Matters with recorded staffing data yet.';
        }

        return 'Highest Attorney share: '.collect($matters)
            ->map(fn (array $m): string => "Matter #{$m['matter_id']} ({$m['attorney_share_percent']}%)")
            ->implode(', ');
    }

    private function lowestMarginSummary(): string
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null) {
            return 'No active firm.';
        }

        $matters = app(LeverageReportingService::class)->mattersWithLowestProjectedMargin($firmUser->firm, 5);

        if (empty($matters)) {
            return 'No Matters with a projected margin yet.';
        }

        return 'Lowest projected margin: '.collect($matters)
            ->map(fn (array $m): string => "Matter #{$m['matter_id']} ({$m['projected_margin_percent']}%)")
            ->implode(', ');
    }

    private function staffingVarianceByMatterTypeSummary(): string
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null) {
            return 'No active firm.';
        }

        $variance = app(LeverageReportingService::class)->staffingVarianceByMatterType($firmUser->firm);

        if (empty($variance)) {
            return 'Not enough staffing data to compute variance by Matter Type yet.';
        }

        return collect($variance)
            ->map(fn (array $v): string => "Matter Type #{$v['matter_type_id']}: {$v['average_attorney_share_percent']}% avg. Attorney share across {$v['matter_count']} matter(s)")
            ->implode('; ');
    }

    private function workloadByRoleSummary(): string
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null) {
            return 'No active firm.';
        }

        $byRole = app(LeverageReportingService::class)->workloadByRole($firmUser->firm);

        if (empty($byRole)) {
            return 'No active staff.';
        }

        return collect($byRole)
            ->map(function (array $workloads, string $role): string {
                $totalHours = collect($workloads)->sum('recorded_hours');
                $overdueTasks = collect($workloads)->sum('overdue_task_count');

                return str($role)->headline()." ({$totalHours} recorded hrs, {$overdueTasks} overdue task(s) across ".count($workloads).' staff)';
            })
            ->implode('; ');
    }

    private function bottlenecksSummary(): string
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null) {
            return 'No active firm.';
        }

        $bottlenecks = app(LeverageReportingService::class)->bottlenecks($firmUser->firm);

        $overdueCount = count($bottlenecks['overdue_task_backlog']);
        $deadlineCount = count($bottlenecks['deadline_concentration']);
        $stalledCount = count($bottlenecks['stalled_document_requests']);
        $unassignedCount = $bottlenecks['unassigned_task_count'];

        return "{$overdueCount} staff member(s) with an overdue-task backlog, {$deadlineCount} with deadline concentration, "
            ."{$stalledCount} stalled document request item(s), {$unassignedCount} unassigned task(s).";
    }
}
