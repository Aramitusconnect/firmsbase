<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\BootCheckStatus;
use App\Enums\DeploymentMode;
use App\Enums\OperationsFreshness;
use App\Models\DeploymentConfig;
use App\Models\DeploymentHealthCheck;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Services\PlatformStaffAccessPolicyService;
use App\Services\TenantContextService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * PlatformDeploymentConfigsPage — Phase 4 (FirmsVault Platform Admin
 * Control Center, "Operations"). READ-ONLY cross-firm listing of
 * `deployment_configs` — one row per firm running in Dedicated/
 * PrivateEnterprise mode (see phase4-architecture-map-operations-
 * governance.md §A.4). `deployment_configs` carries FORCE ROW LEVEL
 * SECURITY (DirectTenant), so cross-firm listing requires the
 * per-firm-loop-under-runWithFirmContext() pattern — mirrored here
 * directly in this page's ->records() closure (mirrors
 * ConnectionResource/FirmUserResource's own established shape for
 * exactly this situation; no reusable cross-firm read service exists
 * for this table yet, matching the architecture map's own note that
 * this is genuine net-new read code, not a rebuild).
 *
 * This is expected to be EMPTY for any environment where every firm
 * runs plain Saas mode (the overwhelming majority) — that is a
 * disclosed, honest characteristic of this module, not a bug (see this
 * page's own disclosure section below).
 *
 * No admin-facing mutation exists for DeploymentConfig/
 * DeploymentHealthCheck (see architecture map §A.4: "no admin-facing
 * mutation exists for these today ... and none should be invented") —
 * this page is List-only, no actions of any kind.
 */
class PlatformDeploymentConfigsPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament-panels::pages.page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCircleStack;

    protected static ?string $navigationLabel = 'Dedicated Deployments';

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 87;

    protected static ?string $title = 'Dedicated Deployments';

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

    private function disclosureSection(): Section
    {
        return Section::make('About This Data')
            ->icon(Heroicon::OutlinedInformationCircle)
            ->collapsible()
            ->schema([
                Text::make(
                    'This shows firms running in Dedicated or Private Enterprise deployment mode — NOT FirmsVault\'s '.
                    'own SaaS release/CI-CD history (no such concept exists in this codebase). isolated_database/ '.
                    'isolated_storage are declarations only — no real provisioning happens here. This list is empty '.
                    'for any environment where every firm runs plain SaaS mode, which is expected and honest, not a bug.'
                ),
                Text::make(
                    'DECLARED IS NOT VERIFIED. isolated_database and isolated_storage are configuration flags a firm '.
                    'record carries — they state what was INTENDED, and nothing in this platform inspects AWS, RDS, or '.
                    'object storage to confirm any of it actually happened. The columns below are labelled Declared '.
                    'for that reason, and the matching Verified columns read "Verification Not Available" because no '.
                    'infrastructure verification capability exists here. Treat a declared-isolated deployment as '.
                    'unproven until someone confirms it out-of-band.'
                )->color('warning'),
                Text::make(
                    'Version skew status is deliberately NOT computed on this page: VersionSkewPolicyService::check() '.
                    'is a real, pure comparison function, but no part of this codebase defines a live "current SaaS '.
                    'version" to compare a reported instance version against — every existing caller of '.
                    'DeploymentHealthEnvelopeService::buildEnvelope() supplies both versions as literal test values, '.
                    'never from a real running source. Fabricating a comparison here would produce a false skew '.
                    'verdict. The "Reported version" column below shows the raw, real value each dedicated/private '.
                    'instance last reported — nothing more.'
                )->color('gray'),
            ]);
    }

    /**
     * Per-firm-loop cross-firm read: for every currently Dedicated/
     * PrivateEnterprise firm, reads that firm's own DeploymentConfig
     * and latest DeploymentHealthCheck (with version skew, when both a
     * config and a health check exist) under its own
     * runWithFirmContext() call — never a single unscoped cross-firm
     * query against a FORCE-RLS table.
     *
     * @return array<int, array<string, mixed>>
     */
    private function crossFirmRows(): array
    {
        $tenantContext = app(TenantContextService::class);
        $rows = [];

        $firms = Firm::query()
            ->whereIn('deployment_mode', [DeploymentMode::Dedicated->value, DeploymentMode::PrivateEnterprise->value])
            ->orderBy('id')
            ->get();

        foreach ($firms as $firm) {
            $row = $tenantContext->runWithFirmContext($firm, function () use ($firm) {
                $config = DeploymentConfig::query()->where('firm_id', $firm->id)->first();
                $latestHealth = DeploymentHealthCheck::query()
                    ->where('firm_id', $firm->id)
                    ->orderByDesc('id')
                    ->first();

                return [$config, $latestHealth];
            });

            [$config, $latestHealth] = $row;

            $rows[] = [
                'id' => $firm->id,
                'firm_id' => $firm->id,
                'firm_name' => $firm->name,
                'custom_domain' => $config?->custom_domain,
                'isolated_database' => $config?->isolated_database ?? false,
                'isolated_storage' => $config?->isolated_storage ?? false,
                'boot_check_status' => $config?->boot_check_status,
                'latest_health_status' => $latestHealth?->status,
                'latest_heartbeat_at' => $latestHealth?->heartbeat_at,
                'reported_version' => $latestHealth?->version,
                'migration_status' => $latestHealth?->migration_status,
                'reported_via' => $latestHealth?->reported_via,
            ];
        }

        return $rows;
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (int|string $page, int|string $recordsPerPage): LengthAwarePaginator {
                $rows = collect($this->crossFirmRows());
                $perPage = (int) $recordsPerPage;
                $pageNumber = (int) $page;

                return new LengthAwarePaginator(
                    $rows->forPage($pageNumber, $perPage)->values(),
                    $rows->count(),
                    $perPage,
                    $pageNumber,
                );
            })
            ->filters([
                SelectFilter::make('boot_check_status')
                    ->options(collect(BootCheckStatus::cases())
                        ->mapWithKeys(fn (BootCheckStatus $s): array => [$s->value => Str::headline($s->value)])
                        ->all()),
            ])
            ->columns([
                TextColumn::make('firm_name')->label('Firm'),
                TextColumn::make('custom_domain')->label('Custom domain')->placeholder('Not configured'),
                // "Declared" is load-bearing in these two labels: the
                // flag records intent, never a verified fact about
                // real infrastructure. See this page's disclosure.
                IconColumn::make('isolated_database')->label('Declared isolated DB')->boolean(),
                IconColumn::make('isolated_storage')->label('Declared isolated storage')->boolean(),
                TextColumn::make('verified_database_isolation')
                    ->label('Verified DB isolation')
                    ->state('Verification Not Available')
                    ->badge()
                    ->color('gray')
                    ->tooltip('No infrastructure verification capability exists in this platform.')
                    ->toggleable(),
                TextColumn::make('verified_storage_isolation')
                    ->label('Verified storage isolation')
                    ->state('Verification Not Available')
                    ->badge()
                    ->color('gray')
                    ->tooltip('No infrastructure verification capability exists in this platform.')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('boot_check_status')
                    ->label('Boot check')
                    ->badge()
                    ->formatStateUsing(fn (?BootCheckStatus $state): string => $state === null ? '—' : Str::headline($state->value))
                    ->color(fn (?BootCheckStatus $state): string => match ($state) {
                        BootCheckStatus::Passed => 'success',
                        BootCheckStatus::Failed => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('latest_health_status')
                    ->label('Latest health')
                    ->placeholder('—')
                    ->formatStateUsing(fn ($state): string => $state === null ? '—' : Str::headline(is_string($state) ? $state : $state->value)),
                TextColumn::make('latest_heartbeat_at')->label('Last heartbeat')->dateTime()->placeholder('Never reported'),
                TextColumn::make('heartbeat_freshness')
                    ->label('Heartbeat freshness')
                    ->badge()
                    ->state(fn (array $record): string => $this->heartbeatFreshness($record)->label())
                    ->color(fn (array $record): string => $this->heartbeatFreshness($record)->color())
                    ->description(fn (array $record): ?string => $this->heartbeatAgeDescription($record))
                    ->tooltip(
                        'No expected reporting cadence is defined for dedicated deployments anywhere in this platform, '.
                        'so an age can be measured but "overdue" cannot be decided.'
                    ),
                TextColumn::make('reported_version')
                    ->label('Reported version')
                    ->placeholder('Never reported')
                    ->tooltip('Self-reported by the deployment. Not a verified release — nothing here confirms it.'),
                TextColumn::make('migration_status')->label('Migration status')->placeholder('Not reported'),
                TextColumn::make('reported_via')
                    ->label('Reported via')
                    ->placeholder('—')
                    ->formatStateUsing(fn ($state): string => $state === null ? '—' : Str::headline(is_string($state) ? $state : $state->value)),
            ])
            ->emptyStateHeading('No dedicated/private-enterprise firms')
            ->emptyStateDescription('Every firm in this environment runs plain SaaS mode — that is expected, not a bug.')
            ->defaultSort('firm_name')
            ->paginated([25, 50, 100]);
    }

    /**
     * Heartbeat freshness for one deployment row.
     *
     * Deliberately never returns Stale. A heartbeat age is real and
     * measurable, but "too old" is only meaningful against an
     * expected reporting cadence, and no such cadence is defined for
     * dedicated deployments anywhere in this codebase — not in
     * DeploymentHealthEnvelopeService, not in the schedule, not in
     * config. Inventing a threshold here would manufacture overdue
     * verdicts (or, worse, reassuring fresh ones) out of nothing, so
     * this reports CadenceUnknown and shows the real age alongside it
     * for a human to judge.
     *
     * @param  array<string, mixed>  $record
     */
    private function heartbeatFreshness(array $record): OperationsFreshness
    {
        return ($record['latest_heartbeat_at'] ?? null) === null
            ? OperationsFreshness::NeverObserved
            : OperationsFreshness::CadenceUnknown;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function heartbeatAgeDescription(array $record): ?string
    {
        $heartbeatAt = $record['latest_heartbeat_at'] ?? null;

        if ($heartbeatAt === null) {
            return null;
        }

        return 'reported '.Carbon::parse($heartbeatAt)->diffForHumans();
    }
}
