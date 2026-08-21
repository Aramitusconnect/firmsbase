<?php

declare(strict_types=1);

namespace App\Filament\ClientPortal\Resources\MatterResource\RelationManagers;

use App\Models\ClientPortalUser;
use App\Services\ClientPortalMatterAccessPolicyService;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * DeadlinesRelationManager (Client Portal) — non-payment completion
 * program. "Deadlines" tab on the Client Portal ViewMatter page,
 * listing this matter's Deadline rows (`Matter::deadlines()`, a real,
 * already-defined HasMany) — mirrors the Firm-panel
 * DeadlinesRelationManager's own shape, gated on
 * ClientPortalMatterAccessPolicyService::canAccessMatter() instead of
 * MatterAccessPolicyService/TaskCrudAccessPolicyService (a client has
 * no role-based CRUD ceiling to check — matter access is the only
 * gate).
 *
 * Deliberately read-only, and deliberately a NARROWER column set than
 * the Firm-panel tab: title/due date/status only — no jurisdiction, no
 * "View" link-out to the internal-only DeadlineResource (Firm-panel
 * only), and no internal deadline_type/source/reminder_offsets_days
 * fields, matching MatterResource (Client Portal)'s own established
 * field-allowlist discipline.
 */
class DeadlinesRelationManager extends RelationManager
{
    protected static string $relationship = 'deadlines';

    protected static ?string $title = 'Deadlines';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        /** @var ClientPortalUser|null $portalUser */
        $portalUser = Auth::guard('client')->user();

        if ($portalUser === null) {
            return false;
        }

        return app(ClientPortalMatterAccessPolicyService::class)->canAccessMatter($portalUser, $ownerRecord);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable()->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => is_object($state) ? $state->value : (string) $state)
                    ->color(fn ($state): string => match (is_object($state) ? $state->value : $state) {
                        'completed' => 'success',
                        'due' => 'warning',
                        'missed' => 'danger',
                        'cancelled' => 'gray',
                        'upcoming' => 'gray',
                        default => 'gray',
                    }),
                TextColumn::make('due_at')->label('Due')->dateTime()->sortable(),
            ])
            ->defaultSort('due_at', 'asc')
            ->emptyStateHeading('No deadlines shared with you yet.')
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
