<?php

declare(strict_types=1);

namespace App\Filament\Resources\FirmResource\Pages;

use App\Filament\Actions\Platform\ResendFirmOwnerInvitationAction;
use App\Filament\Resources\FirmResource;
use App\Filament\Resources\PlanResource;
use App\Integrations\Models\FirmIntegration;
use App\Models\Firm;
use App\Models\FirmLicense;
use App\Models\FirmUser;
use App\Models\SecurityEvent;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Services\FirmSeatCapacityService;
use App\Services\TenantContextService;
use Filament\Actions\EditAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

/**
 * ViewFirm — read-only detail view over Firm's real fillable columns
 * only. No generic Edit page exists (see FirmResource's own docblock).
 *
 * Platform Firm Provisioning workflow addition: ResendFirmOwnerInvitationAction
 * is the one header action registered here — a narrow, explicit
 * state-machine action (re-dispatch the owner's setup email), never
 * arbitrary field editing. Its own ->visible()/authorization-inside-the-
 * closure discipline is documented on that class.
 *
 * "Commercial / License" section (Admin audit follow-up): read-only
 * only, deliberately. `FirmSeatCapacityService` (Firm Feature Manifest
 * §12's flat per-firm seat model) is the ONLY source of truth consulted
 * here for purchased/used/remaining seats — never a duplicated ad-hoc
 * query. There is no reusable domain SERVICE for adjusting
 * `purchased_seats` today — the only existing write path is
 * `firms:report-missing-purchased-seats --apply`'s own inline console-
 * command logic, not a Service class this page could call the way
 * every other mutation in this panel routes through one. Adding a Filament
 * Action here would mean either reimplementing that write logic a second
 * time (drifting from the command's own idempotency/force/conflict
 * rules) or bypassing it — both worse than staying read-only until a
 * proper `FirmLicenseService`-style seat-adjustment method exists. See
 * the accompanying report for this gap.
 *
 * Plan/License-status entries deliberately do NOT use Filament's
 * automatic `license.plan.name` / `license.license_status` dot-path
 * relationship resolution: that resolution accesses the lazy `license`
 * relation with no tenant context active (the Admin panel has no
 * ambient per-request tenant-context middleware, unlike the Firm
 * panel), and `firm_licenses` is FORCE ROW LEVEL SECURITY protected --
 * an unwrapped read silently returns nothing. `resolvedLicense()` below
 * wraps the read in `TenantContextService::runWithFirmContext()`,
 * mirroring how `FirmSeatCapacityService` already self-wraps every one
 * of its own reads for the exact same reason.
 *
 * Mission 7 ("Super Admin Operational Completion") additions — Users,
 * Integrations, and Recent Audit Activity sections. Each is its own,
 * independent `runWithFirmContext($record, ...)` call (never a shared
 * cross-firm/directory-service loop — this page already has exactly one
 * specific firm from the route, so the O(firm count) per-firm-loop
 * pattern `PlatformFirmUserDirectoryService`/`PlatformConnectionDirectoryService`
 * use for their own cross-firm LIST pages would be the wrong tool here):
 *  - Users: `firm_users` (FORCE RLS) queried directly, mirroring
 *    `FirmUserResource`'s own columns (name/email/role/status/seat
 *    class/primary/invitation accepted). "Last login" is a genuine
 *    signal, not fabricated: `AppServiceProvider`'s `Login` listener
 *    writes a firm-scoped `security_events` row
 *    (event_type=login_succeeded, actor_type=App\Models\User) for every
 *    successful firm-side login, the same underlying table
 *    `PlatformAdministratorResource::lastLoginAtByAdminId()` already
 *    relies on for the identical purpose one guard over — one extra
 *    batched (never per-row) query inside the same firm context.
 *  - Integrations: `firm_integrations` (FORCE RLS) queried directly,
 *    mirroring `ConnectionResource`'s provider/status/health columns.
 *  - Recent Audit Activity: `timeline_events` (FORCE RLS) — the same
 *    model/table `AuditLogResource` already surfaces cross-firm — scoped
 *    to this one firm, ordered most-recent-first, limited to 10 rows.
 */
