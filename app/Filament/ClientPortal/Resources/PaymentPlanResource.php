<?php

declare(strict_types=1);

namespace App\Filament\ClientPortal\Resources;

use App\Filament\ClientPortal\Resources\PaymentPlanResource\Pages\ListPaymentPlans;
use App\Filament\ClientPortal\Resources\PaymentPlanResource\Pages\ViewPaymentPlan;
use App\Filament\ClientPortal\Resources\PaymentPlanResource\RelationManagers\InstallmentsRelationManager;
use App\Models\ClientPortalUser;
use App\Models\PaymentPlan;
use App\Services\ClientPortalMatterAccessPolicyService;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * PaymentPlanResource (Client Portal) — PORTAL-003. Read-only
 * visibility into ALREADY-RECORDED `PaymentPlan`/`PaymentPlanInstallment`
 * data only — no Finix/Stripe/payment-provider code is touched, and no
 * write path exists here at all (List + View only, mirroring this
 * panel's own `InvoiceResource` shape byte-for-byte).
 *
 * Scoping: identical composed rule to `InvoiceResource` —
 * `client_id` must match the authenticated ClientPortalUser's own
 * `client_id` (a PaymentPlan is always tied to exactly one client, per
 * the `payment_plans` migration's NOT NULL `client_id`), AND — when
 * `matter_id` is set (nullable on this table) — the plan's matter must
 * have an ACTIVE `ClientPortalMatterGrant`, the same "explicit grant,
 * never inferred from client_id alone" principle
 * `ClientPortalMatterAccessPolicyService` documents. A payment plan
 * with no `matter_id` at all shows based on the client_id scoping
 * alone.
 *
 * `getEloquentQuery()` is the list-level UX filter;
 * `ViewPaymentPlan::resolveRecord()` re-checks the identical rule as
 * the real per-record boundary — never trusting the list query alone.
 */
class PaymentPlanResource extends Resource
{
    protected static ?string $model = PaymentPlan::class;

    protected static ?string $slug = 'payment-plans';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDateRange;

    protected static ?string $navigationLabel = 'Payment Plans';

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'id';

    public static function canAccess(): bool
    {
        return Auth::guard('client')->check() && parent::canAccess();
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
     * list-level filter and ViewPaymentPlan::resolveRecord()'s boundary
     * check — kept in exactly one place so the two can never drift.
     */
    public static function isVisibleToPortalUser(PaymentPlan $plan, ClientPortalUser $portalUser): bool
    {
        if ((int) $plan->client_id !== (int) $portalUser->client_id) {
            return false;
        }

        if ($plan->matter_id === null) {
            return true;
        }

        return app(ClientPortalMatterAccessPolicyService::class)->canAccessMatter($portalUser, $plan->matter);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('Plan')->formatStateUsing(fn ($state): string => "#{$state}")->sortable(),
                TextColumn::make('matter.stage')->label('Matter')->placeholder('—'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => is_object($state) ? (string) str($state->value)->headline() : (string) $state)
                    ->color(fn ($state): string => match (is_object($state) ? $state->value : $state) {
                        'active' => 'success',
                        'completed' => 'primary',
                        'draft' => 'gray',
                        'paused' => 'warning',
                        'renegotiated' => 'info',
                        'defaulted', 'cancelled' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('total_cents')
                    ->label('Total')
                    ->formatStateUsing(fn (int $state): string => '$'.number_format($state / 100, 2))
                    ->sortable(),
                TextColumn::make('installment_count')->label('Installments'),
                TextColumn::make('activated_at')->dateTime()->placeholder('—')->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([])
            ->toolbarActions([])
            ->emptyStateHeading('No payment plans to show yet');
    }

    public static function getRelations(): array
    {
        return [
            InstallmentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPaymentPlans::route('/'),
            'view' => ViewPaymentPlan::route('/{record}'),
        ];
    }
}
