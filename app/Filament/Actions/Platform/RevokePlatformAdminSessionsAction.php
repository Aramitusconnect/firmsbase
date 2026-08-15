<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Filament\Support\StepUp\StepUpAuthentication;
use App\Models\PlatformAdmin;
use App\Services\PlatformAdminAuditEventRecorder;
use App\Services\PlatformStaffAccessPolicyService;
use App\Services\Security\SessionRevocationService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * RevokePlatformAdminSessionsAction — CORE SuperAdmin mission, section
 * 25/29. A standalone "Revoke Sessions" action, independent of
 * activation/MFA state — the gap ViewPlatformAdministrator's own
 * (now-corrected) docblock previously described as unbuildable no
 * longer applies: SessionRevocationService::revokeAllSessionsFor()
 * already reliably targets the `platform_admin` guard (it decodes each
 * session row's own guard-scoped Laravel auth key rather than trusting
 * a bare `user_id` column) — proven by TogglePlatformAdminActiveStatusAction,
 * which has called it on every deactivation since Mission 1B. This
 * action wires the SAME existing service to an explicit, standalone
 * trigger for the "password alone may be compromised, MFA is intact"
 * scenario that action's own docblock disclosed as its one remaining
 * gap — no new session-revocation logic, only a new entry point onto
 * the existing one.
 */
class RevokePlatformAdminSessionsAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'revokePlatformAdminSessions';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Revoke Sessions');
        $this->icon(Heroicon::OutlinedArrowRightOnRectangle);
        $this->color('danger');

        StepUpAuthentication::protect($this, 'platform_admin');
        $this->modalHeading('Revoke platform administrator sessions');
        $this->modalDescription('This immediately signs this administrator out of every active session. Their password and MFA enrollment are unaffected — they can sign back in normally.');

        $this->action(function (PlatformAdmin $record, PlatformStaffAccessPolicyService $accessPolicy, PlatformAdminAuditEventRecorder $auditRecorder, SessionRevocationService $sessionRevocation): void {
            $actor = Auth::guard('platform_admin')->user();

            if (! $actor instanceof PlatformAdmin) {
                Notification::make()->title('You are not signed in as a platform admin.')->danger()->send();

                return;
            }

            if (! $accessPolicy->canManagePlatformAdministrators($actor)->allowed) {
                Notification::make()->title('You are not authorized to manage platform administrators.')->danger()->send();

                return;
            }

            $target = PlatformAdmin::query()->find($record->getKey());

            if ($target === null) {
                Notification::make()->title('That platform administrator could not be found.')->danger()->send();

                return;
            }

            $revokedCount = $sessionRevocation->revokeAllSessionsFor($target, 'platform_admin');

            $auditRecorder->recordPlatformEvent(
                $actor,
                'platform_admin_sessions_revoked',
                'platform_admin_management',
                [
                    'target_platform_admin_id' => $target->id,
                    'target_platform_admin_uuid' => $target->uuid,
                    'revoked_session_count' => $revokedCount,
                ],
            );

            Notification::make()
                ->title($revokedCount > 0 ? "Sessions revoked ({$revokedCount})" : 'No active sessions found to revoke')
                ->success()
                ->send();
        });
    }
}
