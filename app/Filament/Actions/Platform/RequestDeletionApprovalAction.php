<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Enums\DeletionRequestStatus;
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
 * RequestDeletionApprovalAction — DeletionRequestResource's row action.
 * Routes exclusively through DeletionApprovalService::requestApproval() —
 * already a genuine, already-PlatformAdmin-typed, already-audited
 * (via HighRiskPlatformChangePolicyService -> security_events) mutation.
 * Only offered once SubmitDeletionRequestForApprovalAction has already
 * moved the request to PendingApproval AND no approval row exists yet
 * (requestApproval() would otherwise create a second, orphaned
 * DeletionApproval for the same request).
 */
class RequestDeletionApprovalAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'requestDeletionApproval';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Request Approval');
        $this->icon(Heroicon::OutlinedHandRaised);
        $this->color('warning');

        $this->schema([
            Textarea::make('reason')
                ->label('Reason for this high-risk change request')
                ->required()
                ->rows(3),
        ]);

        $this->requiresConfirmation();
        $this->modalHeading('Request Deletion Approval');
        $this->modalDescription('Opens a two-person, high-risk-change approval workflow for this deletion request.');

        $this->visible(fn (array $record): bool => ($record['status'] ?? null) === DeletionRequestStatus::PendingApproval->value
            && ($record['approval'] ?? null) === null);

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

            if ($request->approval !== null) {
                Notification::make()->title('An approval has already been requested for this deletion request.')->warning()->send();

                return;
            }

            $approval = $deletionApprovalService->requestApproval($request, $actor, (string) $data['reason']);

            Notification::make()
                ->title('Approval requested')
                ->body("Approval #{$approval->id} opened — awaiting first approval.")
                ->success()
                ->send();
        });
    }
}
