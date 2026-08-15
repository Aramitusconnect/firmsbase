<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Actions\Platform\CreateProviderKillSwitchAction;
use App\Filament\Actions\Platform\ToggleProviderKillSwitchAction;
use App\Filament\Resources\ProviderKillSwitchResource\Pages\ListProviderKillSwitches;
use App\Filament\Support\Integrations\IntegrationDisplay;
use App\Filament\Support\Integrations\ProviderKillSwitchScope;
use App\Integrations\Models\ProviderKillSwitch;
use App\Models\PlatformAdmin;
use App\Services\PlatformStaffAccessPolicyService;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * ProviderKillSwitchResource — FirmsVault Live Integrations, Checkpoint
 * 4 ("Plaid financial evidence add-on";
 * checkpoint4-design-workspace-and-admin-ui.md §3;
 * checkpoint4-combined-design.md §8.5). Direct CRUD-VIA-ACTIONS (not a
 * generic Filament form) over `provider_kill_switches` — mirroring
 * `PlatformAdministratorResource`'s established "mutations via
 * dedicated Action classes" convention. This is the ONE place
 * PlatformAdmin *writes*, by design — kill switches are platform-admin-
 * authored per cost-control §4.2.
 *
 * CORRECTED during Checkpoint 6's cross-provider ops review: this
 * resource was hardcoded to `provider_key = Plaid` in both the list
 * query and the create action, so an operator could not even create a
 * kill-switch row for Microsoft 365 or Google Workspace — those two
 * providers had no admin-triggerable emergency disable at all (their
 * outbound calls never routed through `ProviderBillableCallPipeline`,
 * the only code that ever checked this table). Now provider-selectable,
 * and `ProviderRequestExecutor::send()` — the shared outbound path
 * every provider's adapter routes through — checks a new, provider-
 * agnostic `ProviderKillSwitch::LEVEL_PROVIDER` "kill the whole
 * provider" row before every call, for all providers uniformly (see
 * that method's own docblock). Plaid's existing fine-grained
 * product/endpoint-category/operation-level switches are untouched and
 * still work exactly as before, checked by the billing pipeline.
 */
class ProviderKillSwitchResource extends Resource
{
    protected static ?string $model = ProviderKillSwitch::class;

    protected static ?string $slug = 'provider-kill-switches';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNoSymbol;

    /**
     * Naming (§18/§137): "Provider Kill Switches" — the sidebar said
     * "Kill Switches" while the panel also carries an unrelated platform
     * AI kill switch (PlatformAiOversightPage), so the bare name was
     * ambiguous about which subsystem it suspends.
     */
    protected static ?string $navigationLabel = 'Provider Kill Switches';

    protected static ?string $modelLabel = 'Provider Kill Switch';

    protected static ?string $pluralModelLabel = 'Provider Kill Switches';

    protected static string|\UnitEnum|null $navigationGroup = 'Integrations';

    protected static ?int $navigationSort = 13;

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

        return app(PlatformStaffAccessPolicyService::class)->canAccessIntegrationOversight($admin)->allowed;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->query(ProviderKillSwitch::query()->whereIn('provider_key', array_keys(IntegrationDisplay::liveProviderOptions())))
            ->filters([
                SelectFilter::make('provider_key')
                    ->label('Provider')
                    ->options(fn (): array => IntegrationDisplay::liveProviderOptions()),
                SelectFilter::make('level')
                    ->label('Level')
                    ->options(fn (): array => ProviderKillSwitchScope::levelOptions()),
                TernaryFilter::make('suspended')
                    ->label('State')
                    ->placeholder('Any')
                    ->trueLabel('Active (suspending calls)')
                    ->falseLabel('Released'),
            ])
            ->columns([
                TextColumn::make('provider_key')
                    ->label('Provider')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => IntegrationDisplay::labelForProviderCode($state))
                    ->sortable(),
                // "Suspended" as a bare boolean icon read ambiguously on
                // a screen where every row is a suspension: a checkmark
                // could mean "this switch is fine" as easily as "calls
                // are being refused". An explicit two-state badge cannot
                // be misread (§20/§98).
                TextColumn::make('suspended')
                    ->label('State')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Active — calls refused' : 'Released')
                    ->color(fn (bool $state): string => $state ? 'danger' : 'gray')
                    ->sortable(),
                TextColumn::make('level')
                    ->label('Level')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => Str::headline((string) $state))
                    ->tooltip(fn (ProviderKillSwitch $record): string => ProviderKillSwitchScope::enforcementDisclosure($record->level))
                    ->sortable(),
                TextColumn::make('target')->label('Target')->fontFamily('mono'),
                TextColumn::make('reason')
                    ->label('Reason')
                    ->limit(60)
                    ->wrap()
                    // reason is NOT NULL in practice for every row this
                    // console writes (both actions require it), so an
                    // empty one means a row predating that requirement —
                    // named honestly rather than shown as a dash.
                    ->formatStateUsing(fn (?string $state): string => IntegrationDisplay::orAbsent($state, 'No reason recorded')),
                TextColumn::make('suspendedBy.name')
                    ->label('Changed By')
                    ->formatStateUsing(fn (?string $state): string => IntegrationDisplay::orAbsent($state, 'Unknown actor'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('suspended_at')
                    ->label('Changed At')
                    ->dateTime()
                    ->sortable()
                    ->placeholder(IntegrationDisplay::UNKNOWN),
                // Scope is constant by construction (see
                // ProviderKillSwitchScope::ENFORCED_SCOPE) but is shown
                // so an operator never has to assume it.
                TextColumn::make('scope_type')
                    ->label('Scope')
                    ->formatStateUsing(fn (?string $state): string => $state === ProviderKillSwitch::SCOPE_PLATFORM ? 'Platform-wide' : Str::headline((string) $state))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                CreateProviderKillSwitchAction::make(),
            ])
            ->recordActions([
                ToggleProviderKillSwitchAction::make(),
            ])
            ->defaultSort('suspended', 'desc')
            ->emptyStateHeading('No provider kill switches configured')
            ->emptyStateDescription('No provider is currently suspended. Kill switches are created here during an incident and refuse outbound provider calls platform-wide the moment they are activated.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProviderKillSwitches::route('/'),
        ];
    }
}
