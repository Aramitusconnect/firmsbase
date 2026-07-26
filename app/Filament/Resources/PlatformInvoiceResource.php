<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\PlatformInvoiceStatus;
use App\Filament\Resources\PlatformInvoiceResource\Pages\ListPlatformInvoices;
use App\Filament\Resources\PlatformInvoiceResource\Pages\ViewPlatformInvoice;
use App\Filament\Resources\PlatformInvoiceResource\RelationManagers\InvoiceLinesRelationManager;
use App\Models\PlatformAdmin;
use App\Models\PlatformInvoice;
use App\Services\PlatformStaffAccessPolicyService;
use App\Support\MoneyDisplay;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * PlatformInvoiceResource — Phase 3 (FirmsVault Platform Admin Control
 * Center, "Billing and Commercial Administration"). Cross-cutting
 * oversight of `platform_invoices` (PLATFORM billing only — see that
 * model's own docblock for the deliberate separation from this repo's
 * internal-phase-numbered firm-client `invoices` table; the two never
 * share a table, an enum, or a code path).
 *
 * `platform_invoices` carries no BelongsToTenant/RLS at all (the Phase 3
 * architecture investigation confirmed it is `TenantOwnershipClassification::
 * Global`), so — exactly like FirmResource/PlatformAdministratorResource —
 * this Resource uses a completely ordinary Eloquent-backed `->query()`
 * table with real route-model-binding (`{record}` resolves by `uuid`,
 * via HasPublicUuid), never the `->records()` closure workaround
 * FORCE-RLS'd cross-firm tables in the Integration Operations Center
 * pass required.
 *
 * List+View pages only, no Create/Edit form — invoices are created
 * exclusively through PlatformInvoiceService::createDraftInvoice()/
 * addLine() from the normal billing workflow, never a Filament
 * data-entry form.
 *
 * The only two mutating Actions are Finalize/Void
 * (App\Filament\Actions\Platform\FinalizePlatformInvoiceAction/
 * VoidPlatformInvoiceAction), calling the actor-parameterized
 * PlatformInvoiceService::finalize()/void() added in this same phase's
 * backend-foundations pass. Gate: PlatformStaffAccessPolicyService::
 * canManagePlatformBilling() + the blanket canMutate() rule, both
 * checked inside each Action's own closure (see those classes).
 *
 * "Mark Paid" is DELIBERATELY not exposed anywhere in this Resource, its
 * Pages, or its Actions. PlatformInvoiceService::markPaid() is normally
 * only ever called from inside PlatformPaymentService::attemptPayment()
 * after a (simulated) gateway confirmation — exposing it as a direct
 * admin action would let an invoice show `Paid` with no corresponding
 * PlatformPayment row behind it, indistinguishable from a real,
 * gateway-confirmed payment in every existing query/report (e.g.
 * CommissionEligibilityService keys eligibility off exactly this
 * status). Per the architecture investigation's Open Decision 5, that
 * needs its own provenance mechanism (e.g. a paid_manually_by/
 * paid_manually_reason pair) before it would be safe to expose — out of
 * this phase's scope. Confirmed: no `markPaid` string appears anywhere
 * in this class, its Pages, or its Actions.
 */
class PlatformInvoiceResource extends Resource
{
    protected static ?string $model = PlatformInvoice::class;

    protected static ?string $slug = 'platform-invoices';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $navigationLabel = 'Invoices';

    protected static string|\UnitEnum|null $navigationGroup = 'Billing & Commercial';

    protected static ?int $navigationSort = 40;

    protected static ?string $recordTitleAttribute = 'uuid';

    public static function canAccess(): bool
    {
        $admin = Auth::guard('platform_admin')->user();

        if (! $admin instanceof PlatformAdmin) {
            return false;
        }

        return app(PlatformStaffAccessPolicyService::class)->canAccessPlatformBilling($admin)->allowed;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['billingAccount', 'subscription']))
            ->columns([
                TextColumn::make('billingAccount.name')->label('Billing account')->searchable()->sortable(),
                TextColumn::make('subscription.uuid')->label('Subscription')->placeholder('—')->limit(12)->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (PlatformInvoiceStatus $state): string => Str::headline($state->value))
                    ->color(fn (PlatformInvoiceStatus $state): string => match ($state) {
                        PlatformInvoiceStatus::Paid => 'success',
                        PlatformInvoiceStatus::Open => 'warning',
                        PlatformInvoiceStatus::PastDue => 'danger',
                        PlatformInvoiceStatus::Void => 'gray',
                        PlatformInvoiceStatus::Draft => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('period_starts_at')->label('Period start')->date()->sortable(),
                TextColumn::make('period_ends_at')->label('Period end')->date()->sortable(),
                TextColumn::make('subtotal_cents')->label('Subtotal')->formatStateUsing(fn (int $state): string => MoneyDisplay::fromCents($state))->alignEnd(),
                TextColumn::make('tax_cents')->label('Tax')->formatStateUsing(fn (int $state): string => MoneyDisplay::fromCents($state))->alignEnd(),
                TextColumn::make('total_cents')->label('Total')->formatStateUsing(fn (int $state): string => MoneyDisplay::fromCents($state))->alignEnd()->sortable(),
                TextColumn::make('due_at')->label('Due at')->dateTime()->sortable()->placeholder('—'),
                TextColumn::make('paid_at')->label('Paid at')->dateTime()->sortable()->placeholder('—'),
                TextColumn::make('voided_at')->label('Voided at')->dateTime()->sortable()->placeholder('—'),
                TextColumn::make('id')->label('#')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(PlatformInvoiceStatus::cases())
                        ->mapWithKeys(fn (PlatformInvoiceStatus $status): array => [$status->value => Str::headline($status->value)])
                        ->all()),
                Filter::make('period')
                    ->label('Period starts between')
                    ->schema([
                        DatePicker::make('from'),
                        DatePicker::make('until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $q, $date): Builder => $q->whereDate('period_starts_at', '>=', $date))
                            ->when($data['until'] ?? null, fn (Builder $q, $date): Builder => $q->whereDate('period_starts_at', '<=', $date));
                    }),
            ])
            ->emptyStateHeading('No invoices found')
            ->defaultSort('id', 'desc')
            ->paginated([25, 50, 100]);
    }

    public static function getRelations(): array
    {
        return [
            InvoiceLinesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPlatformInvoices::route('/'),
            'view' => ViewPlatformInvoice::route('/{record}'),
        ];
    }
}
