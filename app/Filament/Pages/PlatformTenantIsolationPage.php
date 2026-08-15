<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\PlatformAdmin;
use App\Services\PlatformStaffAccessPolicyService;
use App\Services\RlsSecurityReportService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;

/**
 * PlatformTenantIsolationPage — Phase 1 FirmsVault Admin Control
 * Center. Named to match the existing `Platform*Page` convention (see
 * PlatformSecurityDashboardPage's own docblock for the same reasoning).
 * Consumes RlsSecurityReportService::cachedGenerate()/
 * runtimeRoleSecurityState() — the extraction target of this
 * checkpoint's item 2 — never re-implements any of that assembly logic
 * itself.
 *
 * Gate: canAccessSecurityLogs() — the mission brief does not name a
 * specific gate for this page, so the existing, already-reused
 * canAccessSecurityLogs() check (identical to PlatformSecurityDashboardPage)
 * was chosen: this page surfaces live RLS enforcement state, which is
 * security-log-adjacent oversight data in the same spirit as
 * PlatformStaffAccessPolicyService's own rule 7/8 ("security auditors
 * can see security logs"), even though it carries no secret/credential
 * material. Documented here as a judgment call, not silently assumed.
 *
 * Caching + rate limiting, per the mission's explicit requirement:
 *  - The full report is never assembled on every page request — content()/
 *    table() below both call RlsSecurityReportService::cachedGenerate(),
 *    a Cache::remember() wrapper with a 5-minute TTL (see that method's
 *    own docblock).
 *  - The "Refresh" header Action explicitly busts that cache
 *    (forgetCachedGenerate()) and is itself rate-limited via Laravel's
 *    RateLimiter — once per minute, keyed per authenticated PlatformAdmin
 *    (never a single global key, so one admin refreshing cannot exhaust
 *    another admin's own refresh budget) — before it is allowed to run.
 *
 * Never renders a database credential, connection string, host, or
 * port anywhere on this page — only table names, booleans, counts, the
 * runtime role NAME (not a credential), and timestamps.
 */
class PlatformTenantIsolationPage extends Page
{
    protected string $view = 'filament-panels::pages.page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLockClosed;

    protected static ?string $navigationLabel = 'Tenant Isolation';

    protected static string|\UnitEnum|null $navigationGroup = 'Security';

    protected static ?string $title = 'Tenant Isolation';

    private const REFRESH_RATE_LIMIT_KEY_PREFIX = 'tenant-isolation-report-refresh:';

    private const REFRESH_MAX_ATTEMPTS_PER_MINUTE = 1;

    public static function canAccess(): bool
    {
        $admin = Auth::guard('platform_admin')->user();

        if (! $admin instanceof PlatformAdmin) {
            return false;
        }

        return app(PlatformStaffAccessPolicyService::class)->canAccessSecurityLogs($admin)->allowed;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    protected function getHeaderActions(): array
    {
        return [$this->refreshAction()];
    }

    public function content(Schema $schema): Schema
    {
        $reportService = app(RlsSecurityReportService::class);
        $report = $reportService->cachedGenerate();
        $runtimeRole = $reportService->runtimeRoleSecurityState();

        $summary = $report['summary'];
        $totalTenantOwnedTables = $summary['prepared'] + $summary['uncovered'];

        return $schema->components([
            Section::make('Coverage Summary')
                ->columns(3)
                ->schema([
                    Text::make("Total tenant-owned tables: {$totalTenantOwnedTables}"),
                    Text::make("Prepared (RLS enabled): {$summary['prepared']}"),
                    Text::make("FORCE RLS active: {$summary['forced']}"),
                    Text::make("Uncovered: {$summary['uncovered']}"),
                    Text::make("Exempt: {$summary['exempt']}"),
                    Text::make('Last verification: '.($report['generated_at'] ?? '—')),
                ]),
            Section::make('Runtime Database Role')
                ->description('No credential, connection string, host, or port is ever shown here — role name and RLS-relevant attributes only.')
                ->columns(3)
                ->schema([
                    Text::make('Role: '.($runtimeRole['role'] ?? '<unavailable>')),
                    Text::make('Superuser: '.$this->formatTriBool($runtimeRole['is_superuser']))
                        ->color($runtimeRole['is_superuser'] === true ? 'danger' : 'success'),
                    Text::make('BYPASSRLS: '.$this->formatTriBool($runtimeRole['has_bypass_rls']))
                        ->color($runtimeRole['has_bypass_rls'] === true ? 'danger' : 'success'),
                ]),
            Section::make('Database Connection')
                ->schema([
                    Text::make('Driver: '.($report['database']['driver'] ?? '<unavailable>')),
                    Text::make(fn (): string => $report['database']['connected']
                        ? 'Connected.'
                        : 'No usable database connection — report is degraded: '.($report['database']['error'] ?? 'unknown error'))
                        ->color($report['database']['connected'] ? 'success' : 'danger'),
                ])
                ->collapsible()
                ->collapsed(),
        ]);
    }

    private function formatTriBool(?bool $value): string
    {
        return match ($value) {
            true => 'yes',
            false => 'no',
            null => '<unavailable>',
        };
    }

    private function refreshAction(): Action
    {
        return Action::make('refresh')
            ->label('Refresh')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->action(function (): void {
                $admin = Auth::guard('platform_admin')->user();

                if (! $admin instanceof PlatformAdmin) {
                    Notification::make()->title('You are not signed in as a platform admin.')->danger()->send();

                    return;
                }

                $rateLimitKey = self::REFRESH_RATE_LIMIT_KEY_PREFIX.$admin->id;

                if (RateLimiter::tooManyAttempts($rateLimitKey, self::REFRESH_MAX_ATTEMPTS_PER_MINUTE)) {
                    $availableIn = RateLimiter::availableIn($rateLimitKey);

                    Notification::make()
                        ->title('Refresh already requested recently')
                        ->body("Please wait {$availableIn} second(s) before refreshing again.")
                        ->warning()
                        ->send();

                    return;
                }

                RateLimiter::hit($rateLimitKey, 60);

                app(RlsSecurityReportService::class)->forgetCachedGenerate();

                Notification::make()->title('Report refreshed')->success()->send();
            });
    }
}
