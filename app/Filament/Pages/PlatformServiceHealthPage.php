<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\HealthCheckStatus;
use App\Enums\HealthCheckType;
use App\Filament\Actions\Platform\RunHealthChecksNowAction;
use App\Models\HealthCheck;
use App\Models\PlatformAdmin;
use App\Services\PlatformStaffAccessPolicyService;
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
 * PlatformServiceHealthPage — Phase 4 (FirmsVault Platform Admin
 * Control Center, "Operations"). List page over `health_checks`
 * (platform-wide rows only — firm_id IS NULL; the one firm-specific
 * check type, TenantIsolationAnomalies, is intentionally left to the
 * existing Phase 1 Tenant Isolation page rather than duplicated here —
 * see phase4-architecture-map-operations-governance.md §A.1's own
 * recommendation).
 *
 * `health_checks` carries FORCE ROW LEVEL SECURITY with the
 * "nullable-firm_id, universal read" two-policy shape — firm_id IS
 * NULL rows are visible under the read policy regardless of active
 * tenant context, so this page's plain `whereNull('firm_id')` query
 * needs no runWithFirmContext()/runWithoutFirmContext() wrap at all
 * (a genuinely simpler case than every other FORCE-RLS table in this
 * mission — see that same architecture-map section).
 *
 * Disclosure: 6 of the 9 registered check types
 * (WebUptime/Storage/EmailDelivery/PaymentWebhooks/DocumentScanning)
 * are hardcoded stub callables in HealthCheckRegistry — always
 * "Healthy," with no real provider behind them. This is stated
 * honestly on the page itself, not hidden.
 */
class PlatformServiceHealthPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament-panels::pages.page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHeart;

    protected static ?string $navigationLabel = 'Service Health';

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 80;

    protected static ?string $title = 'Service Health';

    public static function canAccess(): bool
    {
        $admin = Auth::guard('platform_admin')->user();

        if (! $admin instanceof PlatformAdmin) {
            return false;
        }

        return app(PlatformStaffAccessPolicyService::class)->canAccessOperations($admin)->allowed;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            $this->disclosureSection(),
            EmbeddedTable::make(),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            RunHealthChecksNowAction::make(),
        ];
    }

    private function disclosureSection(): Section
    {
        return Section::make('About This Data')
            ->icon(Heroicon::OutlinedInformationCircle)
            ->collapsible()
            ->schema([
                Text::make(
                    '6 of the 9 registered check types (Web Uptime, Storage, Email Delivery, Payment Webhooks, '.
                    'Document Scanning) are stub checks with no real external provider behind them yet — they always '.
                    'report Healthy. Queue Workers, Failed Jobs, and Scheduler delegate to real, live data '.
                    '(QueueHealthService/SchedulerHealthService). This table is populated by a scheduled sweep '.
                    '(health:checks:run, every 5 minutes) as of this phase, plus whatever an admin triggers manually '.
                    'below — it may show sparse or no history if the scheduler has not run yet in this environment.'
                ),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => HealthCheck::query()->whereNull('firm_id'))
            ->filters([
                SelectFilter::make('check_type')
                    ->label('Check type')
                    ->options(collect(HealthCheckType::cases())
                        ->mapWithKeys(fn (HealthCheckType $type): array => [$type->value => Str::headline($type->value)])
                        ->all()),
                SelectFilter::make('status')
                    ->options(collect(HealthCheckStatus::cases())
                        ->mapWithKeys(fn (HealthCheckStatus $status): array => [$status->value => Str::headline($status->value)])
                        ->all()),
            ])
            ->columns([
                TextColumn::make('check_type')
                    ->label('Check type')
                    ->formatStateUsing(fn (HealthCheckType $state): string => Str::headline($state->value))
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (HealthCheckStatus $state): string => Str::headline($state->value))
                    ->color(fn (HealthCheckStatus $state): string => match ($state) {
                        HealthCheckStatus::Healthy => 'success',
                        HealthCheckStatus::Degraded => 'warning',
                        HealthCheckStatus::Unhealthy, HealthCheckStatus::Unknown => 'danger',
                    })
                    ->sortable(),
                TextColumn::make('detail')->label('Detail')->wrap()->placeholder('—'),
                TextColumn::make('checked_at')->label('Checked at')->dateTime()->sortable(),
                TextColumn::make('id')->label('#')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->emptyStateHeading('No health checks recorded yet')
            ->emptyStateDescription('Run health checks now, or wait for the scheduled sweep to populate this table.')
            ->defaultSort('id', 'desc')
            ->paginated([25, 50, 100]);
    }
}
