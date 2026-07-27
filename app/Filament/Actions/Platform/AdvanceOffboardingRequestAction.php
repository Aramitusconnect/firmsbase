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
 * AdvanceOffboardingRequestAction — OffboardingRequestResource's row
 * action. Routes exclusively through
 * OffboardingRequestService::advance() — re-evaluates export/retention/
 * legal-hold readiness and transitions to the correct next status,
 * gated here by canManageDataExports() + canMutate() before calling it,
 * mirroring every other mutating Action in this mission. Passes the
 * freshly-resolved $actor through so advance() records real attribution
 * (FVACC mission-wide final hardening review finding — this used to be
 * silently attribution-free).
 *
 * Not offered once the request has reached a terminal status
 * (Completed/Cancelled) or is already ReadyForDeletion (advance() would
 * just re-derive the same status — offering it there is a harmless
 * no-op but confusing UI, so it is hidden instead).
 */
class AdvanceOffboardingRequestAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'advanceOffboardingRequest';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Re-evaluate Readiness');
        $this->icon(Heroicon::OutlinedArrowPath);
        $this->color('warning');

        $this->requiresConfirmation();
        $this->modalHeading('Re-evaluate Offboarding Readiness');
        $this->modalDescription('Re-checks export completion, retention clearance, and legal-hold status, then advances this request to the correct resulting status.');

        $this->visible(fn (array $record): bool => ! in_array($record['status'] ?? null, [
            OffboardingRequestStatus::Completed->value,
            OffboardingRequestStatus::Cancelled->value,
        ], true));

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

            $advanced = $offboardingRequestService->advance($request, $actor);

            Notification::make()
                ->title('Readiness re-evaluated')
                ->body("Status: {$advanced->status->value}.")
                ->success()
                ->send();
        });
    }
}
