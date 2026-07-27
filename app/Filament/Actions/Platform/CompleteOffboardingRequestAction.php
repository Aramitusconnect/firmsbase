<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Enums\OffboardingRequestStatus;
use App\Models\Firm;
use App\Models\OffboardingRequest;
use App\Models\PlatformAdmin;
use App\Services\OffboardingRequestService;
use App\Services\PlatformStaffAccessPolicyService;
use App\Services\TenantContextService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * CompleteOffboardingRequestAction — OffboardingRequestResource's row
 * action. Routes exclusively through OffboardingRequestService::complete().
 * Only offered when the request is ReadyForDeletion — completing from
 * any earlier status would misrepresent an offboarding that has not
 * actually cleared export/retention/legal-hold checks yet, and
 * complete() itself performs no readiness re-check of its own (that is
 * advance()'s job).
 */
class CompleteOffboardingRequestAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'completeOffboardingRequest';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Complete');
        $this->icon(Heroicon::OutlinedCheckCircle);
        $this->color('success');

        $this->requiresConfirmation();
        $this->modalHeading('Complete Offboarding Request');
        $this->modalDescription('Marks this offboarding request Completed. This does not perform any physical deletion — see the Deletion Requests module for that separately-governed workflow.');

        $this->visible(fn (array $record): bool => ($record['status'] ?? null) === OffboardingRequestStatus::ReadyForDeletion->value);

        $this->action(function (array $record, PlatformStaffAccessPolicyService $accessPolicy, OffboardingRequestService $offboardingRequestService): void {
            $actor = Auth::guard('platform_admin')->user();

            if (! $actor instanceof PlatformAdmin) {
                Notification::make()->title('You are not signed in as a platform admin.')->danger()->send();

                return;
            }

            $manageDecision = $accessPolicy->canManageDataExports($actor);

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

            $request = (new TenantContextService)->runWithFirmContext($firm, fn () => OffboardingRequest::query()->find($record['id']));

            if ($request === null) {
                Notification::make()->title('That offboarding request could not be found.')->danger()->send();

                return;
            }

            if ($request->status !== OffboardingRequestStatus::ReadyForDeletion) {
                Notification::make()->title('This request is no longer ready for completion.')->warning()->send();

                return;
            }

            $completed = $offboardingRequestService->complete($request, $actor);

            Notification::make()
                ->title('Offboarding request completed')
                ->body("Status: {$completed->status->value}.")
                ->success()
                ->send();
        });
    }
}
