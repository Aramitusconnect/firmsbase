<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\PlatformInvoiceStatus;
use App\Enums\PlatformPaymentAttemptStatus;
use App\Enums\PlatformSubscriptionStatus;
use App\Enums\RecordStatus;
use App\Filament\Resources\BillingAccountResource\Pages\ListBillingAccounts;
use App\Filament\Resources\BillingAccountResource\Pages\ViewBillingAccount;
use App\Models\BillingAccount;
use App\Models\Organization;
use App\Models\PlatformAdmin;
use App\Services\PlatformBillingCommercialOverviewService;
use App\Services\PlatformStaffAccessPolicyService;
use App\Support\MoneyDisplay;
use BackedEnum;
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
 * BillingAccountResource — Billing & Commercial Control Plane pass. The
 * commercial view of a billing account: the bill-to entity that
 * everything else in this domain hangs off (subscriptions, invoices,
 * payments, usage, commission), and the natural pivot between a
 * customer organization and its commercial records.
 *
 * No admin surface for `billing_accounts` existed before this pass —
 * BillingAccount appeared only as a foreign-key label on other
 * resources' tables, so there was no way to answer "what is this
 * account's commercial position?" without visiting five separate lists
 * and filtering each one by hand. This Resource is that pivot.
 *
 * READ-ONLY. BillingAccountCommercialService exposes
 * createBillingAccount()/attachToOrganization()/detachFromOrganization(),
 * but account creation and organization attachment belong to
 * commercial onboarding, not to billing oversight, and re-parenting an
 * account between organizations silently moves every invoice, payment,
 * and commission event attributed to it. Neither is exposed here; this
 * Resource observes commercial state rather than changing it, and every
 * mutation in this domain stays on the resource that owns it
 * (subscriptions cancel from Subscriptions, invoices finalize/void from
 * Invoices, and so on).
 *
 * NO PAYMENT INSTRUMENT DATA IS DISPLAYED, ANYWHERE. billing_accounts
 * has a `payment_method_ref` column, and it is deliberately never shown
 * or made searchable/filterable by this Resource. It is a placeholder
 * reference with no real payment-method lifecycle behind it (there is
 * no production gateway to have created one), so rendering it would
 * suggest a stored instrument that does not exist — and a gateway
 * customer/payment-method reference is not a value that belongs on a
 * list page regardless. No card brand, last four, expiry, bank detail,
 * token, or gateway secret appears in this Resource, and no
 * "payment method health" indicator is shown, because no real source
 * for one exists.
 *
 * PERFORMANCE. The list is a plain Eloquent query with withCount /
 * withSum aggregates pushed into SQL — never a per-row relation load.
 * Outstanding balance is a scalar subquery over platform_invoices, so
 * the page cost is flat in the number of accounts on screen, not in the
 * number of invoices in the system.
 *
 * `billing_accounts` is platform-global with no RLS (re-verified at
 * this pass's HEAD against RowLevelSecurityCoverageMappingService's
 * EXEMPT_TABLES, not inherited from an earlier report), so an ordinary
 * ->query() table is correct and no firm/tenant context is involved.
 * Authorization is the gate here, and it is applied server-side in
 * canViewAny().
 */
class BillingAccountResource extends Resource
{
    protected static ?string $model = BillingAccount::class;

    protected static ?string $slug = 'billing-accounts';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static ?string $navigationLabel = 'Billing Accounts';

    protected static string|\UnitEnum|null $navigationGroup = 'Billing & Commercial';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'name';

    /**
     * The statuses whose invoice totals count toward an account's
     * outstanding balance. Draft is not issued; Paid and Void are
     * settled. There is no partial-payment netting in this domain — a
     * platform invoice is charged for its full total and marked paid on
     * success — so an unpaid invoice's outstanding amount is its total.
     * Kept here as one definition so the list column and the detail page
     * can never disagree.
     *
     * @var array<int, string>
     */
    public const OUTSTANDING_INVOICE_STATUSES = [
        PlatformInvoiceStatus::Open->value,
        PlatformInvoiceStatus::PastDue->value,
    ];

    /**
     * Subscription statuses that mean "this account is live". Used for
     * the live-subscription count and the has/has-no-live-subscription
     * filter.
     *
     * @var array<int, string>
     */
    public const LIVE_SUBSCRIPTION_STATUSES = [
        PlatformSubscriptionStatus::Active->value,
        PlatformSubscriptionStatus::Trialing->value,
        PlatformSubscriptionStatus::PastDue->value,
    ];

