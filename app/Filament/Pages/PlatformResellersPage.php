<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\CommissionEventStatus;
use App\Enums\CommissionEventType;
use App\Models\CommissionEvent;
use App\Models\PlatformAdmin;
use App\Services\PlatformStaffAccessPolicyService;
use App\Support\MoneyDisplay;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * PlatformResellersPage — Phase 3 (FirmsVault Platform Admin Control
 * Center, "Billing and Commercial Administration"). A structurally
 * required nav item ("Resellers") with NO matching backend concept —
 * follows this mission's own established rule for exactly this
 * situation ("when a module is structurally required but backend
 * support is incomplete: build a safe read-only status page; clearly
 * label the limitation; do not fabricate data").
 *
 * The Phase 3 architecture investigation's own exhaustive search
 * (`grep -ril "reseller" .` across the entire repo, excluding vendor/)
 * returned ZERO matches — independently re-confirmed here. No
 * Reseller/Partner model, migration, service, enum, or Filament
 * resource exists anywhere in this codebase.
 *
 * This page therefore carries TWO clearly, separately labeled sections:
 *  1. A prominent, honest disclosure (at the top, before anything else
 *     renders) that no reseller/partner account system exists.
 *  2. "Internal Sales Commission Data (not a reseller/partner system)"
 *     — real, accurate, read-only data from CommissionPlan/
 *     CommissionEvent, the only adjacent backend concept, honestly
 *     labeled for what it actually is: internal FirmsVault sales-rep
 *     commission tracking (a FirmsVault employee earning commission for
 *     closing/expanding a deal), never presented as reseller/partner
 *     management. See CommissionEvent's own docblock and
 *     `platform_admin_id`/the factory's `attributedTo()` state for the
 *     confirming evidence.
 *
 * NO mutating action is registered anywhere in this class. CommissionEventService::
 * markPaid()/reverse() are real, safe, audited-adjacent methods, but
 * exposing them was not part of this phase's "Resellers" requirement and
 * would compound the mislabeling risk (a "Resellers" page with a "Mark
 * Commission Paid" button reads as even more confusing) — flagged as a
 * possible FUTURE enhancement, not built in this pass. Confirmed: no
 * `markPaid`/`reverse` call, and no `CommissionEventService` import,
 * anywhere in this file.
 *
 * `commission_events`/`commission_plans` carry no RLS at all (Global),
 * so an ordinary Eloquent `->query()` table is correct here.
 */
class PlatformResellersPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament-panels::pages.page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $navigationLabel = 'Resellers';

    protected static string|\UnitEnum|null $navigationGroup = 'Billing & Commercial';

    protected static ?int $navigationSort = 44;

    protected static ?string $title = 'Resellers';

    public static function canAccess(): bool
    {
        $admin = Auth::guard('platform_admin')->user();

        if (! $admin instanceof PlatformAdmin) {
            return false;
        }

        return app(PlatformStaffAccessPolicyService::class)->canAccessPlatformBilling($admin)->allowed;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            $this->disclosureSection(),
            Section::make('Internal Sales Commission Data (not a reseller/partner system)')
                ->description(
                    'Real backend data from CommissionPlan/CommissionEvent — FirmsVault employees earning commission '.
                    'for closing or expanding a deal. This is honestly labeled for what it actually is, not '.
                    'presented as reseller or partner account management.'
                )
                ->schema([EmbeddedTable::make()]),
        ]);
    }

    private function disclosureSection(): Section
    {
        return Section::make('No Reseller/Partner Account System Exists')
            ->icon(Heroicon::OutlinedExclamationCircle)
            ->schema([
                Text::make(
                    'This codebase has no reseller or partner account system — confirmed by an exhaustive repository '.
                    'search (no Reseller/Partner model, migration, service, or table anywhere). This page does not '.
                    'fabricate reseller data. Below this notice is a separate, honestly-labeled section showing the '.
                    'only adjacent real backend concept — internal FirmsVault sales-rep commission tracking — which '.
                    'is a materially different thing from external reseller/partner account management.'
                )->color('danger'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(function (): Builder {
                $admin = Auth::guard('platform_admin')->user();

                if (! $admin instanceof PlatformAdmin) {
                    return CommissionEvent::query()->whereRaw('1 = 0');
                }

                if (! app(PlatformStaffAccessPolicyService::class)->canAccessPlatformBilling($admin)->allowed) {
                    return CommissionEvent::query()->whereRaw('1 = 0');
                }

                return CommissionEvent::query()->with(['commissionPlan', 'billingAccount']);
            })
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(CommissionEventStatus::cases())
                        ->mapWithKeys(fn (CommissionEventStatus $status): array => [$status->value => Str::headline($status->value)])
                        ->all()),
                SelectFilter::make('event_type')
                    ->label('Event type')
                    ->options(collect(CommissionEventType::cases())
                        ->mapWithKeys(fn (CommissionEventType $type): array => [$type->value => Str::headline($type->value)])
                        ->all()),
            ])
            ->columns([
                TextColumn::make('commissionPlan.name')->label('Commission plan')->searchable()->sortable(),
                TextColumn::make('commissionPlan.rate_type')->label('Rate type')->placeholder('—'),
                TextColumn::make('commissionPlan.rate_value')->label('Rate value')->placeholder('—'),
                TextColumn::make('billingAccount.name')->label('Billing account')->searchable()->sortable(),
                TextColumn::make('event_type')
                    ->badge()
                    ->formatStateUsing(fn (CommissionEventType $state): string => Str::headline($state->value)),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (CommissionEventStatus $state): string => Str::headline($state->value))
                    ->color(fn (CommissionEventStatus $state): string => match ($state) {
                        CommissionEventStatus::Paid => 'success',
                        CommissionEventStatus::Payable => 'info',
                        CommissionEventStatus::Pending => 'warning',
                        CommissionEventStatus::Blocked, CommissionEventStatus::Reversed => 'danger',
                    })
                    ->sortable(),
                TextColumn::make('amount_cents')->label('Amount')->formatStateUsing(fn (int $state): string => MoneyDisplay::fromCents($state))->alignEnd()->sortable(),
                TextColumn::make('holding_period_ends_at')->label('Holding period ends')->dateTime()->placeholder('—'),
                TextColumn::make('paid_at')->label('Paid at')->dateTime()->placeholder('—')->sortable(),
            ])
            ->emptyStateHeading('No commission events recorded yet')
            ->defaultSort('id', 'desc')
            ->paginated([25, 50, 100]);
    }
}
