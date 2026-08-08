<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\MatterResource\Pages;

use App\Filament\Firm\Resources\ClientResource;
use App\Filament\Firm\Resources\MatterResource;
use App\Filament\Firm\Resources\MatterResource\Actions\OpenMatterAction;
use App\Models\Matter;
use App\Services\MatterAccessPolicyService;
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
        ]);
    }
}
