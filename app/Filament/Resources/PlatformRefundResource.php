<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\PlatformRefundStatus;
use App\Filament\Resources\PlatformRefundResource\Pages\ListPlatformRefunds;
use App\Filament\Resources\PlatformRefundResource\Pages\ViewPlatformRefund;
use App\Models\PlatformAdmin;
use App\Models\PlatformRefund;
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
 * PlatformRefundResource — Phase 3 (FirmsVault Platform Admin Control
 * Center, "Billing and Commercial Administration"). Cross-cutting,
 * READ-ONLY oversight of `platform_refunds`, keyed to
 * `platform_payment_id`. No RLS at all (Global), so an ordinary
 * Eloquent `->query()` table is correct, exactly like
 * PlatformInvoiceResource.
 *
 * List+View pages ONLY. ZERO Filament Actions of any kind are ever
 * registered anywhere in this class, its Pages, or its
 * RelationManagers (none exist) — no row-level actions array is ever
 * registered on the table. See PlatformRefundResourceTest's
 * positive-proof tests.
 *
 * No "Issue Refund" action: PlatformRefundService::refund() requires a
 * live StripeGateway instance and the only implementation anywhere in
 * this codebase is FakeStripeGateway — explicitly forbidden from making
 * a real Stripe API call and not even bound in the container. The
 * remaining-refundable-balance validation inside refund() is real and
 * safe, but the actual money-movement step is always simulated in this
 * codebase — exposing an "Issue Refund" action here would create the
 * false appearance of a real financial action. This mirrors the exact
 * same structural limitation as FailedPaymentResource's missing
 * "Retry" action.
 *
 * "Credits": see this class's own ViewPlatformRefund page for the
 * separate, clearly-labeled disclosure section — no Credit model,
 * table, or service exists anywhere in this codebase for platform
 * billing (confirmed by the Phase 3 architecture investigation:
 * `grep -rl "class.*Credit" app/Models` returns nothing, no `credits`
 * migration exists). This Resource does not fabricate a credits table
 * or leave a silently-empty section with no explanation.
 */
class PlatformRefundResource extends Resource
{
    protected static ?string $model = PlatformRefund::class;

    protected static ?string $slug = 'platform-refunds';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptRefund;

    protected static ?string $navigationLabel = 'Credits and Refunds';

    protected static string|\UnitEnum|null $navigationGroup = 'Billing & Commercial';

    protected static ?int $navigationSort = 43;

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
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['payment', 'payment.billingAccount']))
            ->columns([
                TextColumn::make('payment.uuid')->label('Payment')->limit(12)->placeholder('—'),
                TextColumn::make('payment.billingAccount.name')->label('Billing account')->searchable()->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (PlatformRefundStatus $state): string => Str::headline($state->value))
                    ->color(fn (PlatformRefundStatus $state): string => match ($state) {
                        PlatformRefundStatus::Completed => 'success',
                        PlatformRefundStatus::Processing, PlatformRefundStatus::Requested => 'warning',
                        PlatformRefundStatus::Failed => 'danger',
                    })
                    ->sortable(),
                TextColumn::make('amount_cents')->label('Amount')->formatStateUsing(fn (int $state): string => MoneyDisplay::fromCents($state))->alignEnd()->sortable(),
                TextColumn::make('reason')->placeholder('—')->wrap(),
                TextColumn::make('gateway_refund_ref')->label('Gateway refund ref')->placeholder('—')->fontFamily('mono'),
                TextColumn::make('requested_at')->label('Requested at')->dateTime()->sortable(),
                TextColumn::make('processed_at')->label('Processed at')->dateTime()->sortable()->placeholder('—'),
                TextColumn::make('id')->label('#')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(PlatformRefundStatus::cases())
                        ->mapWithKeys(fn (PlatformRefundStatus $status): array => [$status->value => Str::headline($status->value)])
                        ->all()),
                Filter::make('date_range')
                    ->label('Requested between')
                    ->schema([
                        DatePicker::make('from'),
                        DatePicker::make('until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $q, $date): Builder => $q->whereDate('requested_at', '>=', $date))
                            ->when($data['until'] ?? null, fn (Builder $q, $date): Builder => $q->whereDate('requested_at', '<=', $date));
                    }),
            ])
            ->emptyStateHeading('No refunds found')
            ->emptyStateDescription(
                'No "Issue Refund" action exists here — the only payment gateway in this codebase (FakeStripeGateway) '.
                'cannot move real money. See this resource\'s View page for the separate Credits disclosure.'
            )
            ->defaultSort('requested_at', 'desc')
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPlatformRefunds::route('/'),
            'view' => ViewPlatformRefund::route('/{record}'),
        ];
    }
}
