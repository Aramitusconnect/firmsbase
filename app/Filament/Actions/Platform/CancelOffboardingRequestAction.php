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
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * CancelOffboardingRequestAction — OffboardingRequestResource's row
 * action. Routes exclusively through OffboardingRequestService::cancel().
 * Not offered once the request is already Completed or Cancelled.
 */
class CancelOffboardingRequestAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'cancelOffboardingRequest';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Cancel');
        $this->icon(Heroicon::OutlinedXCircle);
        $this->color('danger');

        $this->schema([
            Textarea::make('reason')
                ->label('Cancellation reason')
                ->required()
                ->rows(2),
        ]);

        $this->requiresConfirmation();
        $this->modalHeading('Cancel Offboarding Request');
        $this->modalSubmitActionLabel('Cancel Request');

        $this->visible(fn (array $record): bool => ! in_array($record['status'] ?? null, [
            OffboardingRequestStatus::Completed->value,
            OffboardingRequestStatus::Cancelled->value,
        ], true));

        $this->action(function (array $record, array $data, PlatformStaffAccessPolicyService $accessPolicy, OffboardingRequestService $offboardingRequestService): void {
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

            if (in_array($request->status, [OffboardingRequestStatus::Completed, OffboardingRequestStatus::Cancelled], true)) {
                Notification::make()->title('This request can no longer be cancelled.')->warning()->send();

                return;
            }

            $cancelled = $offboardingRequestService->cancel($request, (string) $data['reason']);

            Notification::make()
                ->title('Offboarding request cancelled')
                ->body("Status: {$cancelled->status->value}.")
                ->success()
                ->send();
        });
    }
}
