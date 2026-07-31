<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Enums\FirmActivationStatus;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Services\FirmProvisioningService;
use App\Services\PlatformStaffAccessPolicyService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * ResendFirmOwnerInvitationAction — the sole recovery path for a firm
 * whose owner invitation failed to deliver. Never recreates the Firm,
 * User, or FirmUser owner membership — FirmProvisioningService::resendInvitation()
 * only re-dispatches the password-setup notification.
 *
 * Visible only for a firm still in Onboarding — an Activated firm's
 * owner has, by ActivationChecklistService's own gate conditions,
 * already completed setup, so this action has nothing to do there.
 */
class ResendFirmOwnerInvitationAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'resendFirmOwnerInvitation';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Resend owner invitation');
        $this->icon(Heroicon::OutlinedEnvelope);
        $this->color('gray');
        $this->requiresConfirmation();

        $this->visible(fn (Firm $record): bool => $record->activation_status === FirmActivationStatus::Onboarding);

        $this->action(function (Firm $record): void {
            $admin = Auth::guard('platform_admin')->user();

            if (! $admin instanceof PlatformAdmin) {
                Notification::make()->title('You are not signed in as a platform admin.')->danger()->send();

                return;
            }

            $accessPolicy = app(PlatformStaffAccessPolicyService::class);
            $manageDecision = $accessPolicy->canManageFirms($admin);

            if (! $manageDecision->allowed) {
                Notification::make()->title('Not permitted')->body($manageDecision->reason)->danger()->send();

                return;
            }

            $mutateDecision = $accessPolicy->canMutate($admin);

            if (! $mutateDecision->allowed) {
                Notification::make()->title('Not permitted')->body($mutateDecision->reason)->danger()->send();

                return;
            }

            try {
                $succeeded = app(FirmProvisioningService::class)->resendInvitation($record->fresh(), $admin);
            } catch (Throwable $e) {
                Notification::make()->title('Could not resend invitation')->body($e->getMessage())->danger()->send();

                return;
            }

            if ($succeeded) {
                Notification::make()->title('Invitation resent')->success()->send();
            } else {
                Notification::make()->title('Invitation could not be sent')->body('The delivery attempt failed again. The firm remains in Onboarding.')->danger()->send();
            }
        });
    }
}
