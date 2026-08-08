<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Models\TimelineEvent;
use App\Services\PlatformStaffAccessPolicyService;
use App\Services\TenantContextService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * PlaidAnomalyOversightPage — FirmsVault Live Integrations, Checkpoint
 * 4 ("Plaid financial evidence add-on";
 * checkpoint4-design-workspace-and-admin-ui.md §3). Lists
 * `provider_billing.anomaly_detected` `TimelineEvent` rows (written by
 * `DetectProviderUsageAnomaliesJob`), firm+product+count only, never
 * the underlying calls. `TimelineEvent` carries permanent FORCE ROW
 * LEVEL SECURITY, so cross-firm reads use the same per-firm-loop
 * pattern `PlatformConnectionDirectoryService`/
 * `PlatformPlaidCostOversightReadService` already establish.
 */
class PlaidAnomalyOversightPage extends Page implements HasTable
{
    use InteractsWithTable;

    private const MAX_FIRMS_SCANNED = 500;

    protected string $view = 'filament-panels::pages.page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?string $navigationLabel = 'Plaid Anomalies';

    protected static string|\UnitEnum|null $navigationGroup = 'Integrations';

    protected static ?string $title = 'Plaid Usage Anomalies';

    public static function canAccess(): bool
    {
        $admin = Auth::guard('platform_admin')->user();

        if (! $admin instanceof PlatformAdmin) {
            return false;
        }

        return app(PlatformStaffAccessPolicyService::class)->canAccessIntegrationOversight($admin)->allowed;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (): Collection {
                $admin = Auth::guard('platform_admin')->user();

                if (! $admin instanceof PlatformAdmin) {
                    return collect();
                }

                if (! app(PlatformStaffAccessPolicyService::class)->canAccessIntegrationOversight($admin)->allowed) {
                    return collect();
                }

                $tenantContext = new TenantContextService;
                $rows = collect();

                Firm::query()
                    ->orderBy('id')
                    ->limit(self::MAX_FIRMS_SCANNED)
                    ->get(['id', 'uuid', 'name'])
                    ->each(function (Firm $firm) use (&$rows, $tenantContext) {
                        // $firm->id, not $firm -- this model was loaded with a
                        // restricted column list (see ->get() above), which
                        // omits deployment_mode/organization_id that
                        // TenantContextResolver::resolveForFirm() needs.
                        // Passing the partial model directly throws; passing
                        // the id makes runWithFirmContext() re-fetch the full
                        // row. Mirrors the established, working precedent in
                        // DetectProviderUsageAnomaliesJob and
                        // ExpireStaleProviderReservationsJob.
                        $events = $tenantContext->runWithFirmContext($firm->id, fn () => TimelineEvent::query()
                            ->where('firm_id', $firm->id)
                            ->where('event_type', 'provider_billing.anomaly_detected')
                            ->orderByDesc('occurred_at')
                            ->limit(20)
                            ->get());

                        foreach ($events as $event) {
                            $rows->push([
                                'id' => $event->uuid,
                                'firm_name' => $firm->name,
                                'firm_uuid' => $firm->uuid,
                                'product' => $event->metadata_json['product'] ?? '—',
                                'current_window_count' => $event->metadata_json['current_window_count'] ?? '—',
                                'baseline_daily_average' => $event->metadata_json['baseline_daily_average'] ?? '—',
                                'occurred_at' => $event->occurred_at,
                            ]);
                        }
                    });

                return $rows->sortByDesc('occurred_at')->values();
            })
            ->columns([
                TextColumn::make('firm_name')->label('Firm')->description(fn (array $record): string => (string) ($record['firm_uuid'] ?? '')),
                TextColumn::make('product')->label('Product'),
                TextColumn::make('current_window_count')->label('Current window count')->alignEnd(),
                TextColumn::make('baseline_daily_average')->label('Baseline daily average')->alignEnd(),
                TextColumn::make('occurred_at')->label('Detected')->dateTime(),
            ])
            ->emptyStateHeading('No anomalies detected')
            ->paginated([25, 50]);
    }
}
