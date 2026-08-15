<?php

declare(strict_types=1);

namespace App\Filament\Resources\FirmUserResource\Pages;

use App\Enums\FirmUserStatus;
use App\Exceptions\LastFirmOwnerRemovalException;
use App\Filament\Resources\FirmUserResource;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\PlatformAdmin;
use App\Services\FirmUserInvitationService;
use App\Services\PlatformAdminAuditEventRecorder;
use App\Services\PlatformFirmUserDirectoryService;
use App\Services\PlatformStaffAccessPolicyService;
use App\Services\Security\SessionRevocationService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * ViewFirmUser — a custom Resource page, NOT the standard Filament
 * ViewRecord (`{record}` route-model-binding). See FirmUserResource's
 * own docblock for why: a FirmUser row cannot be resolved by its own
 * uuid alone under firm_users' FORCE RLS without the correct firm's
 * context already active, so the route carries both `firmUuid` and
 * `firmUserUuid` — mirroring App\Filament\Pages\
 * PlatformFirmIntegrationDetailPage's established
 * `{firmUuid}/{connectionUuid}` composite-route shape exactly.
 *
 * Scalar-property-only, TOCTOU-consistent with that same precedent: the
 * only public properties are the two route-parameter strings; the
 * actual FirmUser is re-resolved fresh via
 * PlatformFirmUserDirectoryService::findByUuid() inside content(), never
 * cached on $this between renders beyond what mount() needs for its own
 * one-time 403/404 check.
 */
class ViewFirmUser extends Page
{
    protected static string $resource = FirmUserResource::class;

    protected string $view = 'filament-panels::pages.page';

    protected static ?string $title = 'Firm User';

    public string $firmUuid = '';

    public string $firmUserUuid = '';

