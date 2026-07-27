<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Enums\StatusPageEventStatus;
use App\Models\PlatformAdmin;
use App\Models\StatusPageEvent;
use App\Services\PlatformStaffAccessPolicyService;
use App\Services\StatusPageService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * UnpublishStatusPageEventAction — Phase 4 (FirmsVault Platform Admin
 * Control Center, "Operations"). Row action on
 * PlatformStatusPageEventsPage. Routes exclusively through
 * StatusPageService::unpublish(). Hidden once already Unpublished.
 */
class UnpublishStatusPageEventAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'unpublishStatusPageEvent';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Unpublish');
        $this->icon(Heroicon::OutlinedEyeSlash);
        $this->color('danger');

        $this->requiresConfirmation();
        $this->modalHeading('Unpublish this status update');

        $this->visible(fn (StatusPageEvent $record): bool => $record->status !== StatusPageEventStatus::Unpublished);

        $this->action(function (StatusPageEvent $record, PlatformStaffAccessPolicyService $accessPolicy, StatusPageService $statusPageService): void {
            $actor = Auth::guard('platform_admin')->user();

            if (! $actor instanceof PlatformAdmin) {
                Notification::make()->title('You are not signed in as a platform admin.')->danger()->send();

                return;
            }

            $manageDecision = $accessPolicy->canManageOperations($actor);

            if (! $manageDecision->allowed) {
                Notification::make()->title('Not permitted')->body($manageDecision->reason)->danger()->send();

                return;
            }

            $mutateDecision = $accessPolicy->canMutate($actor);

            if (! $mutateDecision->allowed) {
                Notification::make()->title('Not permitted')->body($mutateDecision->reason)->danger()->send();

                return;
            }

            $statusPageService->unpublish($record->correlation_id, $actor);

            Notification::make()->title('Status update unpublished')->success()->send();
        });
    }
}
