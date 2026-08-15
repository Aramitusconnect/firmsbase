<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\BillingInterval;
use App\Enums\PlatformSubscriptionStatus;
use App\Filament\Actions\Platform\CancelSubscriptionAction;
use App\Filament\Resources\PlatformSubscriptionResource\Pages\ListPlatformSubscriptions;
use App\Filament\Resources\PlatformSubscriptionResource\Pages\ViewPlatformSubscription;
use App\Models\Plan;
use App\Models\PlatformAdmin;
use App\Models\PlatformSubscription;
use App\Services\PlatformBillingCommercialOverviewService;
use App\Services\PlatformStaffAccessPolicyService;
use App\Support\MoneyDisplay;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * PlatformSubscriptionResource — Phase 3 (FirmsVault Platform Admin
 * Control Center, "Billing and Commercial Administration"). Cross-firm
 * List+View oversight over `platform_subscriptions`, the PLATFORM
 * billing subscription record (billing_account -> plan), never a
 * firm-client PaymentPlan (project rule 1 — see PlatformSubscription's
 * own docblock). No RLS on this table (Global — confirmed by the Phase
 * 3 architecture investigation), so — unlike ConnectionResource/
 * FirmUserResource's ->records() closure workaround for FORCE-RLS
 * tables — this Resource uses a completely ordinary Eloquent ->query()
 * table, mirroring FirmResource/PlatformAdministratorResource's
 * established shape.
 *
 * List+View only, no Create/Edit form — a PlatformSubscription is
 * created exclusively via PlatformSubscriptionService::subscribe()
 * (part of a larger commercial-onboarding workflow out of this
 * checkpoint's scope, per the mission's own "no generic Create/Edit
 * forms" convention already established across Phases 1-2). The one
 * mutating surface is the Cancel action (CancelSubscriptionAction),
 * routed exclusively through PlatformSubscriptionService::cancel() (the
 * only method PlatformSubscriptionService exposes for this Resource,
 * as of the Phase 3 backend-foundations pass).
 *
 * Read gate: PlatformStaffAccessPolicyService::canAccessPlatformBilling()
 * — the pre-existing, broader read gate (SuperAdmin/PlatformAdmin/
 * BillingAdmin). Mutating-action gate: canManagePlatformBilling() (added
 * in the backend-foundations pass, narrower — SuperAdmin/PlatformAdmin
 * only), checked inside CancelSubscriptionAction's own closure, never
 * here.
 */
class PlatformSubscriptionResource extends Resource
{
    protected static ?string $model = PlatformSubscription::class;

    protected static ?string $slug = 'platform-subscriptions';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static ?string $navigationLabel = 'Subscriptions';

    protected static string|\UnitEnum|null $navigationGroup = 'Billing & Commercial';

    protected static ?int $navigationSort = 21;

    protected static ?string $recordTitleAttribute = 'uuid';

    /**
     * Direct role-service check (not Laravel Policy auto-resolution) —
     * no Policy class is registered for PlatformSubscription anywhere in
     * this codebase (confirmed: app/Policies contains only Firm/
     * FirmUser/PlatformAdmin policies), so this mirrors ConnectionResource/
     * DeadLetterQueueResource's own established "manual canAccess()"
     * shape rather than registering a new, unnecessary Policy binding.
     */
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

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with(['billingAccount.organization', 'plan'])
                // Pushed into SQL as a scalar subquery — the recurring
                // amount below must never trigger a per-row load of a
                // subscription's line items.
                ->withSum('items as items_cents', DB::raw('quantity * unit_amount_cents')))
            ->columns([
                TextColumn::make('billingAccount.name')
                    ->label('Billing account')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('billingAccount.organization.name')
                    ->label('Organization')
                    ->placeholder('Not consolidated')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('plan.name')
                    ->label('Plan')
                    ->searchable()
                    ->sortable(),
                /**
                 * The subscription's recurring amount for its own
                 * billing interval. A platform subscription stores no
                 * price of its own — only plan_id — so the plan's
                 * current price IS its price, plus any line items. That
                 * is safe to render as authoritative here precisely
                 * because PlanService::update() refuses to change a
                 * plan's price_cents or billing_interval once any
                 * subscription or firm license references it: a
                 * subscriber's financial terms cannot move underneath
                 * them. Not sortable — it is a computed composite, and
                 * offering a sort that silently ordered by only part of
                 * it would be worse than offering none.
                 */
                TextColumn::make('recurring_amount')
                    ->label('Amount')
                    ->state(fn (PlatformSubscription $record): string => MoneyDisplay::fromCents(
                        (int) ($record->plan?->price_cents ?? 0) + (int) ($record->items_cents ?? 0),
                        PlatformBillingCommercialOverviewService::CURRENCY,
                    ))
                    ->description(fn (PlatformSubscription $record): string => 'per '.Str::lower(
                        Str::headline($record->billing_interval->value)
                    ).' term')
                    ->alignEnd(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (PlatformSubscriptionStatus $state): string => Str::headline($state->value))
                    ->color(fn (PlatformSubscriptionStatus $state): string => match ($state) {
                        PlatformSubscriptionStatus::Active => 'success',
                        PlatformSubscriptionStatus::Trialing => 'info',
                        PlatformSubscriptionStatus::PastDue => 'warning',
                        PlatformSubscriptionStatus::Cancelled, PlatformSubscriptionStatus::Expired => 'danger',
                    })
                    ->sortable(),
                TextColumn::make('billing_interval')
                    ->label('Billing interval')
                    ->formatStateUsing(fn (BillingInterval $state): string => Str::headline($state->value))
                    ->sortable(),
                TextColumn::make('current_period_starts_at')
                    ->label('Period start')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('current_period_ends_at')
                    ->label('Period end')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('trial_ends_at')
                    ->label('Trial ends')
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable(),
                IconColumn::make('cancel_at_period_end')
                    ->label('Cancel at period end')
                    ->boolean(),
                TextColumn::make('cancelled_at')
                    ->label('Cancelled at')
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(PlatformSubscriptionStatus::cases())
                        ->mapWithKeys(fn (PlatformSubscriptionStatus $status): array => [$status->value => Str::headline($status->value)])
                        ->all()),
                SelectFilter::make('plan_id')
                    ->label('Plan')
                    ->searchable()
                    ->options(fn (): array => Plan::query()->orderBy('name')->pluck('name', 'id')->all()),
                SelectFilter::make('billing_interval')
                    ->label('Billing interval')
                    ->options(collect(BillingInterval::cases())
                        ->mapWithKeys(fn (BillingInterval $interval): array => [$interval->value => Str::headline($interval->value)])
                        ->all()),
                TernaryFilter::make('cancel_at_period_end')
                    ->label('Cancelling at period end')
                    ->placeholder('Any')
                    ->trueLabel('Scheduled to cancel')
                    ->falseLabel('Not cancelling'),

                // Deliberately NO billing-account select filter. Building
                // its options would mean plucking every billing account
                // in the platform into PHP on every render — an
                // unbounded load that grows with the customer base,
                // unlike the plan filter above (plans are a small,
                // deliberately-curated catalog). The billing account
                // column is already searchable, which answers the same
                // question without the unbounded query.
            ])
            ->recordActions([
                CancelSubscriptionAction::make(),
            ])
            ->emptyStateHeading('No subscriptions found')
            ->emptyStateDescription('Platform subscriptions are created when a billing account subscribes to a plan.')
            ->defaultSort('current_period_starts_at', 'desc')
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPlatformSubscriptions::route('/'),
            'view' => ViewPlatformSubscription::route('/{record}'),
        ];
    }
}
