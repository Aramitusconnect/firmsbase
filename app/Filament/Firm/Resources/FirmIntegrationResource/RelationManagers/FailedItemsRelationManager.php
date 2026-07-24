<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\FirmIntegrationResource\RelationManagers;

use App\Filament\Firm\Resources\FirmIntegrationResource\Actions\RequeueOutboxEventAction;
use App\Filament\Firm\Resources\FirmIntegrationResource\Actions\RequeueSyncItemAction;
use App\Integrations\Enums\OutboxEventStatus;
use App\Integrations\Enums\SyncItemStatus;
use App\Integrations\Models\IntegrationOutboxEvent;
use App\Integrations\Models\IntegrationSyncItem;
use App\Integrations\Services\IntegrationAccessPolicyService;
use App\Services\IntegrationEntitlementPolicyService;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Auth;

/**
 * FailedItemsRelationManager — Checkpoint 10 (frozen-design-post-
 * security-review.md §5, §12; agent-10e-sync-requeue-conflict-ui.md §4d
 * "Failed Items / Dead-Lettered Events"). A single combined, read-only
 * view of BOTH failure surfaces for this connection:
 *   - `IntegrationOutboxEvent` rows with status = dead_lettered
 *   - `IntegrationSyncItem` rows with status = failed_permanent
 * (`last_error` on both is UI-safe to render directly — 10D's
 * inventory classifies it SAFE-BY-CALLER-DISCIPLINE, and both writing
 * services name the parameter `$sanitizedError`/`$lastError`
 * specifically to signal that discipline).
 *
 * Neither underlying table has a single, uniform Eloquent shape a
 * normal relationship-backed table could bind to, so this table is
 * built via Filament's `Table::records()` array-data-source feature
 * (`vendor/filament/tables/src/Table/Concerns/HasRecords.php`) instead
 * of `getRelationship()` — each combined row is a plain array keyed
 * `type` ('outbox_event'|'sync_item') and `model_id` (the REAL
 * underlying primary key), never a raw Eloquent model of either kind.
 * `RequeueOutboxEventAction`/`RequeueSyncItemAction` are BOTH
 * registered as row actions; each is independently `->visible()`-gated
 * on the row's own `type`, so exactly one is ever shown per row.
 *
 * getRelationship() is still overridden (required by
 * InteractsWithRelationshipTable::makeTable(), which unconditionally
 * calls it while building the base table) and returns a harmless,
 * always-empty fallback relation — its underlying rows are never
 * actually fetched for display, since `records()` below makes
 * `Table::hasQuery()` false, which is what `HasRecords::
 * getTableRecords()` checks to prefer the array data source over the
 * query/relationship path. HOWEVER, `Table::getRelationshipQuery()`
 * itself (see `vendor/filament/tables/src/Table/Concerns/HasQuery.php`)
 * is still called and its return value TYPE-CHECKED during table
 * initialization regardless of `hasQuery()` — confirmed empirically:
 * a bare Eloquent `Builder` here throws the exact same
 * `getRelationshipQuery(): Return value must be of type
 * ?Illuminate\Database\Eloquent\Builder, Illuminate\Database\Query\
 * Builder returned` `TypeError` that SyncRunsRelationManager/
 * ConflictsRelationManager hit, even though this fallback relation's
 * rows are never displayed. A genuine `HasMany` `Relation` avoids that
 * TypeError the same way it does there. `id`/`id` (rather than a
 * `firm_integration_id`-style key — `integration_sync_items` has no
 * such column) is used as the foreign/local key pair purely because
 * both columns definitely exist on both sides, keeping this
 * intentionally-never-fetched fallback relation inert and side-effect
 * free even if that assumption is ever violated in the future; the
 * `whereRaw('1 = 0')` below is what actually guarantees zero rows.
 */
class FailedItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'failedItems';

    /**
     * Filament's default `canViewForRecord()` (`public static`) tries
     * `$ownerRecord->{static::getRelationshipName()}()` — i.e.
     * `$firmIntegration->failedItems()` — which doesn't exist on
     * `FirmIntegration` at all (this manager has no single backing
     * relationship even in principle; see this class's own top
     * docblock on the combined-array `records()` data source), so the
     * default logic throws before the table ever renders. This override
     * replaces it with a direct authorization check — the same
     * firm-membership + entitlement + role gate every other tab on this
     * page uses — rather than attempting to resolve any relationship at
     * all.
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
            IntegrationSyncItem::query()->whereRaw('1 = 0'),
            $this->getOwnerRecord(),
            'id',
            'id',
        );
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function () {
                $connectionId = $this->getOwnerRecord()->getKey();

                $outboxEvents = IntegrationOutboxEvent::query()
                    ->where('firm_integration_id', $connectionId)
                    ->where('status', OutboxEventStatus::DeadLettered->value)
                    ->orderByDesc('dead_lettered_at')
                    ->limit(200)
                    ->get()
                    ->map(static fn (IntegrationOutboxEvent $event): array => [
                        'id' => "outbox:{$event->id}",
                        'type' => 'outbox_event',
                        'model_id' => $event->id,
                        'label' => $event->event_type,
                        'detail' => trim(($event->resource_type ?? '').($event->resource_id !== null ? " #{$event->resource_id}" : '')),
                        'last_error' => $event->last_error,
                        'failed_at' => $event->dead_lettered_at,
                        'requeue_count' => $event->requeue_count,
                    ]);

                $syncItems = IntegrationSyncItem::query()
                    ->join('integration_sync_runs', 'integration_sync_runs.id', '=', 'integration_sync_items.sync_run_id')
                    ->where('integration_sync_runs.firm_integration_id', $connectionId)
                    ->where('integration_sync_items.status', SyncItemStatus::FailedPermanent->value)
                    ->orderByDesc('integration_sync_items.terminal_at')
                    ->limit(200)
                    ->select('integration_sync_items.*')
                    ->get()
                    ->map(static fn (IntegrationSyncItem $item): array => [
                        'id' => "sync_item:{$item->id}",
                        'type' => 'sync_item',
                        'model_id' => $item->id,
                        'label' => $item->resource_type,
                        'detail' => $item->external_id,
                        'last_error' => $item->last_error,
                        'failed_at' => $item->terminal_at,
                        'requeue_count' => $item->requeue_count,
                    ]);

                return $outboxEvents->concat($syncItems)->sortByDesc('failed_at')->values();
            })
            ->columns([
                TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === 'outbox_event' ? 'Outbox Event' : 'Sync Item')
                    ->color(fn (string $state): string => $state === 'outbox_event' ? 'info' : 'warning'),
                TextColumn::make('label')->label('Resource / Event'),
                TextColumn::make('detail')->label('Detail'),
                TextColumn::make('last_error')->label('Error')->limit(60)->toggleable(),
                TextColumn::make('failed_at')->label('Failed at')->dateTime(),
                TextColumn::make('requeue_count')->label('Requeues')->alignEnd(),
            ])
            ->emptyStateHeading('No failed items')
            ->emptyStateDescription('Nothing needs requeuing for this connection right now.')
            ->recordActions([
                RequeueOutboxEventAction::make(),
                RequeueSyncItemAction::make(),
            ])
            // InteractsWithRelationshipTable::makeTable() installs TWO
            // default Model-typed closures before this table() override
            // ever runs: `recordAction()` (the per-row click action) and
            // — unless `hasCustomRecordUrl()` is already true —
            // `recordUrl()`. Both are typed
            // `function (Model $record, Table $table): ?string`. Every
            // row here is a plain array (see records() above and this
            // class's own top docblock), so Filament invoking EITHER
            // default closure with an array argument throws a
            // `TypeError` for ANY row — confirmed empirically for both.
            // Disabling both entirely is correct anyway: this combined
            // view has no single "view"/"edit" destination a row click
            // or row URL could sensibly resolve to.
            ->recordAction(null)
            ->recordUrl(null)
            ->toolbarActions([]);
    }
}