    public static function canAccess(): bool
    {
        return static::canViewAny();
    }

    public static function canViewAny(): bool
    {
        $admin = Auth::guard('platform_admin')->user();

        if (! $admin instanceof PlatformAdmin) {
            return false;
        }

        return app(PlatformStaffAccessPolicyService::class)->canAccessPlatformBilling($admin)->allowed;
    }

    /**
     * The aggregate columns every view of a billing account needs.
     * Shared by the list table and the detail page so both report the
     * same numbers from the same SQL.
     */
    public static function withCommercialAggregates(Builder $query): Builder
    {
        return $query
            ->with('organization')
            ->withCount([
                'firms',
                'platformSubscriptions as live_subscriptions_count' => fn (Builder $q) => $q
                    ->whereIn('status', self::LIVE_SUBSCRIPTION_STATUSES),
                'platformInvoices as open_invoices_count' => fn (Builder $q) => $q
                    ->whereIn('status', self::OUTSTANDING_INVOICE_STATUSES),
                'platformPaymentAttempts as failed_attempts_count' => fn (Builder $q) => $q
                    ->where('status', PlatformPaymentAttemptStatus::Failed->value),
                'usageRollups as usage_records_count',
            ])
            ->withSum([
                'platformInvoices as outstanding_cents' => fn (Builder $q) => $q
                    ->whereIn('status', self::OUTSTANDING_INVOICE_STATUSES),
            ], 'total_cents');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => self::withCommercialAggregates($query))
            ->columns([
                TextColumn::make('name')
                    ->label('Billing account')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('organization.name')
                    ->label('Organization')
                    ->placeholder('Not consolidated')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (RecordStatus $state): string => Str::headline($state->value))
                    ->color(fn (RecordStatus $state): string => match ($state) {
                        RecordStatus::Active => 'success',
                        RecordStatus::Inactive => 'warning',
                        RecordStatus::Archived => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('live_subscriptions_count')
                    ->label('Live subscriptions')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('open_invoices_count')
                    ->label('Open invoices')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('outstanding_cents')
                    ->label('Outstanding')
                    ->formatStateUsing(fn (?int $state): string => MoneyDisplay::fromCents(
                        $state ?? 0,
                        PlatformBillingCommercialOverviewService::CURRENCY,
                    ))
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('failed_attempts_count')
                    ->label('Failed payment attempts')
                    ->alignEnd()
                    ->sortable(),

                // Secondary detail — kept off the default 7-column view.
                TextColumn::make('billing_email')
                    ->label('Billing email')
                    ->placeholder('—')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('bill_to_contact')
                    ->label('Bill-to contact')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('firms_count')
                    ->label('Firms')
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('usage_records_count')
                    ->label('Usage records')
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(RecordStatus::cases())
                        ->mapWithKeys(fn (RecordStatus $status): array => [$status->value => Str::headline($status->value)])
                        ->all()),
                SelectFilter::make('organization_id')
                    ->label('Organization')
                    ->searchable()
                    ->options(fn (): array => Organization::query()->orderBy('name')->pluck('name', 'id')->all()),
                Filter::make('has_outstanding_balance')
                    ->label('Has an outstanding balance')
                    ->query(fn (Builder $query): Builder => $query->whereHas(
                        'platformInvoices',
                        fn (Builder $q) => $q->whereIn('status', self::OUTSTANDING_INVOICE_STATUSES),
                    )),
                Filter::make('has_failed_payment_attempt')
                    ->label('Has a failed payment attempt')
                    ->query(fn (Builder $query): Builder => $query->whereHas(
                        'platformPaymentAttempts',
                        fn (Builder $q) => $q->where('status', PlatformPaymentAttemptStatus::Failed->value),
                    )),
                Filter::make('no_live_subscription')
                    ->label('No live subscription')
                    ->query(fn (Builder $query): Builder => $query->whereDoesntHave(
                        'platformSubscriptions',
                        fn (Builder $q) => $q->whereIn('status', self::LIVE_SUBSCRIPTION_STATUSES),
                    )),
            ])
            ->emptyStateHeading('No billing accounts found')
            ->emptyStateDescription(
                'A billing account is the bill-to entity FirmsVault charges. Accounts are created during '.
                'commercial onboarding, not from this console.'
            )
            ->defaultSort('name')
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBillingAccounts::route('/'),
            'view' => ViewBillingAccount::route('/{record}'),
        ];
    }
}
