<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Enums\SupportAccessSessionStatus;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Services\PlatformFirmIntegrationBoundedAccessService;
use App\Services\PlatformStaffAccessPolicyService;
use App\Services\PlatformSupportAccessDirectoryService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * RevokeApprovedSupportSessionAction — SupportSessionResource's row
 * action. Unlike RevokeSupportAccessSessionAction (Checkpoint 11's
 * single-firm header action with a Select dropdown of that one firm's
 * active sessions), this is a per-ROW action on the new cross-firm
 * Approved Support Sessions list — the row's own firm_uuid/id resolve
 * the exact session directly, no picker needed.
 *
 * Routed through the SAME, already-wired, already-TOCTOU-safe, dual-
 * audited chokepoint: PlatformFirmIntegrationBoundedAccessService::
 * revokeSupportAccessSession() — never reimplemented here. This class
 * adds one narrower UI-layer gate on top
 * (PlatformStaffAccessPolicyService::canManageSupportAccess(), see that
 * method's own docblock) before ever reaching the chokepoint, mirroring
 * RequeueDeadLetterQueueEventAction/VoidPlatformInvoiceAction's
 * established "check a narrower manage-gate at the UI layer, then let
 * the chokepoint's own broader gate + TOCTOU-safe fresh re-read do the
 * rest" shape.
 */
class RevokeApprovedSupportSessionAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'revokeApprovedSupportSession';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Revoke');
        $this->icon(Heroicon::OutlinedNoSymbol);
        $this->color('danger');

        $this->requiresConfirmation();
        $this->modalHeading('Revoke Approved Support Session');
        $this->modalDescription('This immediately ends the session for whichever platform admin is using it. This cannot be undone.');

        $this->visible(fn (array $record): bool => ($record['status'] ?? null) === SupportAccessSessionStatus::Active->value);

        $this->action(function (array $record, PlatformSupportAccessDirectoryService $directory, PlatformFirmIntegrationBoundedAccessService $boundedAccess, PlatformStaffAccessPolicyService $accessPolicy): void {
            $admin = Auth::guard('platform_admin')->user();

            if (! $admin instanceof PlatformAdmin) {
                Notification::make()->title('You are not signed in as a platform admin.')->danger()->send();

                return;
            }

            if (! $accessPolicy->canManageSupportAccess($admin)->allowed) {
                Notification::make()->title('You are not authorized to manage support access.')->danger()->send();

                return;
            }

            $mutateDecision = $accessPolicy->canMutate($admin);

            if (! $mutateDecision->allowed) {
                Notification::make()->title('Not permitted')->body($mutateDecision->reason)->danger()->send();

                return;
            }

            $firm = Firm::findByUuid((string) $record['firm_uuid']);

            $session = $directory->findApprovedSupportSessionModel($admin, $firm, (int) $record['id']);

            if ($session === null) {
                Notification::make()->title('That support access session could not be found.')->danger()->send();

                return;
            }

            try {
                $boundedAccess->revokeSupportAccessSession($admin, $session);
            } catch (RuntimeException $e) {
                Notification::make()->title('Could not revoke this session')->body($e->getMessage())->danger()->send();

                return;
            }

            Notification::make()->title('Support access session revoked')->success()->send();
        });
    }
}
