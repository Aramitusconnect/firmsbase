<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Enums\SupportAccessRequestStatus;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Services\PlatformStaffAccessPolicyService;
use App\Services\PlatformSupportAccessDirectoryService;
use App\Services\SupportAccessRequestService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * ExpireSupportCaseAction — SupportCaseResource's row action. The one
 * mutating action this phase adds to Support Cases: marks a stale
 * Requested/Approved SupportAccessRequest Expired via
 * SupportAccessRequestService::expire($request, $actor) — the actor +
 * audit plumbing this phase added to that previously zero-caller, zero-
 * actor method (see that method's own docblock).
 *
 * Deliberately the ONLY mutating action on this resource — no
 * approve/deny action exists or will ever be added here.
 * SupportAccessRequestService::approve()/deny() require a real
 * FirmUser $approver/$denier by explicit, deliberate design (the
 * model's own docblock: "a platform admin cannot approve access into a
 * firm on the firm's behalf except via the emergency path") — a
 * platform-admin-panel action structurally cannot supply that actor
 * type, so this is a genuine, honest architectural boundary, not a gap
 * this phase's own SupportCaseResourceTest leaves unproven (see that
 * test's own positive-proof assertions mirroring ConflictResourceTest's
 * established "no such action exists" pattern).
 *
 * TOCTOU-safe: resolves the acting admin and the underlying
 * SupportAccessRequest model fresh inside the closure (never trusts a
 * stale/mount-time value), gate-checks canManageSupportAccess() +
 * canMutate() before ever calling into the service, and re-validates
 * the request's own status is still Requested/Approved after the fresh
 * fetch (not merely at ->visible() time) before calling expire().
 */
class ExpireSupportCaseAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'expireSupportCase';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Expire');
        $this->icon(Heroicon::OutlinedClock);
        $this->color('gray');

        $this->requiresConfirmation();
        $this->modalHeading('Expire Support Case');
        $this->modalDescription('This marks a stale support access request as Expired. It cannot be reversed, and does not revoke any session already in progress (use Revoke on Approved Support Sessions for that).');

        $this->visible(fn (array $record): bool => in_array($record['status'] ?? null, [
            SupportAccessRequestStatus::Requested->value,
            SupportAccessRequestStatus::Approved->value,
        ], true));

        $this->action(function (array $record, PlatformSupportAccessDirectoryService $directory, SupportAccessRequestService $requestService, PlatformStaffAccessPolicyService $accessPolicy): void {
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

            $request = $directory->findSupportCaseModel($admin, $firm, (int) $record['id']);

            if ($request === null) {
                Notification::make()->title('That support case could not be found.')->danger()->send();

                return;
            }

            if (! in_array($request->status, [SupportAccessRequestStatus::Requested, SupportAccessRequestStatus::Approved], true)) {
                Notification::make()
                    ->title('This support case can no longer be expired')
                    ->body("Its status is already {$request->status->value}.")
                    ->warning()
                    ->send();

                return;
            }

            $expired = $requestService->expire($request, $admin);

            Notification::make()
                ->title('Support case expired')
                ->body("Status: {$expired->status->value}.")
                ->success()
                ->send();
        });
    }
}
