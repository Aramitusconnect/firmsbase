<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\InvoiceResource\RelationManagers;

use App\Models\Invoice;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Services\BillingAccessPolicyService;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Auth;

/**
 * TimelineRelationManager — "Timeline" tab on ViewInvoice. `TimelineEvent`
 * has no `invoice()` relationship and should not gain one solely for
 * this tab — same "don't add relationship methods just for a
 * RelationManager" precedent MatterResource's own ActivityRelationManager
 * establishes (see that class's docblock); getRelationship() below
 * hand-constructs a `HasMany` the identical way, scoped to
 * subject_type = Invoice::class.
 *
 * Read-only. Rows appear once InvoiceDraftingService records an event
 * subject-scoped to this Invoice — today that's 'invoice_drafted' (both
 * draftFromTimeEntries() and createFlatFee()) and 'invoice_sent', each
 * via `$this->timeline->record($firm, '...', $invoice, ...)`. This tab
 * is purely a display surface — no write path is added or changed
 * here.
 */
class TimelineRelationManager extends RelationManager
{
    // Mirrors ActivityRelationManager's own reasoning: getRelationship()
    // is overridden below (no real invoice() relationship exists to
    // name here), which leaves Filament with no string to derive a tab
    // title from — this explicit $title avoids the resulting crash.
    protected static ?string $title = 'Timeline';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null || ! $ownerRecord instanceof Invoice || (int) $firmUser->firm_id !== (int) $ownerRecord->firm_id) {
            return false;
        }

        return app(BillingAccessPolicyService::class)->canViewBilling($firmUser->role);
    }

    public function getRelationship(): Relation|Builder
    {
        return new HasMany(
            TimelineEvent::query()->where('subject_type', Invoice::class),
            $this->getOwnerRecord(),
            'subject_id',
            'id',
        );
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('event_type'),
                TextColumn::make('actor_id')
                    ->label('Actor')
                    ->formatStateUsing(fn (?int $state): ?string => $state === null ? null : User::query()->find($state)?->name)
                    ->placeholder('—'),
                TextColumn::make('occurred_at')->dateTime()->sortable(),
            ])
            ->defaultSort('occurred_at', 'desc')
            ->emptyStateHeading('No timeline events recorded yet for this invoice.')
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
