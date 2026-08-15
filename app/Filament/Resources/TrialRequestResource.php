<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\TrialRequestStatus;
use App\Filament\Actions\Platform\ActivateTrialRequestAction;
use App\Filament\Actions\Platform\ConvertTrialRequestAction;
use App\Filament\Actions\Platform\ExpireTrialRequestAction;
use App\Filament\Actions\Platform\ProvisionTrialRequestAction;
use App\Filament\Resources\TrialRequestResource\Pages\ListTrialRequests;
use App\Filament\Resources\TrialRequestResource\Pages\ViewTrialRequest;
use App\Models\PlatformAdmin;
use App\Models\TrialRequest;
use App\Services\PlatformBillingCommercialOverviewService;
use App\Services\PlatformStaffAccessPolicyService;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * TrialRequestResource — Phase 3 (FirmsVault Platform Admin Control
 * Center, "Billing and Commercial Administration"). Cross-firm
 * List+View oversight over `trial_requests`. No RLS (Global — "a firm
 * does not yet exist at trial-request stage," per the RLS mapping
 * service's own note), so an ordinary Eloquent ->query() table is
 * correct, same shape as every other Resource in this phase.
 *
 * "Opportunity" column: TrialRequest itself has no displayable name —
 * it is keyed to opportunity_id, and Opportunity in turn has no name
 * column of its own, only a belongsTo PlatformLead (the actual
 * prospective firm). Rendered as
 * opportunity.platformLead.company_name (eager-loaded via
 * opportunity.platformLead, two levels deep, never per-row) rather than
 * a raw opportunity id/uuid, so this column is genuinely meaningful to
 * an admin rather than an opaque identifier. Confirmed safe to display:
 * PlatformLead.company_name is platform sales-pipeline data (a
 * prospective law firm's name), never firm-client legal/matter data.
 *
 * No Create/Edit form — a TrialRequest is created exclusively via
 * TrialRequestService::request(Opportunity, ...), part of the sales
 * pipeline workflow, out of this checkpoint's scope. Mutations are the
 * four discrete, purpose-built actions: Provision, Activate, Expire,
 * Convert — all routed through TrialRequestService, never a bare form
 * save.
 */
class TrialRequestResource extends Resource
{
    protected static ?string $model = TrialRequest::class;

    protected static ?string $slug = 'trial-requests';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBeaker;

    protected static ?string $navigationLabel = 'Trials';

    protected static string|\UnitEnum|null $navigationGroup = 'Billing & Commercial';

    protected static ?int $navigationSort = 22;

    protected static ?string $recordTitleAttribute = 'uuid';

    /**
     * No Policy class is registered for TrialRequest anywhere in this
     * codebase — mirrors ConnectionResource/PlatformSubscriptionResource's
     * own manual canAccess() shape.
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
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['opportunity.platformLead', 'organization']))
            ->columns([
                TextColumn::make('opportunity.platformLead.company_name')
                    ->label('Opportunity')
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('organization.name')
                    ->label('Organization')
                    ->placeholder('Not yet provisioned')
                    ->searchable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (TrialRequestStatus $state): string => Str::headline($state->value))
                    ->color(fn (TrialRequestStatus $state): string => match ($state) {
                        TrialRequestStatus::Active => 'success',
                        TrialRequestStatus::Converted => 'success',
                        TrialRequestStatus::Requested, TrialRequestStatus::Provisioned => 'info',
                        TrialRequestStatus::Expired, TrialRequestStatus::Cancelled => 'danger',
                    })
                    ->sortable(),
                TextColumn::make('requested_at')->label('Requested')->dateTime()->sortable(),
                TextColumn::make('provisioned_at')->label('Provisioned')->dateTime()->placeholder('—')->sortable(),
                TextColumn::make('expires_at')->label('Expires')->dateTime()->placeholder('—')->sortable(),
                /**
                 * Days remaining until expiry, derived from expires_at
                 * for trials that are still running. Deliberately blank
                 * for a trial that has already reached a terminal
                 * status: a countdown against a Converted or Expired
                 * trial is meaningless, and rendering a negative number
                 * there would read as an overdue alarm rather than as
                 * history. Computed per row from an already-loaded
                 * column — no query.
                 */
                TextColumn::make('days_remaining')
                    ->label('Days remaining')
                    ->alignEnd()
                    ->placeholder('—')
                    ->state(function (TrialRequest $record): ?string {
                        if ($record->expires_at === null) {
                            return null;
                        }

                        if (! in_array($record->status, [
                            TrialRequestStatus::Requested,
                            TrialRequestStatus::Provisioned,
                            TrialRequestStatus::Active,
                        ], true)) {
                            return null;
                        }

                        $days = CarbonImmutable::now()->diffInDays($record->expires_at, false);

                        return $days < 0 ? 'Past expiry date' : (string) (int) floor($days);
                    }),
                TextColumn::make('converted_at')->label('Converted')->dateTime()->placeholder('—')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(TrialRequestStatus::cases())
                        ->mapWithKeys(fn (TrialRequestStatus $status): array => [$status->value => Str::headline($status->value)])
                        ->all()),
                /**
                 * "Expiring soon" is scoped to trials that are still
                 * running AND have an expiry date inside the horizon —
                 * never to a trial already in a terminal status, whose
                 * expiry date is history rather than a deadline. The
                 * horizon comes from
                 * PlatformBillingCommercialOverviewService so this
                 * filter and the Billing & Commercial Overview's
                 * "Trials expiring" figure can never drift apart.
                 */
                Filter::make('expiring_soon')
                    ->label('Expiring within '.PlatformBillingCommercialOverviewService::TRIAL_EXPIRY_HORIZON_DAYS.' days')
                    ->query(function (Builder $query): Builder {
                        $now = CarbonImmutable::now();

                        return $query
                            ->whereIn('status', [
                                TrialRequestStatus::Requested->value,
                                TrialRequestStatus::Provisioned->value,
                                TrialRequestStatus::Active->value,
                            ])
                            ->whereNotNull('expires_at')
                            ->where('expires_at', '>=', $now)
                            ->where('expires_at', '<', $now->addDays(
                                PlatformBillingCommercialOverviewService::TRIAL_EXPIRY_HORIZON_DAYS
                            ));
                    }),
                Filter::make('awaiting_provisioning')
                    ->label('Awaiting provisioning')
                    ->query(fn (Builder $query): Builder => $query
                        ->where('status', TrialRequestStatus::Requested->value)),
            ])
            ->recordActions([
                ProvisionTrialRequestAction::make(),
                ActivateTrialRequestAction::make(),
                ConvertTrialRequestAction::make(),
                ExpireTrialRequestAction::make(),
            ])
            ->emptyStateHeading('No trial requests found')
            ->emptyStateDescription('Trial requests are created from the sales pipeline when an opportunity moves to trial.')
            ->defaultSort('requested_at', 'desc')
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTrialRequests::route('/'),
            'view' => ViewTrialRequest::route('/{record}'),
        ];
    }
}
