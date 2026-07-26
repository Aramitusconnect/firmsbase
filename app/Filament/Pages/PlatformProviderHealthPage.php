<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\IntegrationPlatformProviderHealthSummary;
use App\Models\PlatformAdmin;
use App\Services\PlatformStaffAccessPolicyService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * PlatformProviderHealthPage — Phase 2 of the FirmsVault Platform Admin
 * Control Center mission ("Integration Operations Center"). A
 * read-only, always-visible top-level nav page over the new
 * `integration_platform_provider_health_summaries` table (built in the
 * Phase 2 backend-foundations pass — see that table's own create
 * migration, database/migrations/2026_09_11_110001_create_integration_platform_provider_health_summaries_table.php,
 * for its exact, verified schema).
 *
 * Unlike PlatformIntegrationOverviewPage/PlatformFirmIntegrationsPage
 * (which must use a raw ->records() closure because their underlying
 * tables are either a FORCE-RLS'd per-firm table or require per-request
 * re-verification discipline against a broader access chokepoint), this
 * page's table uses an ordinary Eloquent ->query() — genuinely safe here
 * because `integration_platform_provider_health_summaries` carries NO
 * RLS/FORCE RLS at all (see that table's own "WHY THIS TABLE HAS NO RLS
 * AND NO FORCE RLS" migration docblock) and is not tenant-scoped in any
 * way, exactly mirroring FirmResource's own "plain, no-RLS table -> plain
 * ->query()-backed table" precedent. Real, genuine DB-level LIMIT/OFFSET
 * pagination (Eloquent's own ->paginate(), driven by Filament's table
 * pagination controls) rather than an in-PHP-materialized Collection.
 *
 * Gate: canAccessIntegrationOversight() — the same gate every other
 * Integration Operations Center page/resource in this pass uses.
 *
 * Structurally read-only: NO live provider calls are made anywhere in
 * this class (confirmed by inspection — every column below reads a
 * column already present on this cached summary row; nothing in this
 * class makes an HTTP/network call of any kind). This is a mission-
 * level, non-negotiable constraint (an Admin panel cannot safely
 * perform a live third-party OAuth/API call), not merely an
 * implementation choice — the ONLY writer of this table is the
 * scheduled RefreshIntegrationPlatformProviderHealthSummaryJob (via
 * IntegrationPlatformProviderHealthSummaryService::refreshForProvider()),
 * never this page.
 */
class PlatformProviderHealthPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament-panels::pages.page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    protected static ?string $navigationLabel = 'Provider Health';

    protected static ?string $title = 'Provider Health';

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
        return $schema->components([
            EmbeddedTable::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(function (): Builder {
                // Re-checked here too (not merely at canAccess()/mount
                // time), matching every other Checkpoint 11/Phase 2
                // page's own established discipline — an unauthorized
                // caller (or a caller whose role was revoked mid-session)
                // gets an empty, always-safe query rather than any real
                // rows, never trusting the page-load-time canAccess()
                // check alone.
                $admin = Auth::guard('platform_admin')->user();

                if (! $admin instanceof PlatformAdmin) {
                    return IntegrationPlatformProviderHealthSummary::query()->whereRaw('1 = 0');
                }

                if (! app(PlatformStaffAccessPolicyService::class)->canAccessIntegrationOversight($admin)->allowed) {
                    return IntegrationPlatformProviderHealthSummary::query()->whereRaw('1 = 0');
                }

                return IntegrationPlatformProviderHealthSummary::query();
            })
            ->filters([
                // Boolean-native filter (never a SelectFilter with
                // string '1'/'0' option keys against a real boolean
                // column) — this table's query is a genuine Eloquent
                // Builder, so Filament applies this filter's ->query()
                // automatically; TernaryFilter's own built-in
                // ->boolean() query shape is the correct, type-safe way
                // to filter a boolean column.
                TernaryFilter::make('provider_enabled')
                    ->label('Enabled'),
            ])
            ->columns([
                TextColumn::make('provider_code')->label('Provider')->searchable()->sortable(),
                IconColumn::make('provider_enabled')->label('Enabled')->boolean()->sortable(),
                TextColumn::make('connected_firm_count')->label('Connected Firms')->alignEnd()->sortable(),
                TextColumn::make('disconnected_firm_count')->label('Disconnected Firms')->alignEnd()->sortable(),
                TextColumn::make('firms_requiring_attention_count')
                    ->label('Firms Requiring Attention')
                    ->alignEnd()
                    ->sortable()
                    ->color(fn (int $state): string => $state > 0 ? 'warning' : 'success'),
                TextColumn::make('oauth_health_signal')->label('OAuth Health')->badge()->placeholder('—'),
                TextColumn::make('webhook_health_signal')->label('Webhook Health')->badge()->placeholder('—'),
                TextColumn::make('rate_limit_condition_signal')->label('Rate-Limit Condition')->badge()->placeholder('—'),
                TextColumn::make('recent_error_classification_summary')
                    ->label('Recent Error Classifications')
                    ->placeholder('—')
                    ->formatStateUsing(function (?array $state): string {
                        if ($state === null || $state === []) {
                            return '—';
                        }

                        return collect($state)
                            ->map(fn (mixed $count, string $category): string => "{$category}: {$count}")
                            ->implode(', ');
                    })
                    ->wrap(),
                TextColumn::make('computed_at')->label('Last Computed')->since()->sinceTooltip()->sortable(),
            ])
            ->emptyStateHeading('No provider health summaries yet')
            ->emptyStateDescription('Provider health summaries are refreshed every 5 minutes once at least one firm has connected to a provider.')
            ->defaultSort('provider_code')
            ->paginated([25, 50, 100]);
    }
}
