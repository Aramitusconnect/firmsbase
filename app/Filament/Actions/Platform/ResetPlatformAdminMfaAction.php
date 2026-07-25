<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Models\PlatformAdmin;
use App\Services\PlatformAdminMfaResetService;
use App\Services\PlatformStaffAccessPolicyService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * ResetPlatformAdminMfaAction — FirmsVault Admin Control Center MFA
 * design proposal §8/§9. Registered on PlatformAdministratorResource's
 * ViewPlatformAdministrator page (per-record action). Also doubles as
 * "require re-enrollment" — the design proposal's own §6 finding is
 * that these are the SAME underlying mechanism (a reset simply returns
 * the target's account to the "never enrolled" branch, which
 * EnsurePlatformAdminMfaIsEnrolledAndVerified's step 3 then routes
 * through the normal forced-setup flow on their next request) — there
 * is deliberately no second, parallel "force re-enrollment" action.
 *
 * Follows RevokeSupportAccessSessionAction's exact shape: actor
 * re-resolved fresh from the guard at action-execution time (never
 * trusts page-load-time authorization alone — TOCTOU discipline), a
 * confirmation modal, and a danger notification on failure rather than
 * an uncaught exception.
 *
 * Gate: PlatformStaffAccessPolicyService::canManagePlatformAdministrators()
 * (SuperAdmin-only) — matching the design proposal's "requires another
 * active SuperAdmin actor" requirement.
 *
 * Self-target is deliberately REJECTED, not merely permitted. This
 * action exists specifically as the "acting SuperAdmin resets a
 * DIFFERENT admin's MFA on their behalf" path and, unlike Filament's
 * own DisableAppAuthenticationAction, requires no proof of current TOTP
 * or recovery-code possession from the target — if a SuperAdmin could
 * target themselves through this action, an already-authenticated
 * session (e.g. one obtained through session hijacking, not device
 * theft) could strip its own MFA with no second factor and no second
 * approver at all, defeating the entire point of gating this action to
 * SuperAdmin. A SuperAdmin who has lost their own device but still has
 * their password should ask ANOTHER active SuperAdmin to run this
 * action against their account; a SOLE SuperAdmin who has lost both
 * their device and their recovery codes has no other SuperAdmin to ask
 * — that is exactly the scenario the
 * platform-admin:emergency-mfa-reset Artisan command exists for
 * (a deliberately out-of-band, non-production-by-default path, not
 * reachable through the panel UI at all).
 *
 * Last-SuperAdmin protection (PlatformRoleService::
 * wouldLeaveNoActiveSuperAdmin()) deliberately does NOT gate this
 * action — an MFA reset never revokes a role or deactivates an
 * account, so per the design proposal's explicit §6 finding it must
 * remain unconditionally available even when the target is the sole
 * active SuperAdmin.
 */
class ResetPlatformAdminMfaAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'resetPlatformAdminMfa';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Reset MFA');
        $this->icon(Heroicon::OutlinedShieldExclamation);
        $this->color('danger');

        $this->schema([
            Textarea::make('reason')
                ->label('Reason')
                ->required()
                ->maxLength(500)
                ->helperText('Recorded in the audit trail. Explain why this admin\'s MFA is being reset (e.g. lost device, lost recovery codes).'),
        ]);

        $this->requiresConfirmation();
        $this->modalHeading('Reset platform administrator MFA');
        $this->modalDescription('This immediately clears the target admin\'s authenticator app enrollment and recovery codes, and forces their current session (if any) to log out and re-enroll on their next request. This cannot be undone.');

        $this->action(function (array $data, PlatformAdmin $record, PlatformStaffAccessPolicyService $accessPolicy, PlatformAdminMfaResetService $resetService): void {
            $actor = Auth::guard('platform_admin')->user();

            if (! $actor instanceof PlatformAdmin) {
                Notification::make()->title('You are not signed in as a platform admin.')->danger()->send();

                return;
            }

            if ($actor->is($record)) {
                Notification::make()
                    ->title('You cannot reset your own MFA through this action.')
                    ->body('Ask another active SuperAdmin to reset it for you, or use the emergency recovery command if you are the sole SuperAdmin.')
                    ->danger()
                    ->send();

                return;
            }

            if (! $accessPolicy->canManagePlatformAdministrators($actor)->allowed) {
                Notification::make()->title('You are not authorized to reset another platform administrator\'s MFA.')->danger()->send();

                return;
            }

            $target = PlatformAdmin::query()->find($record->getKey());

            if ($target === null) {
                Notification::make()->title('That platform administrator could not be found.')->danger()->send();

                return;
            }

            try {
                $resetService->reset($actor, $target, (string) $data['reason']);
            } catch (RuntimeException $e) {
                Notification::make()->title('Could not reset MFA for this administrator')->body($e->getMessage())->danger()->send();

                return;
            }

            Notification::make()->title('MFA reset — the administrator will be required to re-enroll')->success()->send();
        });
    }
}