class ViewFirm extends ViewRecord
{
    protected static string $resource = FirmResource::class;

    private ?FirmLicense $resolvedLicenseCache = null;

    private ?int $resolvedLicenseCacheFirmId = null;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            ResendFirmOwnerInvitationAction::make(),
        ];
    }

    private function resolvedLicense(Firm $record): ?FirmLicense
    {
        if ($this->resolvedLicenseCacheFirmId === $record->id) {
            return $this->resolvedLicenseCache;
        }

        $this->resolvedLicenseCacheFirmId = $record->id;

        return $this->resolvedLicenseCache = app(TenantContextService::class)->runWithFirmContext(
            $record,
            fn () => $record->license()->with('plan')->first(),
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function firmUsersRows(Firm $record): array
    {
        return app(TenantContextService::class)->runWithFirmContext($record, function (): array {
            $firmUsers = FirmUser::query()->with('user')->orderByDesc('created_at')->get();

            $userIds = $firmUsers->pluck('user_id')->filter()->unique()->values()->all();

            // ONE batched, bounded query — never per-row — mirroring
            // PlatformAdministratorResource::lastLoginAtByAdminId()'s
            // identical shape one guard over. See this class's own
            // docblock for why this is a genuine signal, not a
            // fabricated column.
            $lastLoginByUserId = $userIds === [] ? [] : SecurityEvent::query()
                ->where('actor_type', User::class)
                ->whereIn('actor_id', $userIds)
                ->where('event_type', 'login_succeeded')
                ->selectRaw('actor_id, MAX(created_at) as last_login_at')
                ->groupBy('actor_id')
                ->pluck('last_login_at', 'actor_id')
                ->all();

            return $firmUsers->map(fn (FirmUser $firmUser): array => [
                'name' => $firmUser->user?->name ?? '—',
                'email' => $firmUser->user?->email ?? '—',
                'role' => $firmUser->role !== null ? Str::headline($firmUser->role->value) : '—',
                'status' => $firmUser->status !== null ? Str::headline($firmUser->status->value) : '—',
                'last_login' => $firmUser->user_id !== null
                    ? ($lastLoginByUserId[$firmUser->user_id] ?? 'Never')
                    : 'Never',
            ])->all();
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function firmIntegrationsRows(Firm $record): array
    {
        return app(TenantContextService::class)->runWithFirmContext($record, fn (): array => FirmIntegration::query()
            ->with('integrationProvider')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (FirmIntegration $integration): array => [
                'provider' => $integration->integrationProvider?->display_name ?? '—',
                'status' => Str::headline($integration->status->value),
                'health' => $integration->last_health_status !== null ? Str::headline($integration->last_health_status->value) : '—',
                'last_health_check_at' => $integration->last_health_check_at?->toDateTimeString() ?? '—',
            ])
            ->all());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function recentAuditActivityRows(Firm $record): array
    {
        return app(TenantContextService::class)->runWithFirmContext($record, fn (): array => TimelineEvent::query()
            ->orderByDesc('occurred_at')
            ->limit(10)
            ->get()
            ->map(fn (TimelineEvent $event): array => [
                'event_type' => Str::headline($event->event_type),
                'subject_type' => $event->subject_type !== null ? class_basename($event->subject_type) : '—',
                'actor_type' => $event->actor_type !== null ? class_basename($event->actor_type) : '—',
                'occurred_at' => $event->occurred_at?->toDateTimeString() ?? '—',
            ])
            ->all());
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Firm')
                ->columns(2)
                ->schema([
                    TextEntry::make('name'),
                    TextEntry::make('legal_name')->placeholder('—'),
                    TextEntry::make('activation_status')
                        ->badge()
                        ->formatStateUsing(fn ($state): string => is_object($state) ? $state->value : (string) $state),
                    TextEntry::make('customer_type')
                        ->formatStateUsing(fn ($state): string => is_object($state) ? $state->value : (string) $state),
                    TextEntry::make('deployment_mode')
                        ->badge()
                        ->formatStateUsing(fn ($state): string => is_object($state) ? $state->value : (string) $state),
                    TextEntry::make('primary_country')->label('Country')->placeholder('—'),
                    TextEntry::make('primary_state')->label('State/Province')->placeholder('—'),
                    TextEntry::make('default_timezone')->label('Timezone')->placeholder('—'),
                    TextEntry::make('default_currency')->label('Currency')->placeholder('—'),
                    TextEntry::make('data_region')->placeholder('—'),
                    TextEntry::make('created_at')->dateTime(),
                ]),
            Section::make('Commercial / License')
                ->columns(3)
                ->schema([
                    TextEntry::make('plan_name')
                        ->label('Plan')
                        ->state(fn (Firm $record): ?string => $this->resolvedLicense($record)?->plan?->name)
                        ->placeholder('No plan assigned')
                        ->url(function (Firm $record): ?string {
                            $plan = $this->resolvedLicense($record)?->plan;

                            return $plan ? PlanResource::getUrl('view', ['record' => $plan]) : null;
                        }),
                    TextEntry::make('license_status')
                        ->label('License status')
                        ->badge()
                        ->state(fn (Firm $record) => $this->resolvedLicense($record)?->license_status)
                        ->placeholder('No license')
                        ->formatStateUsing(fn ($state): string => is_object($state) ? $state->value : (string) $state),
                    TextEntry::make('purchased_seats')
                        ->label('Purchased seats')
                        ->state(fn (Firm $record): ?int => app(FirmSeatCapacityService::class)->purchasedSeats($record))
                        ->placeholder('Not configured / Unset'),
                    TextEntry::make('used_seats')
                        ->label('Seats used')
                        ->state(fn (Firm $record): int => app(FirmSeatCapacityService::class)->usedSeats($record)),
                    TextEntry::make('remaining_seats')
                        ->label('Seats remaining')
                        ->state(fn (Firm $record): ?int => app(FirmSeatCapacityService::class)->remainingSeats($record))
                        ->placeholder('—'),
                ]),
            Section::make('Users')
                ->collapsible()
                ->schema([
                    RepeatableEntry::make('firmUsers')
                        ->hiddenLabel()
                        ->state(fn (Firm $record): array => $this->firmUsersRows($record))
                        ->schema([
                            TextEntry::make('name'),
                            TextEntry::make('email'),
                            TextEntry::make('role')->badge(),
                            TextEntry::make('status')->badge(),
                            TextEntry::make('last_login')->label('Last login'),
                        ])
                        ->columns(5),
                ])
                ->collapsed(),
            Section::make('Integrations')
                ->collapsible()
                ->schema([
                    RepeatableEntry::make('firmIntegrations')
                        ->hiddenLabel()
                        ->state(fn (Firm $record): array => $this->firmIntegrationsRows($record))
                        ->schema([
                            TextEntry::make('provider'),
                            TextEntry::make('status')->badge(),
                            TextEntry::make('health')->label('Health'),
                            TextEntry::make('last_health_check_at')->label('Last health check'),
                        ])
                        ->columns(4),
                ])
                ->collapsed(),
            Section::make('Recent Audit Activity')
                ->description('Most recent timeline_events rows for this firm (up to 10) — the same general business-activity trail AuditLogResource surfaces cross-firm.')
                ->collapsible()
                ->schema([
                    RepeatableEntry::make('recentAuditActivity')
                        ->hiddenLabel()
                        ->state(fn (Firm $record): array => $this->recentAuditActivityRows($record))
                        ->schema([
                            TextEntry::make('event_type')->label('Event type'),
                            TextEntry::make('subject_type')->label('Subject'),
                            TextEntry::make('actor_type')->label('Actor'),
                            TextEntry::make('occurred_at')->label('Occurred at'),
                        ])
                        ->columns(4),
                ])
                ->collapsed(),
        ]);
    }
}
