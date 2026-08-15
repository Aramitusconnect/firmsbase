<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Resources\PlatformIncidentResource;
use App\Models\PlatformAdmin;
use App\Services\PlatformSecurityDashboardService;
use App\Services\PlatformStaffAccessPolicyService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\UnorderedList;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * PlatformSecurityDashboardPage — Phase 1 FirmsVault Admin Control
 * Center. Named to match the existing `Platform*Page` convention already
 * established by PlatformIntegrationOverviewPage/
 * PlatformFirmIntegrationsPage/PlatformFirmIntegrationDetailPage (the
 * mission brief's own suggested "SecurityDashboardPage" name would be
 * the only Page in app/Filament/Pages without that prefix).
 *
 * Gate: canAccessSecurityLogs() — this method ALREADY existed on
 * PlatformStaffAccessPolicyService before this checkpoint (rule 7/8 in
 * that class's own docblock: "Security auditors can see security
 * logs"); reused here as-is, not duplicated, exactly as the mission
 * brief anticipated it might.
 *
 * Surfaces four things, per the mission brief:
 *  1. security_events recent activity — the page's own table()
 *     (PlatformSecurityDashboardService::recentSecurityEvents(), a
 *     cached, per-firm-merged, redacted read — see that service's own
 *     docblock).
 *  2. A summary of PlatformAdmins without confirmed MFA — read-only
 *     reporting only; does not require the MFA system itself to exist
 *     yet (PlatformAdmin::two_factor_confirmed_at already exists as an
 *     inert, cast column).
 *  3. Recent role changes (platform_roles grants/revocations).
 *  4. Integration-domain security signals — per the brief's own
 *     "reuse, don't duplicate" instruction, this is a LINK to
 *     PlatformIntegrationOverviewPage, never a re-query of the same
 *     summary data that page already owns.
 *
 * Scalar-property-only, matching every sibling Platform*Page in this
 * directory: no public Model-typed property, every section re-resolves
 * fresh on each render.
 */
class PlatformSecurityDashboardPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament-panels::pages.page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldExclamation;

    protected static ?string $navigationLabel = 'Security Dashboard';

    protected static string|\UnitEnum|null $navigationGroup = 'Security';

    protected static ?string $title = 'Security Dashboard';

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

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            $this->securityMetricsSection(),
            $this->mfaGapSection(),
            $this->privilegedActivitySection(),
            $this->recentRoleChangesSection(),
            $this->integrationOversightLinkSection(),
            $this->incidentLinkSection(),
            Section::make('Recent Security Activity')
                ->description('Most recent security_events rows across every firm (redacted — event metadata is never rendered here).')
                ->schema([EmbeddedTable::make()]),
        ]);
    }

    /**
     * CORE SuperAdmin mission, section 37: REAL measured numbers only —
     * no fabricated "0 Critical / 0 High" cards, since SecurityEvent
     * carries no severity column at all (confirmed by direct source
     * read of that model/migration). Rather than inventing a severity
     * taxonomy that doesn't exist, this section shows the metrics that
     * ARE genuinely measurable today, each honestly labeled, plus one
     * explicit note that severity itself is not yet classified.
     */
    private function securityMetricsSection(): Section
    {
        return Section::make('Security Metrics')
            ->columns(3)
            ->schema([
                Text::make(function (): string {
                    $count = app(PlatformSecurityDashboardService::class)->platformAdminFailedLoginCount(24);

                    return "Platform admin failed logins (last 24h): {$count}";
                })->color(fn () => app(PlatformSecurityDashboardService::class)->platformAdminFailedLoginCount(24) > 0 ? 'warning' : 'success'),
                Text::make(function (): string {
                    $count = app(PlatformSecurityDashboardService::class)->adminsWithoutConfirmedMfa()->count();

                    return "Platform admins without confirmed MFA: {$count}";
                })->color(fn () => app(PlatformSecurityDashboardService::class)->adminsWithoutConfirmedMfa()->isEmpty() ? 'success' : 'warning'),
                Text::make('Severity classification: Not classified — security_events carries no severity taxonomy today. Every event shown below is real; none is ranked by severity.')
                    ->color('gray'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (): Collection {
                $admin = Auth::guard('platform_admin')->user();

                if (! $admin instanceof PlatformAdmin) {
                    return collect();
                }

                try {
                    return app(PlatformSecurityDashboardService::class)->recentSecurityEvents($admin);
                } catch (RuntimeException $e) {
                    return collect();
                }
            })
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime()->sortable(),
                TextColumn::make('firm_name')->label('Firm'),
                TextColumn::make('category')->badge(),
                TextColumn::make('event_type')->label('Event'),
                TextColumn::make('actor_type')->label('Actor type'),
                TextColumn::make('actor_id')->label('Actor ID'),
            ])
            ->emptyStateHeading('No recent security activity')
            ->defaultSort('created_at', 'desc')
            ->paginated([10, 25, 50]);
    }

    /**
     * CORE SuperAdmin mission, section 38: the description here used to
     * claim "the MFA enrollment/challenge system itself is a separate,
     * not-yet-built effort" — false by the time this checkpoint ran
     * (confirmed by direct source read): AdminPanelProvider requires
     * TOTP+WebAuthn via EnsurePlatformAdminMfaIsEnrolledAndVerified on
     * every request, and PlatformAdministratorResource's own MFA
     * status column, ResetPlatformAdminMfaAction, and
     * RevokeDirectoryAttorneyVerificationAction-style audited actions
     * all already exist and are exercised elsewhere in this panel.
     * Enrollment (has the admin ever confirmed a TOTP/WebAuthn factor)
     * and enforcement (is the panel actually requiring it before
     * granting access) are now stated as the two DISTINCT facts they
     * are, never conflated.
     */
    private function mfaGapSection(): Section
    {
        return Section::make('PlatformAdmins Without Confirmed MFA')
            ->description('MFA enforcement is active platform-wide — every PlatformAdmin is forced through TOTP/WebAuthn setup on their next request if not yet enrolled (EnsurePlatformAdminMfaIsEnrolledAndVerified). This lists who currently lacks a confirmed enrollment — a transient in-progress state, not a policy gap.')
            ->schema([
                UnorderedList::make(function (): array {
                    $admin = Auth::guard('platform_admin')->user();

                    if (! $admin instanceof PlatformAdmin) {
                        return ['You are not signed in as a platform admin.'];
                    }

                    $rows = app(PlatformSecurityDashboardService::class)->adminsWithoutConfirmedMfa();

                    if ($rows->isEmpty()) {
                        return ['Every PlatformAdmin has a confirmed MFA enrollment.'];
                    }

                    return $rows->map(fn (PlatformAdmin $a): string => sprintf(
                        '%s <%s>%s',
                        $a->name,
                        $a->email,
                        $a->is_active ? '' : ' (inactive)',
                    ))->all();
                }),
            ])
            ->collapsible();
    }

    private function recentRoleChangesSection(): Section
    {
        return Section::make('Recent Role Changes')
            ->schema([
                UnorderedList::make(function (): array {
                    $rows = app(PlatformSecurityDashboardService::class)->recentRoleChanges();

                    if ($rows->isEmpty()) {
                        return ['No role grants or revocations recorded yet.'];
                    }

                    return $rows->map(function ($role): string {
                        $adminName = $role->platformAdmin?->name ?? "admin #{$role->platform_admin_id}";
                        $grantedByName = $role->grantedBy?->name;

                        if ($role->revoked_at !== null) {
                            return sprintf('%s — %s revoked at %s', $adminName, $role->role_code->value, $role->revoked_at->toDayDateTimeString());
                        }

                        return sprintf(
                            '%s — %s granted at %s%s',
                            $adminName,
                            $role->role_code->value,
                            $role->granted_at?->toDayDateTimeString() ?? '—',
                            $grantedByName !== null ? " (by {$grantedByName})" : '',
                        );
                    })->all();
                }),
            ])
            ->collapsible();
    }

    private function integrationOversightLinkSection(): Section
    {
        return Section::make('Integration Security Signals')
            ->description('Integration-domain security signals (health, conflicts, dead-lettered events) are already surfaced by the Integration Oversight page — reused via link here, not re-queried.')
            ->schema([
                Text::make(fn (): string => 'See: '.PlatformIntegrationOverviewPage::getUrl()),
            ])
            ->collapsible();
    }

    /**
     * CORE SuperAdmin mission, section 40: surfaces the highest-
     * privilege PlatformAdmin-management actions (role grants/
     * revocations, activation/deactivation, session revocation, MFA
     * reset) via PlatformSecurityDashboardService::
     * recentPrivilegedPlatformActivity() — see that method's own
     * docblock for exactly which event types and why Firm/FirmUser-
     * scoped privileged events are deliberately NOT duplicated here
     * (they are already visible in the Recent Security Activity table
     * below).
     */
    private function privilegedActivitySection(): Section
    {
        return Section::make('Privileged Platform Activity')
            ->description('Role grants/revocations, administrator activation/deactivation, session revocation, and MFA resets — the actions this panel treats as highest-privilege.')
            ->schema([
                UnorderedList::make(function (): array {
                    $rows = app(PlatformSecurityDashboardService::class)->recentPrivilegedPlatformActivity();

                    if ($rows->isEmpty()) {
                        return ['No privileged platform-administration activity recorded yet.'];
                    }

                    return $rows->map(function (array $row): string {
                        $description = str_replace('_', ' ', $row['event_type']);
                        $target = $row['target_platform_admin_id'] !== null ? " (target admin #{$row['target_platform_admin_id']})" : '';
                        $role = $row['role_code'] !== null ? " [{$row['role_code']}]" : '';
                        $when = $row['created_at']?->toDayDateTimeString() ?? '—';

                        return "{$description}{$role}{$target} — actor #{$row['actor_id']} — {$when}";
                    })->all();
                }),
            ])
            ->collapsible();
    }

    private function incidentLinkSection(): Section
    {
        return Section::make('Incident Console')
            ->description('The canonical Incident subsystem (severity, status, root cause) is a separate console, not re-implemented here.')
            ->schema([
                Text::make(fn (): string => 'See: '.PlatformIncidentResource::getUrl()),
            ])
            ->collapsible()
            ->collapsed();
    }
}
