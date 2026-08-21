<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\MatterResource\Pages;

use App\Filament\Firm\Resources\ClientResource;
use App\Filament\Firm\Resources\MatterResource;
use App\Filament\Firm\Resources\MatterResource\Actions\ApplyMatterBudgetTemplateAction;
use App\Filament\Firm\Resources\MatterResource\Actions\ArchiveMatterAction;
use App\Filament\Firm\Resources\MatterResource\Actions\CloseMatterAction;
use App\Filament\Firm\Resources\MatterResource\Actions\OpenMatterAction;
use App\Models\Matter;
use App\Models\TrustLedger;
use App\Services\Leverage\LeverageAnalysisService;
use App\Services\MatterAccessPolicyService;
use App\Services\MatterBudget\MatterBudgetAccessPolicyService;
use App\Services\TenantContextService;
use App\Services\TrustBalanceService;
use App\Services\TrustEligibilityService;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * ViewMatter — Checkpoint 4 ("Plaid financial evidence add-on").
 * Per-record authorization boundary (the real gate — the list page's
 * getEloquentQuery() is UX-layer filtering only, the same
 * non-boundary/boundary split FirmIntegrationResource's own docblock
 * draws between entitlement and its real policy-service boundary).
 *
 * Tier 3 addition: hosts the "Open Matter" header action
 * (OpenMatterAction, wired to the pre-existing MatterOpeningService::
 * openMatter() — never a status field on a form). Matter mutation
 * otherwise stays exclusively in its existing services
 * (MatterOpeningService, MatterReadinessService, etc.); this page still
 * has no `form()`/editable fields of its own.
 *
 * Mission 5A addition: "Close Matter"/"Archive Matter" header actions
 * (CloseMatterAction/ArchiveMatterAction, wired to the new
 * MatterClosingService::close()/archive() — same "never a status field
 * on a form" discipline as OpenMatterAction). Also adds a read-only
 * Trust Balance section (trustBalanceSection() below) — a cached read
 * via TrustBalanceService::matterBalanceCentsAggregate(), never a
 * recompute/lock; this page still writes nothing trust-related.
 */
class ViewMatter extends ViewRecord
{
    protected static string $resource = MatterResource::class;

    protected function getHeaderActions(): array
    {
        return [
            OpenMatterAction::make(),
            CloseMatterAction::make(),
            ArchiveMatterAction::make(),
            ApplyMatterBudgetTemplateAction::make(),
        ];
    }

    protected function resolveRecord(int|string $key): Model
    {
        $record = parent::resolveRecord($key);
        $user = Auth::user();

        abort_unless(
            $user !== null && app(MatterAccessPolicyService::class)->canAccessMatter($user, $record),
            403,
        );

        return $record;
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Matter')
                ->columns(2)
                ->schema([
                    TextEntry::make('matterType.name')->label('Type')->placeholder('—'),
                    TextEntry::make('stage')->placeholder('—'),
                    TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(fn ($state): string => is_object($state) ? $state->value : (string) $state)
                        ->color(fn ($state): string => match (is_object($state) ? $state->value : $state) {
                            'open', 'active' => 'success',
                            'waiting_on_client', 'ready_for_review' => 'warning',
                            'closed', 'archived' => 'gray',
                            'conflict_check_required', 'conflict_review' => 'info',
                            default => 'gray',
                        }),
                    TextEntry::make('opened_at')->dateTime()->placeholder('—'),
                    TextEntry::make('closed_at')->dateTime()->placeholder('—'),
                ]),

            Section::make('Client')
                ->columns(2)
                ->schema([
                    // Tier1-G: a Matter belongs to exactly one Client, so
                    // this stays a simple info panel + link-out (not a
                    // RelationManager, which would be the wrong shape for
                    // a single BelongsTo record) — links to
                    // ClientResource's own ViewClient page, which itself
                    // now hosts this same client's full Matters/Time
                    // Entries/Expenses/Payments/Activity tabs.
                    TextEntry::make('client.display_name')
                        ->label('Name')
                        ->placeholder('—')
                        ->url(fn (Matter $record): ?string => $record->client === null
                            ? null
                            : ClientResource::getUrl('view', ['record' => $record->client])),
                    TextEntry::make('client.email')->label('Email')->placeholder('—'),
                    TextEntry::make('client.phone')->label('Phone')->placeholder('—'),
                ]),

            Section::make('Team')
                ->columns(2)
                ->schema([
                    TextEntry::make('assignedAttorney.name')->label('Assigned Attorney')->placeholder('—'),
                    TextEntry::make('matterAssignments')
                        ->label('Active Team')
                        ->state(fn (Matter $record) => $record->matterAssignments()
                            ->whereNull('removed_at')
                            ->with('user')
                            ->get()
                            ->map(fn ($assignment) => trim(sprintf(
                                '%s%s',
                                (string) $assignment->user?->name,
                                $assignment->role !== null ? " ({$assignment->role})" : '',
                            )))
                            ->all())
                        ->listWithLineBreaks()
                        ->placeholder('—'),
                ]),

            $this->budgetSection(),
            $this->trustBalanceSection(),
            $this->leverageSection(),
        ]);
    }

    /**
     * Predictive Matter Budget Alerts, item 17. Reads the cached,
     * hourly-swept MatterBudgetAnalysis row (Matter::budgetAnalysis())
     * — never recomputed on a page view, which would make a plain GET
     * request perform a write. A Matter with no budget shows "No
     * Budget Configured", never a fabricated zero (item 24). Every
     * profitability figure (margin, projected cost) is additionally
     * gated behind MatterBudgetAccessPolicyService::canViewProfitability() —
     * a role with only operational visibility sees hours/expenses/
     * progress, never dollar margin figures.
     */
    private function budgetSection(): Section
    {
        $accessPolicy = app(MatterBudgetAccessPolicyService::class);
        $canViewOperational = fn (): bool => ($firmUser = Auth::user()?->activeFirmUser()) !== null
            && $accessPolicy->canViewOperationalBudget($firmUser->role);
        $canViewProfitability = fn (): bool => ($firmUser = Auth::user()?->activeFirmUser()) !== null
            && $accessPolicy->canViewProfitability($firmUser->role);
        $hasAnalysis = fn (Matter $record): bool => $record->budgetAnalysis !== null;

        return Section::make('Budget')
            ->columns(2)
            ->visible($canViewOperational)
            ->schema([
                TextEntry::make('no_budget')
                    ->label('')
                    ->state('No Budget Configured')
                    ->columnSpanFull()
                    ->visible(fn (Matter $record): bool => ! $hasAnalysis($record)),

                TextEntry::make('budgetAnalysis.work_completion_percent')
                    ->label('Work Completion')
                    ->suffix('%')
                    ->visible($hasAnalysis),
                TextEntry::make('budgetAnalysis.time_elapsed_percent')
                    ->label('Time Elapsed')
                    ->suffix('%')
                    ->placeholder('—')
                    ->visible($hasAnalysis),

                TextEntry::make('budget_hours_summary')
                    ->label('Hours Used vs. Budgeted')
                    ->state(fn (Matter $record): array => collect($record->budgetAnalysis?->hours_by_role_json ?? [])
                        ->map(fn (array $d, string $role): string => sprintf(
                            '%s: %.1f / %s hrs%s',
                            str($role)->headline(),
                            $d['actual'],
                            $d['expected'] > 0 ? $d['expected'] : '—',
                            $d['consumed_percent'] !== null ? " ({$d['consumed_percent']}%)" : '',
                        ))->values()->all())
                    ->listWithLineBreaks()
                    ->placeholder('—')
                    ->columnSpanFull()
                    ->visible($hasAnalysis),

                TextEntry::make('budget_expenses_summary')
                    ->label('Expenses Used vs. Budgeted')
                    ->state(fn (Matter $record): array => collect($record->budgetAnalysis?->expenses_by_category_json ?? [])
                        ->map(fn (array $d, string $category): string => sprintf(
                            '%s: $%.2f / $%s%s',
                            str($category)->headline(),
                            $d['actual_cents'] / 100,
                            $d['expected_cents'] > 0 ? number_format($d['expected_cents'] / 100, 2) : '—',
                            $d['consumed_percent'] !== null ? " ({$d['consumed_percent']}%)" : '',
                        ))->values()->all())
                    ->listWithLineBreaks()
                    ->placeholder('—')
                    ->columnSpanFull()
                    ->visible($hasAnalysis),

                TextEntry::make('budgetAnalysis.current_margin_percent')
                    ->label('Current Margin')
                    ->suffix('%')
                    ->placeholder('—')
                    ->visible(fn (Matter $record): bool => $hasAnalysis($record) && $canViewProfitability()),
                TextEntry::make('budgetAnalysis.projected_margin_percent')
                    ->label('Projected Margin')
                    ->suffix('%')
                    ->placeholder('—')
                    ->visible(fn (Matter $record): bool => $hasAnalysis($record) && $canViewProfitability()),
                TextEntry::make('budgetAnalysis.revenue_outstanding_cents')
                    ->label('AR Remaining')
                    ->formatStateUsing(fn ($state): string => '$'.number_format(((int) $state) / 100, 2))
                    ->visible(fn (Matter $record): bool => $hasAnalysis($record) && $canViewProfitability()),
                TextEntry::make('budgetAnalysis.projected_final_cost_cents')
                    ->label('Projected Final Cost')
                    ->formatStateUsing(fn ($state): string => $state === null ? '—' : '$'.number_format(((int) $state) / 100, 2))
                    ->placeholder('—')
                    ->visible(fn (Matter $record): bool => $hasAnalysis($record) && $canViewProfitability()),

                TextEntry::make('budgetAnalysis.computed_at')
                    ->label('Last Computed')
                    ->dateTime()
                    ->columnSpanFull()
                    ->visible($hasAnalysis),
            ]);
    }

    /**
     * Mission 5A, item 5.3 — MatterTrustBalance had zero Filament
     * references before this addition. Reads ONLY
     * TrustBalanceService::matterBalanceCentsAggregate() (a cached
     * read across every trust ledger this matter has a balance row
     * in) — never recomputes or locks anything, and never touches
     * TrustLedgerService/TrustBalanceService's own write paths (Mission
     * 1 territory). Wrapped in TenantContextService::runWithFirmContext()
     * since matter_trust_balances (and trust_ledgers, read here to
     * distinguish "no ledger" from "zero balance") are both FORCE-RLS
     * protected.
     *
     * Three honest states, matching budgetSection()'s own
     * "No Budget Configured" placeholder convention rather than
     * fabricating a zero:
     *   - Firm not trust-eligible (TrustEligibilityService::isEligible()
     *     is false) -> "Not applicable — trust accounting is not
     *     enabled for this firm."
     *   - Firm eligible, but this matter's client has no trust ledger
     *     at all -> "Not applicable — no trust ledger exists for this
     *     matter's client."
     *   - A ledger exists -> the real aggregate balance (which may
     *     legitimately be $0.00 — a true zero, not a placeholder).
     */
    private function trustBalanceSection(): Section
    {
        $canView = fn (): bool => Auth::user()?->activeFirmUser() !== null;

        $state = function (Matter $record): array {
            $firm = $record->firm;

            if (! app(TrustEligibilityService::class)->isEligible($firm)) {
                return ['eligible' => false, 'has_ledger' => false, 'balance_cents' => null];
            }

            return app(TenantContextService::class)->runWithFirmContext($firm, function () use ($firm, $record): array {
                $hasLedger = TrustLedger::query()
                    ->where('firm_id', $firm->id)
                    ->where('client_id', $record->client_id)
                    ->exists();

                if (! $hasLedger) {
                    return ['eligible' => true, 'has_ledger' => false, 'balance_cents' => null];
                }

                return [
                    'eligible' => true,
                    'has_ledger' => true,
                    'balance_cents' => app(TrustBalanceService::class)->matterBalanceCentsAggregate($firm, $record),
                ];
            });
        };

        $cache = [];
        $resolved = function (Matter $record) use (&$cache, $state): array {
            return $cache[$record->id] ??= $state($record);
        };

        return Section::make('Trust Balance')
            ->columns(1)
            ->visible($canView)
            ->schema([
                TextEntry::make('trust_not_applicable')
                    ->label('')
                    ->state(fn (Matter $record): string => $resolved($record)['eligible']
                        ? 'Not applicable — no trust ledger exists for this matter\'s client.'
                        : 'Not applicable — trust accounting is not enabled for this firm.')
                    ->visible(fn (Matter $record): bool => ! $resolved($record)['has_ledger']),

                TextEntry::make('trust_balance_cents')
                    ->label('Matter Trust Balance')
                    ->state(fn (Matter $record) => $resolved($record)['balance_cents'])
                    ->formatStateUsing(fn ($state): string => '$'.number_format(((int) $state) / 100, 2))
                    ->visible(fn (Matter $record): bool => $resolved($record)['has_ledger']),
            ]);
    }

    /**
     * Leverage Ratio Optimizer, item 25/27/28. Reuses the SAME
     * MatterBudgetAccessPolicyService two-tier gate the Budget section
     * above already uses — never a second permission architecture
     * (item 27's own explicit instruction). Operational fields (hours,
     * shares, task distribution, open recommendations) are visible to
     * every role with operational budget visibility; labor cost and
     * margin figures are additionally gated behind
     * canViewProfitability() — EmployeeRate.cost_rate_cents never
     * reaches a Paralegal/Legal Assistant view through this page
     * (proven by LeverageMatterUiPrivacyTest).
     *
     * LeverageAnalysisService::analyze() is a pure, read-only
     * computation (see its own docblock) — unlike the Budget section's
     * cached MatterBudgetAnalysis row, it is safe to call live on this
     * single-record page view. Memoized per-request via $cache so the
     * several fields below don't each recompute it independently.
     */
    private function leverageSection(): Section
    {
        $accessPolicy = app(MatterBudgetAccessPolicyService::class);
        $canViewOperational = fn (): bool => ($firmUser = Auth::user()?->activeFirmUser()) !== null
            && $accessPolicy->canViewOperationalBudget($firmUser->role);
        $canViewProfitability = fn (): bool => ($firmUser = Auth::user()?->activeFirmUser()) !== null
            && $accessPolicy->canViewProfitability($firmUser->role);

        $cache = [];
        $analysis = function (Matter $record) use (&$cache): array {
            return $cache[$record->id] ??= app(LeverageAnalysisService::class)->analyze($record);
        };
        $hasData = fn (Matter $record) => $analysis($record)['has_recorded_hours'];

        return Section::make('Staffing & Leverage')
            ->columns(2)
            ->visible($canViewOperational)
            ->schema([
                TextEntry::make('leverage_no_data')
                    ->label('')
                    ->state('Insufficient staffing data — no budget or recorded hours yet.')
                    ->columnSpanFull()
                    ->visible(fn (Matter $record): bool => ! $hasData($record)),

                TextEntry::make('leverage_status')
                    ->label('Staffing Status')
                    ->badge()
                    ->state(fn (Matter $record) => $analysis($record)['status']->value)
                    ->formatStateUsing(fn ($state): string => (string) str($state)->headline())
                    ->color(fn ($state): string => match ($state) {
                        'healthy' => 'success',
                        'watch' => 'warning',
                        'inefficient' => 'danger',
                        default => 'gray',
                    })
                    ->visible($hasData),

                TextEntry::make('leverage_hours_by_role')
                    ->label('Recorded Hours by Role')
                    ->state(fn (Matter $record): array => collect($analysis($record)['hours_by_role'])
                        ->map(fn ($hours, $role): string => sprintf('%s: %.1f hrs', str($role)->headline(), $hours))
                        ->values()->all())
                    ->listWithLineBreaks()
                    ->placeholder('—')
                    ->columnSpanFull()
                    ->visible($hasData),

                TextEntry::make('leverage_attorney_share')
                    ->label('Attorney Share')
                    ->state(fn (Matter $record) => $analysis($record)['attorney_share_percent'])
                    ->suffix('%')
                    ->placeholder('—')
                    ->visible($hasData),
                TextEntry::make('leverage_support_share')
                    ->label('Support Staff Share')
                    ->state(fn (Matter $record) => $analysis($record)['support_share_percent'])
                    ->suffix('%')
                    ->placeholder('—')
                    ->visible($hasData),

                TextEntry::make('leverage_expected_mix')
                    ->label('Expected vs. Actual Mix')
                    ->state(fn (Matter $record): array => collect($analysis($record)['actual_mix_percent'] ?? [])
                        ->map(fn ($actual, $role): string => sprintf(
                            '%s: %s%% actual vs %s%% expected',
                            str($role)->headline(),
                            $actual,
                            $analysis($record)['expected_mix_percent'][$role] ?? '—',
                        ))->values()->all())
                    ->listWithLineBreaks()
                    ->placeholder('—')
                    ->columnSpanFull()
                    ->visible(fn (Matter $record): bool => $analysis($record)['expected_mix_percent'] !== null),

                TextEntry::make('leverage_task_distribution')
                    ->label('Task Distribution by Category')
                    ->state(fn (Matter $record): array => collect($analysis($record)['task_category_distribution'])
                        ->map(fn ($countsByRole, $category): string => sprintf(
                            '%s: %s',
                            str($category)->headline(),
                            collect($countsByRole)->map(fn ($count, $role): string => str($role)->headline()." {$count}")->implode(', '),
                        ))->values()->all())
                    ->listWithLineBreaks()
                    ->placeholder('—')
                    ->columnSpanFull()
                    ->visible(fn (Matter $record): bool => collect($analysis($record)['task_category_distribution'])->isNotEmpty()),

                TextEntry::make('leverage_cost_by_role')
                    ->label('Labor Cost by Role')
                    ->state(fn (Matter $record): array => collect($analysis($record)['cost_by_role_cents'])
                        ->map(fn ($cents, $role): string => sprintf('%s: $%s', str($role)->headline(), number_format($cents / 100, 2)))
                        ->values()->all())
                    ->listWithLineBreaks()
                    ->placeholder('—')
                    ->columnSpanFull()
                    ->visible(fn (Matter $record): bool => $hasData($record) && $canViewProfitability()),

                TextEntry::make('leverage_average_cost_per_hour')
                    ->label('Average Cost / Recorded Hour')
                    ->state(fn (Matter $record) => $analysis($record)['average_cost_per_hour_cents'])
                    ->formatStateUsing(fn ($state): string => $state === null ? '—' : '$'.number_format(((int) $state) / 100, 2))
                    ->visible(fn (Matter $record): bool => $hasData($record) && $canViewProfitability()),
                TextEntry::make('leverage_current_margin')
                    ->label('Current Margin')
                    ->suffix('%')
                    ->placeholder('—')
                    ->state(fn (Matter $record) => $analysis($record)['current_margin_percent'])
                    ->visible(fn (Matter $record): bool => $hasData($record) && $canViewProfitability()),

                TextEntry::make('leverage_open_recommendations')
                    ->label('Open Staffing Recommendations')
                    ->state(fn (Matter $record): array => $record->leverageRecommendations()
                        ->whereIn('status', ['open', 'acknowledged'])
                        ->get()
                        ->map(fn ($r): string => sprintf('%s (%s confidence)', str($r->recommendation_type->value)->headline(), $r->confidence->value))
                        ->all())
                    ->listWithLineBreaks()
                    ->placeholder('None')
                    ->columnSpanFull(),
            ]);
    }
}
