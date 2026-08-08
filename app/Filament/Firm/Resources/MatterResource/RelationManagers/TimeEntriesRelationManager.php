<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\MatterResource\RelationManagers;

use App\Filament\Firm\Resources\TimeEntryResource;
use App\Models\TimeEntry;
use App\Services\MatterAccessPolicyService;
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
 * ViewMatter, listing this matter's TimeEntry rows (`Matter::
 * timeEntries()`, a new but plain, direct HasMany — TimeEntry carries
 * its own `matter_id` column, see that model's own migration).
 *
 * Deliberately read-only with a "View" link-out to TimeEntryResource's
 * own ViewRecord page — never duplicating TimeEntryApprovalService
 * mutation here.
 *
 * Gate combines MatterAccessPolicyService::canAccessMatter() with
 * TimeExpenseAccessPolicyService::canViewTimeEntry() — the exact same
 * role ceiling TimeEntryResource itself is authorized by.
 */
class TimeEntriesRelationManager extends RelationManager
{
    protected static string $relationship = 'timeEntries';

    protected static ?string $title = 'Time Entries';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null) {
            return false;
        }

        if (! app(MatterAccessPolicyService::class)->canAccessMatter(Auth::user(), $ownerRecord)) {
            return false;
        }

        return app(TimeExpenseAccessPolicyService::class)->canViewTimeEntry($firmUser->role);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('worked_on')->label('Date')->date()->sortable(),
                TextColumn::make('client.display_name')->label('Client')->placeholder('—'),
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
