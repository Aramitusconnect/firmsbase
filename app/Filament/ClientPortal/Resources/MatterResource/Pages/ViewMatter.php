<?php

declare(strict_types=1);

namespace App\Filament\ClientPortal\Resources\MatterResource\Pages;

use App\Filament\ClientPortal\Resources\MatterResource;
use App\Models\ClientPortalUser;
use App\Models\Matter;
use App\Services\ClientPortalMatterAccessPolicyService;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * ViewMatter (Client Portal) — Mission 4 (Client Portal Activation),
 * finding 4.3. Per-record authorization boundary — the REAL gate, not
 * MatterResource::getEloquentQuery()'s list-level filter alone. Mirrors
 * App\Filament\Firm\Resources\MatterResource\Pages\ViewMatter's own
 * "resolveRecord() re-checks the real policy service" shape exactly,
 * here keyed on ClientPortalMatterAccessPolicyService::canAccessMatter()
 * rather than MatterAccessPolicyService — never trusting the list
 * query's own whereIn() as the actual boundary.
 *
 * Field allowlist (per Mission 4 research): status,
 * primaryPracticeArea name, assignedAttorney display name, opened_at/
 * closed_at only. Deliberately exposes NOTHING else off Matter —
 * no conflictCheckRuns, matterAssignments, intakeSubmissions,
 * readinessScore, timeEntries, expenses, matterBudgets,
 * leverageRecommendations, or any internal note/strategy field.
 */
class ViewMatter extends ViewRecord
{
    protected static string $resource = MatterResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function resolveRecord(int|string $key): Model
    {
        /** @var Matter $record */
        $record = parent::resolveRecord($key);

        /** @var ClientPortalUser|null $portalUser */
        $portalUser = Auth::guard('client')->user();

        abort_unless(
            $portalUser !== null && app(ClientPortalMatterAccessPolicyService::class)->canAccessMatter($portalUser, $record),
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
                    TextEntry::make('primaryPracticeArea.name')->label('Practice Area')->placeholder('—'),
                    TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(fn ($state): string => is_object($state) ? $state->value : (string) $state),
                    TextEntry::make('assignedAttorney.name')->label('Attorney')->placeholder('—'),
                    TextEntry::make('opened_at')->dateTime()->placeholder('—'),
                    TextEntry::make('closed_at')->dateTime()->placeholder('—'),
                ]),
        ]);
    }
}
