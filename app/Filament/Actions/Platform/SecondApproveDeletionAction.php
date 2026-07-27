<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Enums\HighRiskChangeRequestStatus;
use App\Models\DeletionApproval;
use App\Models\DeletionRequest;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Services\DeletionApprovalService;
use App\Services\PlatformStaffAccessPolicyService;
use App\Services\TenantContextService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

/**
 * SecondApproveDeletionAction — DeletionRequestResource's row action.
 * Routes exclusively through DeletionApprovalService::secondApprove(),
 * which itself enforces "the second approver must be a different
 * platform admin than the first approver" (HighRiskPlatformChangePolicyService)
 * — this action does not duplicate that check, it surfaces the
 * resulting InvalidArgumentException as a plain denial notification.
 * On success this is the exact call that moves the linked DeletionRequest
 * to ReadyForExecution — the terminal state; never described as
 * "deleted" anywhere in this action's copy.
 */
class SecondApproveDeletionAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'secondApproveDeletion';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Second Approve');
        $this->icon(Heroicon::OutlinedCheckBadge);
        $this->color('danger');

        $this->requiresConfirmation();
        $this->modalHeading('Second Approval');
        $this->modalDescription('Records the second, required approval for this production data deletion. This moves the request to "approved for execution" — this system never physically deletes the underlying record.');
        $this->modalSubmitActionLabel('Second Approve');

        $this->visible(fn (array $record): bool => ($record['approval']['status'] ?? null) === HighRiskChangeRequestStatus::FirstApproved->value);

        $this->action(function (array $record, PlatformStaffAccessPolicyService $accessPolicy, DeletionApprovalService $deletionApprovalService): void {
            $actor = Auth::guard('platform_admin')->user();

            if (! $actor instanceof PlatformAdmin) {
                Notification::make()->title('You are not signed in as a platform admin.')->danger()->send();

                return;
            }

            $manageDecision = $accessPolicy->canManageDeletionGovernance($actor);

            if (! $manageDecision->allowed) {
                Notification::make()->title('Not permitted')->body($manageDecision->reason)->danger()->send();

                return;
            }

            $mutateDecision = $accessPolicy->canMutate($actor);

            if (! $mutateDecision->allowed) {
                Notification::make()->title('Not permitted')->body($mutateDecision->reason)->danger()->send();

                return;
            }

            $firm = Firm::findByUuid((string) $record['firm_uuid']);

            if ($firm === null) {
                Notification::make()->title('That firm could not be found.')->danger()->send();

                return;
            }

            $request = (new TenantContextService)->runWithFirmContext($firm, fn () => DeletionRequest::query()->find($record['id']));

            if ($request === null) {
                Notification::make()->title('That deletion request could not be found.')->danger()->send();

                return;
            }

            $approval = DeletionApproval::query()->find($record['approval']['id'] ?? null);

            if ($approval === null || $approval->deletion_request_id !== $request->id) {
                Notification::make()->title('That approval could not be found for this deletion request.')->danger()->send();

                return;
            }

            if ($approval->status !== HighRiskChangeRequestStatus::FirstApproved) {
                Notification::make()->title('This approval is not awaiting a second approval.')->warning()->send();

                return;
            }

            try {
                $approved = $deletionApprovalService->secondApprove($approval, $request, $actor);
            } catch (InvalidArgumentException $e) {
                Notification::make()->title('Not permitted')->body($e->getMessage())->danger()->send();

                return;
            }

            Notification::make()
                ->title('Second approval recorded')
                ->body("Status: {$approved->status->value}. The linked deletion request is now approved for execution — no physical deletion occurs here.")
                ->success()
                ->send();
        });
    }
}
