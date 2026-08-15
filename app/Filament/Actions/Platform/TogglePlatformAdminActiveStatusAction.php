<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Filament\Support\StepUp\StepUpAuthentication;
use App\Models\PlatformAdmin;
use App\Services\PlatformAdminAuditEventRecorder;
use App\Services\PlatformRoleService;
use App\Services\PlatformStaffAccessPolicyService;
use App\Services\Security\SessionRevocationService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * TogglePlatformAdminActiveStatusAction — Platform Administrators
 * resource. A single Action whose label/icon/color/confirmation text
 * flip between "Activate" and "Deactivate" based on the record's
 * CURRENT is_active state at render time — the actual toggle logic is
 * identical either direction (flip the boolean), so one class avoids
 * two near-duplicate Action classes that would need to stay in sync.
 *
 * Follows RevokeSupportAccessSessionAction's TOCTOU-safe shape: the
 * acting admin and the target record are both re-resolved fresh from
 * the database inside the action closure, never trusting whatever was
 * true at page-load time.
 *
 * Last-SuperAdmin protection: only the DEACTIVATE direction calls
 * PlatformRoleService::wouldLeaveNoActiveSuperAdmin() — activating an
 * admin can never reduce the active-SuperAdmin count, so the guard is
 * skipped entirely on that path rather than evaluated and trivially
 * passing.
 *
 * Mission 1B (Extreme Security Hardening), sections 11 & 52: the
 * DEACTIVATE direction also calls SessionRevocationService to delete
 * every session row belonging to the target admin immediately, rather
 * than relying solely on Filament's own per-request canAccessPanel()
 * re-check to eventually deny a request that never comes (a long-
 * lived open connection wouldn't otherwise be forced to make one). The
 * modal copy below reflects this — "on its next request" was true
 * before this mission and is now additionally backstopped by immediate
 * revocation.
 *
 * CORE SuperAdmin mission: both directions now require a fresh
 * step-up verification (StepUpAuthentication::protect()) — activating
 * a dormant privileged account is also a meaningful security decision,
 * not only deactivation, so the guard applies to the whole toggle
 * rather than being conditioned on direction.
 */
class TogglePlatformAdminActiveStatusAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'togglePlatformAdminActiveStatus';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(fn (PlatformAdmin $record): string => $record->is_active ? 'Deactivate' : 'Activate');
        $this->icon(fn (PlatformAdmin $record): Heroicon => $record->is_active ? Heroicon::OutlinedNoSymbol : Heroicon::OutlinedCheckCircle);
        $this->color(fn (PlatformAdmin $record): string => $record->is_active ? 'danger' : 'success');

        StepUpAuthentication::protect($this, 'platform_admin');
        $this->modalHeading(fn (PlatformAdmin $record): string => $record->is_active ? 'Deactivate platform administrator' : 'Activate platform administrator');
        $this->modalDescription(fn (PlatformAdmin $record): string => $record->is_active
            ? 'This immediately blocks this administrator from the panel and revokes every one of their active sessions. This can be reversed by activating them again.'
            : 'This restores this administrator\'s access to the panel.');

        $this->action(function (PlatformAdmin $record, PlatformStaffAccessPolicyService $accessPolicy, PlatformRoleService $roleService, PlatformAdminAuditEventRecorder $auditRecorder, SessionRevocationService $sessionRevocation): void {
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

            $isDeactivating = $target->is_active;

            if ($isDeactivating && $roleService->wouldLeaveNoActiveSuperAdmin($target)) {
                Notification::make()
                    ->title('Cannot deactivate this administrator')
                    ->body('This would leave zero active SuperAdmins. Grant SuperAdmin to another active administrator first.')
                    ->warning()
                    ->send();

                return;
            }

            DB::transaction(function () use ($target, $actor, $isDeactivating, $auditRecorder): void {
                $target->forceFill(['is_active' => ! $isDeactivating])->save();

                $auditRecorder->recordPlatformEvent(
                    $actor,
                    $isDeactivating ? 'platform_admin_deactivated' : 'platform_admin_activated',
                    'platform_admin_management',
                    [
                        'target_platform_admin_id' => $target->id,
                        'target_platform_admin_uuid' => $target->uuid,
                    ],
                );
            });

            if ($isDeactivating) {
                $sessionRevocation->revokeAllSessionsFor($target, 'platform_admin');
            }

            Notification::make()
                ->title($isDeactivating ? 'Platform administrator deactivated' : 'Platform administrator activated')
                ->success()
                ->send();
        });
    }
}
