<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\PlatformPaymentAttemptStatus;
use App\Filament\Resources\FailedPaymentResource\Pages\ListFailedPayments;
use App\Filament\Resources\FailedPaymentResource\Pages\ViewFailedPayment;
use App\Models\PlatformAdmin;
use App\Models\PlatformPaymentAttempt;
use App\Services\PlatformStaffAccessPolicyService;
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

/**
 * FailedPaymentResource — Phase 3 (FirmsVault Platform Admin Control
 * Center, "Billing and Commercial Administration"). Cross-cutting,
 * READ-ONLY oversight of `platform_payment_attempts` rows scoped to
 * `status = Failed` — one row per failed attempt to collect a platform
 * invoice via FakeStripeGateway (see PlatformPaymentAttempt's own
 * docblock). `platform_payment_attempts` carries no RLS at all (Global),
 * so an ordinary Eloquent `->query()` table is correct here, exactly
 * like PlatformInvoiceResource.
 *
 * Single-resource design decision (see the Phase 3 architecture
 * investigation's own "your call" note on this module): rather than a
 * second resource/tab for `platform_payments` rows in a failed state,
 * this Resource covers PlatformPaymentAttempt only, with the parent
 * PlatformInvoice linked directly from each row (an attempt already
 * carries every column the mission's own spec asked for — billing
 * account, invoice, attempt number, gateway response code, failure
 * reason, attempted at — with no need for a second data source). The
 * View page additionally surfaces any PlatformPayment rows in `Failed`
 * status for the SAME invoice as read-only context (see
 * ViewFailedPayment), so nothing from `platform_payments` is lost, just
 * not force-fit into a second resource/tab.
 *
 * Correction to the mission brief's own phrasing: PlatformPaymentStatus
 * has exactly 5 cases (Pending/Succeeded/Failed/Refunded/
 * PartiallyRefunded) — confirmed by reading that enum directly. There
 * is no "PastDue" case on PlatformPaymentStatus (PastDue exists only on
 * PlatformInvoiceStatus) — so "Failed/PastDue state" for
 * `platform_payments` narrows to exactly `Failed` here, not a
 * fabricated status this codebase does not have.
 *
 * List+View pages ONLY. ZERO Filament Actions of any kind are ever
 * registered anywhere in this class, its Pages, or its RelationManagers
 * (none exist) — no row-level actions array is ever registered on the
 * table; Filament's own default
 * row-click behavior (a plain Eloquent-backed table needs no explicit
 * "view" Action, unlike the ->records()-closure-backed cross-firm
 * resources in the Integration Operations Center pass) is the only
 * navigation affordance, and it is not itself a registered Action
 * instance. See FailedPaymentResourceTest's positive-proof tests.
 *
 * No "Retry" action.
 *
 * Billing & Commercial Control Plane pass — gateway-truth correction.
 * The Phase 3 docblock this replaces claimed "StripeGateway has no
 * container binding — confirmed by grep"; that is stale. It IS bound
 * today in AppServiceProvider, resolved through
 * PaymentGatewaySimulationPolicyService::isSimulationEnabled():
 *   - testing, and local with PAYMENT_GATEWAY_SIMULATION_ENABLED=true
 *     → FakeStripeGateway (simulated only, never a real Stripe call),
 *   - staging, production, everything else → UnavailablePaymentGateway,
 *     which throws PaymentProviderUnavailableException rather than
 *     fabricating a ['status' => 'succeeded'] response.
 * The conclusion is unchanged and in fact stronger: retrying a failed
 * payment through PlatformPaymentService::attemptPayment() can only
 * ever simulate an outcome in a test, and cannot run at all in the
 * environments an operator uses this console from. There is no live
 * payment-collection path to expose as a genuine admin action.
 *
 * No dunning/recovery state either: `platform_payment_attempts` has no
 * next-retry, retry-state, dunning-state, customer-notified, or
 * resolved-at column (confirmed against the create migration at this
 * pass's HEAD), and no scheduler or job anywhere retries a platform
 * payment. So there is nothing real behind a "Recovery Rate" or "Next
 * Retry" figure, and none is displayed.
 *
 * No "Waive" action: no such concept exists anywhere in
 * PlatformPaymentAttemptStatus, PlatformPaymentStatus, or any platform-
 * billing service method (confirmed — neither enum has a "waived" case,
 * and no service method resembling markWaived() exists for platform
 * billing; the firm-client side's PaymentPlanInstallmentService::
 * markWaived() has no analog here).
 */
class FailedPaymentResource extends Resource
{
    protected static ?string $model = PlatformPaymentAttempt::class;

    protected static ?string $slug = 'failed-payments';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?string $navigationLabel = 'Failed Payments';

    protected static string|\UnitEnum|null $navigationGroup = 'Billing & Commercial';

    protected static ?int $navigationSort = 41;

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
            ->query(fn (): Builder => PlatformPaymentAttempt::query()
                ->where('status', PlatformPaymentAttemptStatus::Failed)
                ->with(['billingAccount', 'invoice']))
            ->columns([
                TextColumn::make('billingAccount.name')->label('Billing account')->searchable()->sortable(),
                TextColumn::make('invoice.uuid')
                    ->label('Invoice')
                    ->placeholder('—')
                    ->limit(12)
                    ->url(fn (PlatformPaymentAttempt $record): ?string => $record->platform_invoice_id === null
                        ? null
                        : PlatformInvoiceResource::getUrl('view', ['record' => $record->invoice])),
                TextColumn::make('attempt_number')->label('Attempt #')->alignEnd()->sortable(),
                TextColumn::make('gateway_response_code')->label('Gateway response')->placeholder('—'),
                TextColumn::make('failure_reason')->label('Failure reason')->placeholder('—')->wrap(),
                TextColumn::make('attempted_at')->label('Attempted at')->dateTime()->sortable(),
                TextColumn::make('id')->label('#')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('date_range')
                    ->label('Attempted between')
                    ->schema([
                        DatePicker::make('from'),
                        DatePicker::make('until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $q, $date): Builder => $q->whereDate('attempted_at', '>=', $date))
                            ->when($data['until'] ?? null, fn (Builder $q, $date): Builder => $q->whereDate('attempted_at', '<=', $date));
                    }),
                SelectFilter::make('failure_reason')
                    ->label('Failure reason')
                    ->options(fn (): array => PlatformPaymentAttempt::query()
                        ->where('status', PlatformPaymentAttemptStatus::Failed)
                        ->whereNotNull('failure_reason')
                        ->distinct()
                        ->orderBy('failure_reason')
                        ->pluck('failure_reason', 'failure_reason')
                        ->all()),
            ])
            ->emptyStateHeading('No failed payments found')
            ->emptyStateDescription(
                'Payment recovery is not operational: no production payment gateway is configured, so no "Retry" '.
                'action exists here. No "Waive" action exists either — no such concept exists in this domain\'s '.
                'status enums or services. There is also no retry schedule or dunning state to show, because this '.
                'domain stores none. This is a read-only oversight view.'
            )
            ->defaultSort('attempted_at', 'desc')
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFailedPayments::route('/'),
            'view' => ViewFailedPayment::route('/{record}'),
        ];
    }
}
