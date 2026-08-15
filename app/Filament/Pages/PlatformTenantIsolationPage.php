<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\PlatformAdmin;
use App\Services\PlatformAdminAuditEventRecorder;
use App\Services\PlatformStaffAccessPolicyService;
use App\Services\RlsSecurityReportService;
use App\Services\RowLevelSecurityCoverageMappingService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\UnorderedList;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

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
 *
 * CORE SuperAdmin mission additions (sections 44-49):
 *  - The old "Refresh" action is renamed "Run Verification" — it was
 *    never a harmless display refresh to begin with: busting the
 *    cache forces RlsSecurityReportService::generate() to re-run its
 *    LIVE pg_policies scan on the next read, which genuinely IS a
 *    verification, just previously mislabeled. It now also writes an
 *    audit event (none existed before) via the canonical
 *    PlatformAdminAuditEventRecorder — never a second audit mechanism.
 *  - Exemption Drill-Down and Table-Level Coverage sections expose
 *    RowLevelSecurityCoverageMappingService::exemptTableMetadata()/
 *    fullTableInventory() directly — both already-existing, static,
 *    declarative registries, never re-derived here. Missing owner/
 *    approval-reference/last-reviewed fields are shown as literal
 *    "Not recorded" text (the data gap section 46 asks to be reported
 *    IS the UI itself, not just prose in a report).
 *  - Verification History reports the genuine gap honestly
 *    (TENANT_ISOLATION_VERIFICATION_HISTORY_UNAVAILABLE) — no schema
 *    change was made to add one; see the mission's own final report
 *    for the proposed table design, held for owner approval.
 */
