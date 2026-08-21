<?php

declare(strict_types=1);

namespace App\Filament\ClientPortal\Resources;

use App\Filament\ClientPortal\Resources\InvoiceResource\Pages\ListInvoices;
use App\Filament\ClientPortal\Resources\InvoiceResource\Pages\ViewInvoice;
use App\Models\ClientPortalUser;
use App\Models\Invoice;
use App\Services\ClientPortalMatterAccessPolicyService;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * InvoiceResource (Client Portal) — Mission 4 (Client Portal
 * Activation), finding 4.6. Read-only visibility into ALREADY-RECORDED
 * `Invoice`/`Payment` data only — no Stripe/Finix/payment-provider code
 * is touched, and no write path exists here at all (List + View only).
 *
 * Scoping: `client_id` must match the authenticated ClientPortalUser's
 * own `client_id` (an Invoice is always addressed to exactly one
 * client), AND — when `matter_id` is set (it is nullable per the
 * `invoices` migration) — the invoice's matter must have an ACTIVE
 * `ClientPortalMatterGrant`, exactly the same "explicit grant, never
 * inferred from client_id alone" principle
 * `ClientPortalMatterAccessPolicyService`'s own docblock documents for
 * matters themselves. An invoice for a matter the client has no grant
 * for must never be visible here, even though its `client_id` matches.
 * An invoice with no `matter_id` at all shows based on the client_id
 * scoping alone, per this mission's own explicit guidance.
 *
 * `getEloquentQuery()` is the list-level UX filter;
 * `ViewInvoice::resolveRecord()` re-checks the identical rule as the
 * real per-record boundary — never trusting the list query alone.
 *
 * canViewAny()/canView() overrides: `App\Models\Invoice` already has a
 * Firm-panel-scoped `App\Policies\InvoicePolicy` (viewAny(User $user)/
 * view(User $user, Invoice $invoice)), auto-discovered by Laravel's
 * standard model-to-policy naming convention. Left to Filament's
 * default Gate-based authorization, the Client Portal sidebar's own
 * navigation-visibility check would invoke that SAME policy against
 * the currently-authenticated `client`-guard actor — a
 * `ClientPortalUser`, not the `User` that policy's own type-hint
 * requires — crashing every authenticated Client Portal page render
 * with a TypeError the instant this resource is registered. Overriding
 * both methods here to defer entirely to this resource's own real
 * boundary (canAccess()/isVisibleToPortalUser()) bypasses that shared
 * policy — the same "list is UX filter, resolve step is the boundary"
 * split this class's own docblock already documents, just extended to
 * cover navigation/list-page reachability too.
 */
class InvoiceResource extends Resource
{
    protected static ?string $model = Invoice::class;

    protected static ?string $slug = 'invoices';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCurrencyDollar;

    protected static ?string $navigationLabel = 'Invoices';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'id';

    public static function canAccess(): bool
    {
        return Auth::guard('client')->check() && parent::canAccess();
    }

    /**
     * Deliberately NOT `return static::canAccess();` — Filament's own
     * default canAccess() (Concerns/HasAuthorization.php) is
     * `return static::canViewAny();`, and this class's own canAccess()
     * above calls `parent::canAccess()` — so canViewAny() delegating
     * back to canAccess() would be infinite mutual recursion
     * (canAccess -> canViewAny -> canAccess -> ...), hanging every
     * request that reaches either method. Checks the guard directly
     * instead, matching canAccess()'s own logic without calling it.
     */
    public static function canViewAny(): bool
    {
        return Auth::guard('client')->check();
    }

    public static function canView($record): bool
    {
        /** @var ClientPortalUser|null $portalUser */
        $portalUser = Auth::guard('client')->user();

        return $portalUser !== null
            && $record instanceof Invoice
            && static::isVisibleToPortalUser($record, $portalUser);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        /** @var ClientPortalUser|null $portalUser */
        $portalUser = Auth::guard('client')->user();

        if ($portalUser === null) {
            return $query->whereRaw('1 = 0');
        }

        $grantedMatterIds = app(ClientPortalMatterAccessPolicyService::class)->grantedMatterIds($portalUser);

        return $query
            ->where('client_id', $portalUser->client_id)
            ->where(function (Builder $matterGate) use ($grantedMatterIds) {
                $matterGate->whereNull('matter_id');

                if ($grantedMatterIds !== []) {
                    $matterGate->orWhereIn('matter_id', $grantedMatterIds);
                }
            });
    }

    /**
     * The real per-record rule, shared by both getEloquentQuery()'s
     * list-level filter and ViewInvoice::resolveRecord()'s boundary
     * check — kept in exactly one place so the two can never drift.
     */
    public static function isVisibleToPortalUser(Invoice $invoice, ClientPortalUser $portalUser): bool
    {
        if ((int) $invoice->client_id !== (int) $portalUser->client_id) {
            return false;
        }

        if ($invoice->matter_id === null) {
            return true;
        }

        return app(ClientPortalMatterAccessPolicyService::class)->canAccessMatter($portalUser, $invoice->matter);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('Invoice')->formatStateUsing(fn ($state): string => "#{$state}")->sortable(),
                TextColumn::make('matter.stage')->label('Matter')->placeholder('—'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => is_object($state) ? (string) str($state->value)->headline() : (string) $state)
                    ->color(fn ($state): string => match (is_object($state) ? $state->value : $state) {
                        'paid' => 'success',
                        'sent', 'approved' => 'info',
                        'partially_paid' => 'warning',
                        'void', 'written_off' => 'gray',
                        'refunded' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('total_cents')
                    ->label('Total')
                    ->formatStateUsing(fn (int $state): string => '$'.number_format($state / 100, 2))
                    ->sortable(),
                TextColumn::make('amount_paid_cents')
                    ->label('Paid')
                    ->formatStateUsing(fn (int $state): string => '$'.number_format($state / 100, 2))
                    ->sortable(),
                TextColumn::make('balance_due')
                    ->label('Balance Due')
                    ->state(fn (Invoice $record): string => '$'.number_format(($record->total_cents - $record->amount_paid_cents) / 100, 2)),
                TextColumn::make('issued_at')->label('Issued')->dateTime()->placeholder('—')->sortable(),
            ])
            ->defaultSort('issued_at', 'desc')
            ->recordActions([])
            ->toolbarActions([])
            ->emptyStateHeading('No invoices to show yet');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInvoices::route('/'),
            'view' => ViewInvoice::route('/{record}'),
        ];
    }
}
