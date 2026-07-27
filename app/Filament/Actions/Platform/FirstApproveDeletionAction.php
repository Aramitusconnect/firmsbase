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

/**
 * FirstApproveDeletionAction — DeletionRequestResource's row action.
 * Routes exclusively through DeletionApprovalService::firstApprove().
 * Only offered while the linked DeletionApproval is Pending.
 */
class FirstApproveDeletionAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'firstApproveDeletion';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('First Approve');
        $this->icon(Heroicon::OutlinedCheck);
        $this->color('warning');

        $this->requiresConfirmation();
        $this->modalHeading('First Approval');
        $this->modalDescription('Records your first approval for this production data deletion. A second, different platform admin must approve before this can proceed.');

        $this->visible(fn (array $record): bool => ($record['approval']['status'] ?? null) === HighRiskChangeRequestStatus::Pending->value);

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

            if ($request === null || $request->approval === null) {
                Notification::make()->title('No approval is pending for this deletion request.')->danger()->send();

                return;
            }

            $approval = DeletionApproval::query()->find($record['approval']['id'] ?? null);

            if ($approval === null || $approval->deletion_request_id !== $request->id) {
                Notification::make()->title('That approval could not be found for this deletion request.')->danger()->send();

                return;
            }

            if ($approval->status !== HighRiskChangeRequestStatus::Pending) {
                Notification::make()->title('This approval is no longer pending a first approval.')->warning()->send();

                return;
            }

            $approved = $deletionApprovalService->firstApprove($approval, $actor);

            Notification::make()
                ->title('First approval recorded')
                ->body("Status: {$approved->status->value}.")
                ->success()
                ->send();
        });
    }
}