class PlatformTenantIsolationPage extends Page implements HasTable
{
    use InteractsWithTable;

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
        return [$this->runVerificationAction()];
    }

    public function content(Schema $schema): Schema
    {
        $reportService = app(RlsSecurityReportService::class);
        $report = $reportService->cachedGenerate();
        $runtimeRole = $reportService->runtimeRoleSecurityState();

        $summary = $report['summary'];
        // CORE SuperAdmin mission, section 44: made the denominator
        // explicit rather than implicit — tenant-owned and exempt are a
        // CLEAN PARTITION (RowLevelSecurityCoverageMappingService's own
        // class docblock), never overlapping, so "Total tenant-owned"
        // below intentionally does NOT include the exempt count.
        $totalTenantOwnedTables = $summary['prepared'] + $summary['uncovered'];

        return $schema->components([
            Section::make('Coverage Summary')
                ->columns(3)
                ->schema([
                    Text::make("Total tenant-owned tables (own firm_id column; excludes exempt/non-tenant): {$totalTenantOwnedTables}"),
                    Text::make("Prepared (RLS enabled): {$summary['prepared']}"),
                    Text::make("FORCE RLS active: {$summary['forced']}"),
                    Text::make("Uncovered: {$summary['uncovered']}"),
                    Text::make("Non-Tenant / RLS-Exempt Tables: {$summary['exempt']}"),
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
            $this->exemptionDrillDownSection(),
            Section::make('Table-Level Coverage')
                ->description('Every table in the canonical 208-table inventory — classification, RLS/FORCE RLS state, and exemption status. No mutation controls exist here (see this page\'s own class docblock — Tenant Isolation is observability only).')
                ->schema([EmbeddedTable::make()])
                ->collapsible()
                ->collapsed(),
            $this->verificationHistorySection(),
        ]);
    }

    /**
     * CORE SuperAdmin mission, section 46: shows the ACTUAL stored
     * fields (table, reason, expected readers, authorized writers) and
     * is explicit — via literal "Not recorded" text, not silence — that
     * owner/approval-reference/last-reviewed fields do not exist in
     * this registry today. Never fabricated.
     */
    private function exemptionDrillDownSection(): Section
    {
        $rows = app(RowLevelSecurityCoverageMappingService::class)->exemptTableMetadata();

        return Section::make('Exemption Drill-Down')
            ->description('Every table exempted from tenant-ownership tracking, with its recorded reason and reader/writer expectations. Owner, approval reference, and last-reviewed date are NOT recorded fields in this registry today — shown honestly as "Not recorded", never fabricated.')
            ->schema([
                UnorderedList::make(
                    collect($rows)->map(fn ($row) => Text::make(sprintf(
                        '%s — %s | Expected readers: %s | Authorized writers: %s | Owner: Not recorded | Approval reference: Not recorded | Last reviewed: Not recorded',
                        $row->table,
                        $row->reason,
                        $row->expectedReaders === [] ? 'none recorded' : implode(', ', $row->expectedReaders),
                        $row->authorizedWriters === [] ? 'none recorded' : implode(', ', $row->authorizedWriters),
                    )))->all()
                ),
            ])
            ->collapsible()
            ->collapsed();
    }

    /**
     * CORE SuperAdmin mission, section 48: no durable verification-run
     * history table exists (confirmed by direct source read of
     * RlsSecurityReportService — cachedGenerate() is a 5-minute
     * Cache::remember() over a live computation, nothing is persisted
     * past that). Reported honestly rather than fabricated from current
     * state — see this mission's own final report for the proposed
     * schema, held for owner approval per the database-change stop
     * gate.
     */
    private function verificationHistorySection(): Section
    {
        return Section::make('Verification History')
            ->schema([
                Text::make('TENANT_ISOLATION_VERIFICATION_HISTORY_UNAVAILABLE — no durable record of past verification runs exists. "Last verification" above reflects only the current cached report\'s own generation time, not a queryable history. A schema proposal for durable history is in this mission\'s final report, pending owner approval — no migration was created here.')
                    ->color('gray'),
            ])
            ->collapsible()
            ->collapsed();
    }

    public function table(Table $table): Table
    {
        return $table
            // Filament's ->records() requires Model|array rows (a
            // TenantTableInventoryItem VO is neither) — each row is
            // flattened to a plain array here, once, rather than
            // leaving every column's own state() closure to reach back
            // into a VO the table layer cannot accept in the first
            // place.
            ->records(function (): Collection {
                $service = app(RowLevelSecurityCoverageMappingService::class);

                return collect($service->fullTableInventory())
                    ->values()
                    ->map(fn ($item): array => [
                        'table' => $item->table,
                        'classification' => $item->classification,
                        'notes' => $item->notes,
                        'rls_enabled' => $service->isPrepared($item->table),
                        'force_rls' => $service->isForced($item->table),
                        'exempt' => $service->exemptMetadataFor($item->table) !== null,
                    ]);
            })
            ->columns([
                TextColumn::make('table')->label('Table')->searchable()->sortable(),
                TextColumn::make('classification')
                    ->label('Classification')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => Str::headline(is_object($state) ? $state->value : (string) $state)),
                IconColumn::make('rls_enabled')->label('RLS Enabled')->boolean(),
                IconColumn::make('force_rls')->label('FORCE RLS')->boolean(),
                IconColumn::make('exempt')->label('Exempt')->boolean(),
                TextColumn::make('notes')->label('Notes')->wrap()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->emptyStateHeading('No tables in the inventory')
            ->defaultSort('table')
            ->paginated([25, 50, 100]);
    }

    private function formatTriBool(?bool $value): string
    {
        return match ($value) {
            true => 'yes',
            false => 'no',
            null => '<unavailable>',
        };
    }

    /**
     * CORE SuperAdmin mission, section 49: renamed from "Refresh" —
     * see this class's own docblock for why this was never a harmless
     * display refresh (busting the cache forces a genuine live
     * pg_policies re-scan on the next read). Now also writes an audit
     * event (previously none existed) via the canonical
     * PlatformAdminAuditEventRecorder — this action executes the
     * existing, already-safe RlsSecurityReportService read path only;
     * it never runs a migration, never disables/enables RLS, and never
     * mutates any policy.
     */
    private function runVerificationAction(): Action
    {
        return Action::make('runVerification')
            ->label('Run Verification')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->action(function (PlatformAdminAuditEventRecorder $auditRecorder): void {
                $admin = Auth::guard('platform_admin')->user();

                if (! $admin instanceof PlatformAdmin) {
                    Notification::make()->title('You are not signed in as a platform admin.')->danger()->send();

                    return;
                }

                $rateLimitKey = self::REFRESH_RATE_LIMIT_KEY_PREFIX.$admin->id;

                if (RateLimiter::tooManyAttempts($rateLimitKey, self::REFRESH_MAX_ATTEMPTS_PER_MINUTE)) {
                    $availableIn = RateLimiter::availableIn($rateLimitKey);

                    Notification::make()
                        ->title('Verification already requested recently')
                        ->body("Please wait {$availableIn} second(s) before running verification again.")
                        ->warning()
                        ->send();

                    return;
                }

                RateLimiter::hit($rateLimitKey, 60);

                $previousGeneratedAt = app(RlsSecurityReportService::class)->cachedGenerate()['generated_at'] ?? null;

                app(RlsSecurityReportService::class)->forgetCachedGenerate();

                $auditRecorder->recordPlatformEvent($admin, 'tenant_isolation_verification_run', 'tenant_isolation', [
                    'previous_generated_at' => $previousGeneratedAt,
                ]);

                Notification::make()->title('Verification complete — report refreshed')->success()->send();
            });
    }
}