    /**
     * CORE SuperAdmin mission, section 23: Suspend/Reactivate and
     * Revoke Sessions. Defined here as inline Action instances (not
     * standalone classes injected with a typed $record, the convention
     * every OTHER Platform*Action in this app uses) because this is a
     * custom Page, not a ViewRecord — there is no `$this->getRecord()`
     * for Filament to auto-inject; each closure instead re-resolves the
     * FirmUser fresh via PlatformFirmUserDirectoryService::findByUuid()
     * using this page's own $firmUuid/$firmUserUuid route-parameter
     * properties, matching the TOCTOU discipline already established by
     * every other mutating action in this panel.
     *
     * MFA recovery/re-enrollment for a FirmUser is a genuine, disclosed
     * gap, not silently omitted: no reset/recovery service exists for
     * the `web`-guard `User` model anywhere in this codebase today
     * (FirmUser2faPolicyService is compliance/policy reporting only —
     * confirmed by direct source read; PlatformAdminMfaResetService is
     * PlatformAdmin-only). Building one is a genuinely separate,
     * schema/service-design decision (out of this narrow UI mission's
     * safe-reuse scope) — see the mission's final report for the
     * BLOCKED_CAPABILITY writeup.
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->toggleStatusAction(),
            $this->revokeSessionsAction(),
        ];
    }

    private function resolvedFirmUser(): ?FirmUser
    {
        $admin = Auth::guard('platform_admin')->user();

        if (! $admin instanceof PlatformAdmin) {
            return null;
        }

        $firm = Firm::findByUuid($this->firmUuid);

        if ($firm === null) {
            return null;
        }

        try {
            return app(PlatformFirmUserDirectoryService::class)->findByUuid($admin, $firm, $this->firmUserUuid);
        } catch (RuntimeException) {
            return null;
        }
    }

    private function toggleStatusAction(): Action
    {
        return Action::make('toggleFirmUserStatus')
            ->label(fn (): string => $this->resolvedFirmUser()?->status === FirmUserStatus::Suspended ? 'Reactivate' : 'Suspend')
            ->icon(fn (): Heroicon => $this->resolvedFirmUser()?->status === FirmUserStatus::Suspended ? Heroicon::OutlinedCheckCircle : Heroicon::OutlinedNoSymbol)
            ->color(fn (): string => $this->resolvedFirmUser()?->status === FirmUserStatus::Suspended ? 'success' : 'danger')
            ->visible(fn (): bool => in_array($this->resolvedFirmUser()?->status, [FirmUserStatus::Active, FirmUserStatus::Suspended], true))
            ->requiresConfirmation()
            ->modalDescription(fn (): string => $this->resolvedFirmUser()?->status === FirmUserStatus::Suspended
                ? 'This restores this person\'s access to their firm.'
                : 'This immediately blocks this person from their firm and revokes every one of their active sessions. This can be reversed by reactivating them again.')
            ->action(function (PlatformStaffAccessPolicyService $accessPolicy, FirmUserInvitationService $invitationService, PlatformAdminAuditEventRecorder $auditRecorder, SessionRevocationService $sessionRevocation): void {
                $actor = Auth::guard('platform_admin')->user();

                if (! $actor instanceof PlatformAdmin) {
                    Notification::make()->title('You are not signed in as a platform admin.')->danger()->send();

                    return;
                }

                if (! $accessPolicy->canManageFirms($actor)->allowed) {
                    Notification::make()->title('You are not authorized to manage firm users.')->danger()->send();

                    return;
                }

                $firmUser = $this->resolvedFirmUser();
                $firm = Firm::findByUuid($this->firmUuid);

                if ($firmUser === null || $firm === null) {
                    Notification::make()->title('This firm user could not be found.')->danger()->send();

                    return;
                }

                $isSuspending = $firmUser->status !== FirmUserStatus::Suspended;

                try {
                    $isSuspending ? $invitationService->suspend($firmUser) : $invitationService->reactivate($firmUser);
                } catch (LastFirmOwnerRemovalException $e) {
                    Notification::make()->title('Cannot suspend this person')->body($e->getMessage())->warning()->send();

                    return;
                }

                $auditRecorder->record($firm, $actor, $isSuspending ? 'firm_user_suspended_by_platform_admin' : 'firm_user_reactivated_by_platform_admin', 'platform_administration', [
                    'firm_user_id' => $firmUser->id,
                    'firm_user_uuid' => $firmUser->uuid,
                ]);

                if ($isSuspending && $firmUser->user !== null) {
                    $sessionRevocation->revokeAllSessionsFor($firmUser->user, 'web');
                }

                Notification::make()->title($isSuspending ? 'Firm user suspended' : 'Firm user reactivated')->success()->send();
            });
    }

    private function revokeSessionsAction(): Action
    {
        return Action::make('revokeFirmUserSessions')
            ->label('Revoke Sessions')
            ->icon(Heroicon::OutlinedArrowRightOnRectangle)
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription('This immediately signs this person out of every active session. Their password and role are unaffected — they can sign back in normally.')
            ->action(function (PlatformStaffAccessPolicyService $accessPolicy, PlatformAdminAuditEventRecorder $auditRecorder, SessionRevocationService $sessionRevocation): void {
                $actor = Auth::guard('platform_admin')->user();

                if (! $actor instanceof PlatformAdmin) {
                    Notification::make()->title('You are not signed in as a platform admin.')->danger()->send();

                    return;
                }

                if (! $accessPolicy->canManageFirms($actor)->allowed) {
                    Notification::make()->title('You are not authorized to manage firm users.')->danger()->send();

                    return;
                }

                $firmUser = $this->resolvedFirmUser();
                $firm = Firm::findByUuid($this->firmUuid);

                if ($firmUser === null || $firm === null || $firmUser->user === null) {
                    Notification::make()->title('This firm user could not be found.')->danger()->send();

                    return;
                }

                $revokedCount = $sessionRevocation->revokeAllSessionsFor($firmUser->user, 'web');

                $auditRecorder->record($firm, $actor, 'firm_user_sessions_revoked_by_platform_admin', 'platform_administration', [
                    'firm_user_id' => $firmUser->id,
                    'firm_user_uuid' => $firmUser->uuid,
                    'revoked_session_count' => $revokedCount,
                ]);

                Notification::make()
                    ->title($revokedCount > 0 ? "Sessions revoked ({$revokedCount})" : 'No active sessions found to revoke')
                    ->success()
                    ->send();
            });
    }

    public function mount(string $firmUuid, string $firmUserUuid): void
    {
        $this->firmUuid = $firmUuid;
        $this->firmUserUuid = $firmUserUuid;

        $admin = Auth::guard('platform_admin')->user();

        if (! $admin instanceof PlatformAdmin) {
            abort(403);
        }

        $firm = Firm::findByUuid($this->firmUuid);

        try {
            $firmUser = app(PlatformFirmUserDirectoryService::class)->findByUuid($admin, $firm, $this->firmUserUuid);
        } catch (RuntimeException $e) {
            throw new HttpException(403, $e->getMessage());
        }

        if ($firmUser === null) {
            abort(404);
        }
    }

    public function content(Schema $schema): Schema
    {
        $admin = Auth::guard('platform_admin')->user();

        if (! $admin instanceof PlatformAdmin) {
            return $schema->components([
                Text::make('You are not signed in as a platform admin.')->color('danger'),
            ]);
        }

        $firm = Firm::findByUuid($this->firmUuid);

        try {
            $firmUser = app(PlatformFirmUserDirectoryService::class)->findByUuid($admin, $firm, $this->firmUserUuid);
        } catch (RuntimeException $e) {
            return $schema->components([
                Text::make($e->getMessage())->color('danger'),
            ]);
        }

        if ($firmUser === null) {
            return $schema->components([
                Text::make('This firm user could not be found.')->color('danger'),
            ]);
        }

        return $schema->components([
            Section::make('Firm User')
                ->columns(2)
                ->schema([
                    Text::make("Name: {$this->displayName($firmUser)}"),
                    Text::make('Email: '.($firmUser->user?->email ?? '—')),
                    Text::make('Firm: '.$firm->name),
                    Text::make('Role: '.($firmUser->role !== null ? Str::headline($firmUser->role->value) : '—')),
                    Text::make('Status: '.($firmUser->status !== null ? Str::headline($firmUser->status->value) : '—')),
                    Text::make('Seat class: '.Str::headline($firmUser->effectiveSeatClass()->value)),
                    Text::make('Primary: '.($firmUser->is_primary ? 'Yes' : 'No')),
                    Text::make('Invitation accepted: '.($firmUser->invitation_accepted_at?->toDayDateTimeString() ?? '—')),
                    Text::make('Member since: '.$firmUser->created_at?->toDayDateTimeString()),
                ]),
        ]);
    }

    private function displayName(FirmUser $firmUser): string
    {
        return $firmUser->user?->name ?? '—';
    }
}
