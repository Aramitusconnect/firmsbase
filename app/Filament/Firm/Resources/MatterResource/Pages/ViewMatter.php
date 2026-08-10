<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\MatterResource\Pages;

use App\Filament\Firm\Resources\ClientResource;
use App\Filament\Firm\Resources\MatterResource;
use App\Filament\Firm\Resources\MatterResource\Actions\ApplyMatterBudgetTemplateAction;
use App\Filament\Firm\Resources\MatterResource\Actions\OpenMatterAction;
use App\Models\Matter;
use App\Services\MatterAccessPolicyService;
use App\Services\MatterBudget\MatterBudgetAccessPolicyService;
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
 */
class ViewMatter extends ViewRecord
{
    protected static string $resource = MatterResource::class;

    protected function getHeaderActions(): array
    {
        return [
            OpenMatterAction::make(),
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
}
