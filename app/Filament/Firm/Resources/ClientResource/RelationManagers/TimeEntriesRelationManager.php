<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\ClientResource\RelationManagers;

use App\Filament\Firm\Resources\TimeEntryResource;
use App\Models\Client;
use App\Models\TimeEntry;
use App\Services\TimeExpenseAccessPolicyService;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * TimeEntriesRelationManager — Tier1-G, "Time Entries" tab on
 * ClientResource\ViewClient, listing this client's TimeEntry rows
 * across all of its matters (`Client::timeEntries()`, a new but plain,
 * direct HasMany — TimeEntry carries its own `client_id` column, see
 * that model's own migration; this is the same "already-defined
 * HasMany" shape as ContactsRelationManager, not a new query pattern).
 *
 * Deliberately read-only with a "View" link-out to TimeEntryResource's
 * own ViewRecord page (which hosts the real Submit/Approve/Reject row
 * actions) — mirrors DocumentRequestsRelationManager's pattern, never
 * duplicating TimeEntryApprovalService-backed mutation here.
 *
 * Gate reuses TimeExpenseAccessPolicyService::canViewTimeEntry() — the
 * exact same role ceiling TimeEntryResource itself is authorized by
 * (via App\Policies\TimeEntryPolicy) — plus the same firm-match
 * defense-in-depth check every other Client relation manager applies.
 */
class TimeEntriesRelationManager extends RelationManager
{
    protected static string $relationship = 'timeEntries';

    protected static ?string $title = 'Time Entries';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null || ! $ownerRecord instanceof Client || (int) $firmUser->firm_id !== (int) $ownerRecord->firm_id) {
            return false;
        }

        return app(TimeExpenseAccessPolicyService::class)->canViewTimeEntry($firmUser->role);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('worked_on')->label('Date')->date()->sortable(),
                TextColumn::make('matter.stage')->label('Matter')->placeholder('—'),
                TextColumn::make('user.name')->label('Biller')->placeholder('—'),
                TextColumn::make('seconds')
                    ->label('Duration')
                    ->formatStateUsing(fn (int $state): string => TimeEntryResource::formatDuration($state))
                    ->sortable(),
                IconColumn::make('is_billable')->label('Billable')->boolean(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => is_object($state) ? $state->value : (string) $state)
                    ->color(fn ($state): string => match (is_object($state) ? $state->value : $state) {
                        'approved' => 'success',
                        'submitted' => 'info',
                        'invoiced' => 'primary',
                        'rejected' => 'danger',
                        'draft' => 'gray',
                        default => 'gray',
                    }),
                TextColumn::make('description')->limit(40)->placeholder('—'),
            ])
            ->defaultSort('worked_on', 'desc')
            ->headerActions([])
            ->recordActions([
                Action::make('viewTimeEntry')
                    ->label('View')
                    ->icon(Heroicon::OutlinedEye)
                    ->color('gray')
                    ->url(fn (TimeEntry $record): string => TimeEntryResource::getUrl('view', ['record' => $record])),
            ])
            ->toolbarActions([]);
    }
}
