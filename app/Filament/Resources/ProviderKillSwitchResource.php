<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\ProviderKillSwitchResource\Pages\ListProviderKillSwitches;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Models\ProviderKillSwitch;
use App\Models\PlatformAdmin;
use App\Services\PlatformStaffAccessPolicyService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

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
 */
class ProviderKillSwitchResource extends Resource
{
    protected static ?string $model = ProviderKillSwitch::class;

    protected static ?string $slug = 'provider-kill-switches';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNoSymbol;

    protected static ?string $navigationLabel = 'Kill Switches';

    protected static string|\UnitEnum|null $navigationGroup = 'Integrations';

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
            ->query(ProviderKillSwitch::query()->where('provider_key', ProviderKey::Plaid->value))
            ->columns([
                TextColumn::make('level')->badge(),
                TextColumn::make('target'),
                TextColumn::make('scope_type')->label('Scope'),
                IconColumn::make('suspended')->boolean()->label('Suspended'),
                TextColumn::make('reason')->limit(60)->placeholder('—'),
                TextColumn::make('suspended_at')->dateTime()->placeholder('—'),
            ])
            ->headerActions([
                self::createAction(),
            ])
            ->recordActions([
                self::toggleAction(),
            ])
            ->emptyStateHeading('No kill switches configured for Plaid');
    }

    private static function createAction(): Action
    {
        return Action::make('createKillSwitch')
            ->label('New Kill Switch')
            ->schema([
                Select::make('level')
                    ->options([
                        ProviderKillSwitch::LEVEL_PRODUCT => 'Product',
                        ProviderKillSwitch::LEVEL_ENDPOINT_CATEGORY => 'Endpoint category',
                        ProviderKillSwitch::LEVEL_OPERATION => 'Operation',
                    ])
                    ->required(),
                TextInput::make('target')->label('Target (e.g. product/operation name)')->required(),
                Textarea::make('reason')->required(),
            ])
            ->action(function (array $data): void {
                $admin = Auth::guard('platform_admin')->user();

                if (! $admin instanceof PlatformAdmin) {
                    return;
                }

                // Checkpoint 4 test-gate fix: this codebase's own
                // "read_only_auditor may never mutate data" rule
                // (already correctly enforced elsewhere, e.g.
                // ArchivePlanAction) was not applied here — an admin
                // holding both PlatformAdmin and ReadOnlyAuditor could
                // create a kill switch despite the read-only role,
                // confirmed by a real test proving the gap. canAccessIntegrationOversight()
                // above only gates the read/list view, never mutation.
                $mutateDecision = app(PlatformStaffAccessPolicyService::class)->canMutate($admin);

                if (! $mutateDecision->allowed) {
                    Notification::make()->title('Not permitted')->body($mutateDecision->reason)->danger()->send();

                    return;
                }

                ProviderKillSwitch::query()->create([
                    'provider_key' => ProviderKey::Plaid->value,
                    'scope_type' => ProviderKillSwitch::SCOPE_PLATFORM,
                    'scope_id' => null,
                    'level' => $data['level'],
                    'target' => $data['target'],
                    'suspended' => true,
                    'reason' => $data['reason'],
                    'suspended_by' => $admin->id,
                    'suspended_at' => now(),
                ]);

                Notification::make()->title('Kill switch created')->success()->send();
            });
    }

    private static function toggleAction(): Action
    {
        return Action::make('toggle')
            ->label(fn (ProviderKillSwitch $record): string => $record->suspended ? 'Resume' : 'Suspend')
            ->color(fn (ProviderKillSwitch $record): string => $record->suspended ? 'success' : 'danger')
            ->requiresConfirmation()
            ->action(function (ProviderKillSwitch $record): void {
                $admin = Auth::guard('platform_admin')->user();

                if (! $admin instanceof PlatformAdmin) {
                    return;
                }

                $mutateDecision = app(PlatformStaffAccessPolicyService::class)->canMutate($admin);

                if (! $mutateDecision->allowed) {
                    Notification::make()->title('Not permitted')->body($mutateDecision->reason)->danger()->send();

                    return;
                }

                $record->update([
                    'suspended' => ! $record->suspended,
                    'suspended_by' => $admin->id,
                    'suspended_at' => now(),
                ]);

                Notification::make()->title('Kill switch updated')->success()->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProviderKillSwitches::route('/'),
        ];
    }
}
