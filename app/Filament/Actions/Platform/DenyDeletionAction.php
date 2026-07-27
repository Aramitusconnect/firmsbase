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
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * DenyDeletionAction — DeletionRequestResource's row action. Routes
 * exclusively through DeletionApprovalService::deny(), which also
 * transitions the linked DeletionRequest to Denied. Offered whenever
 * the linked approval is still Pending or FirstApproved (not yet
 * Approved/Denied).
 */
class DenyDeletionAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'denyDeletion';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Deny');
        $this->icon(Heroicon::OutlinedXMark);
        $this->color('danger');

        $this->schema([
            Textarea::make('reason')
                ->label('Denial reason')
                ->required()
                ->rows(2),
        ]);

        $this->requiresConfirmation();
        $this->modalHeading('Deny Deletion Request');
        $this->modalSubmitActionLabel('Deny');

        $this->visible(fn (array $record): bool => in_array($record['approval']['status'] ?? null, [
            HighRiskChangeRequestStatus::Pending->value,
            HighRiskChangeRequestStatus::FirstApproved->value,
        ], true));

        $this->action(function (array $record, array $data, PlatformStaffAccessPolicyService $accessPolicy, DeletionApprovalService $deletionApprovalService): void {
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

            if (! in_array($approval->status, [HighRiskChangeRequestStatus::Pending, HighRiskChangeRequestStatus::FirstApproved], true)) {
                Notification::make()->title('This approval can no longer be denied.')->warning()->send();

                return;
            }

            $denied = $deletionApprovalService->deny($approval, $request, $actor, (string) $data['reason']);

            Notification::make()
                ->title('Deletion request denied')
                ->body("Status: {$denied->status->value}.")
                ->success()
                ->send();
        });
    }
}
