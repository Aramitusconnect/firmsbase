<?php

declare(strict_types=1);

namespace App\Filament\Pages;

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
            $this->mfaGapSection(),
            $this->recentRoleChangesSection(),
            $this->integrationOversightLinkSection(),
            Section::make('Recent Security Activity')
                ->description('Most recent security_events rows across every firm (redacted — event metadata is never rendered here).')
                ->schema([EmbeddedTable::make()]),
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

    private function mfaGapSection(): Section
    {
        return Section::make('PlatformAdmins Without Confirmed MFA')
            ->description('Read-only reporting — the MFA enrollment/challenge system itself is a separate, not-yet-built effort. This lists every PlatformAdmin whose two_factor_confirmed_at is still null.')
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
}
