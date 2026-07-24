<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\FirmIntegrationResource\RelationManagers;

use App\Filament\Firm\Resources\FirmIntegrationResource\Actions\ApproveConflictResolutionAction;
use App\Filament\Firm\Resources\FirmIntegrationResource\Actions\ProposeConflictResolutionAction;
use App\Integrations\Models\IntegrationConflict;
use App\Integrations\Services\IntegrationAccessPolicyService;
use App\Services\IntegrationEntitlementPolicyService;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Auth;

/**
 * ConflictsRelationManager — Checkpoint 10 (frozen-design-post-
 * security-review.md §7, §12). Lists `integration_conflicts` rows for
 * this connection and hosts the two independent Propose/Approve
 * conflict-resolution actions (§7's full two-actor design).
 *
 * `local_value`/`external_value` are DELIBERATELY never rendered
 * anywhere in this table (frozen design §9 item 2: these columns, if
 * rendered at all, must go through an allowlisted, fails-closed
 * rendering component — no live producer populates them in this
 * checkpoint, and no such rendering component is on the frozen
 * production-file allowlist, so the compliant, conservative choice is
 * to render neither column at all).
 *
 * `FirmIntegration` has no `conflicts()` Eloquent relationship — see
 * SyncRunsRelationManager's identical getRelationship() override
 * rationale (a manually-constructed `HasMany`, never a bare `Builder`,
 * to avoid `Table::getRelationshipQuery()`'s confirmed `TypeError`).
 *
 * The Propose -> Approve flow is applied uniformly to every conflict
 * (not only privileged/flagged ones): `proposeResolution()`/
 * `transitionStatus()`'s distinctness check only actively REQUIRES a
 * second, different approver for privileged/flagged rows, but applying
 * the same two-actor flow to non-privileged conflicts too is strictly
 * safe (never rejected) and keeps this UI's action surface uniform and
 * simple, without a third "direct resolve" action.
 */
class ConflictsRelationManager extends RelationManager
{
    protected static string $relationship = 'conflicts';

    /**
     * See SyncRunsRelationManager::canViewForRecord()'s docblock for the
     * full rationale — same root cause (`FirmIntegration` has no
     * `conflicts()` relationship, and Filament's default
     * `canViewForRecord()` is `static` and cannot use this class's own
     * `getRelationship()` override), same fix.
     */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null || (int) $firmUser->firm_id !== (int) $ownerRecord->firm_id) {
            return false;
        }

        return app(IntegrationEntitlementPolicyService::class)->isEnabled($firmUser->firm)
            && app(IntegrationAccessPolicyService::class)->canView($firmUser->role);
    }

    public function getRelationship(): Relation|Builder
    {
        return new HasMany(
            IntegrationConflict::query(),
            $this->getOwnerRecord(),
            'firm_integration_id',
            'id',
        );
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('conflict_type')
            ->columns([
                TextColumn::make('resource_type')->badge(),
                TextColumn::make('local_type')->label('Local record type'),
                TextColumn::make('local_id')->label('Local record #'),
                TextColumn::make('conflict_type')->badge(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn ($state) => match (is_object($state) ? $state->value : $state) {
                        'detected' => 'danger',
                        'awaiting_review' => 'warning',
                        'expired' => 'gray',
                        default => 'success',
                    })
                    ->formatStateUsing(fn ($state) => is_object($state) ? $state->value : $state),
                IconColumn::make('requires_manual_review')
                    ->label('Flagged')
                    ->boolean(),
                TextColumn::make('detected_at')->dateTime(),
                TextColumn::make('resolved_at')->dateTime(),
                TextColumn::make('resolution_note')->label('Note')->limit(60)->toggleable(),
            ])
            ->defaultSort('detected_at', 'desc')
            ->recordActions([
                ProposeConflictResolutionAction::make(),
                ApproveConflictResolutionAction::make(),
            ])
            ->toolbarActions([]);
    }
}
