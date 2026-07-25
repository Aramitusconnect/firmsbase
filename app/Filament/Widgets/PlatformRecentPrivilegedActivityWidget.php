<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\PlatformAdmin;
use App\Services\PlatformStaffAccessPolicyService;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * PlatformRecentPrivilegedActivityWidget — Phase 1 FirmsVault Admin
 * Control Center, Executive Dashboard. The most recent 10 security_events
 * rows platform-wide — reuses
 * PlatformSecurityDashboardService::recentSecurityEvents() (the same
 * per-firm-loop-then-merge read PlatformSecurityDashboardPage's own
 * table already uses, under its own 2-minute cache), never a second,
 * independent read mechanism.
 *
 * Gate: canAccessSecurityLogs() — the same gate
 * PlatformSecurityDashboardPage uses for the identical data.
 *
 * Data source: a plain array (this widget's `$this->snapshot['recent_activity']['events']`,
 * already computed by App\Services\PlatformExecutiveDashboardService and
 * injected via the Dashboard page's getWidgetData()) — never an Eloquent
 * query, so ->records() is used exactly like
 * PlatformSecurityDashboardPage/PlatformIntegrationOverviewPage's own
 * raw-Collection tables.
 *
 * Empty state: emptyStateHeading() below, matching
 * PlatformSecurityDashboardPage's own wording exactly.
 */
class PlatformRecentPrivilegedActivityWidget extends TableWidget
{
    protected static ?int $sort = 0;

    /**
     * See PlatformEnvironmentBadgeWidget's own docblock — every
     * Executive Dashboard widget reads from a pre-computed `$snapshot`,
     * so lazy-loading (Filament's default) buys nothing here.
     */
    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    /**
     * @var array<string, mixed>
     */
    public array $snapshot = [];

    public static function canView(): bool
    {
        $admin = Auth::guard('platform_admin')->user();

        if (! $admin instanceof PlatformAdmin) {
            return false;
        }

        return app(PlatformStaffAccessPolicyService::class)->canAccessSecurityLogs($admin)->allowed;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Recent Privileged Activity')
            ->records(function (): Collection {
                $section = $this->snapshot['recent_activity'] ?? null;

                if ($section === null || ($section['authorized'] ?? false) !== true) {
                    return collect();
                }

                return collect($section['events'] ?? []);
            })
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime()->sortable(),
                TextColumn::make('firm_name')->label('Firm')->placeholder('—'),
                TextColumn::make('category')->badge(),
                TextColumn::make('event_type')->label('Event'),
                TextColumn::make('actor_type')->label('Actor type'),
                TextColumn::make('actor_id')->label('Actor ID'),
            ])
            ->emptyStateHeading('No recent security activity')
            ->paginated(false);
    }
}
