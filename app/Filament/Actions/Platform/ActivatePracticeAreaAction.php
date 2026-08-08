<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Models\PlatformAdmin;
use App\Models\PracticeArea;
use App\Services\PlatformStaffAccessPolicyService;
use App\Services\PracticeAreaService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * ActivatePracticeAreaAction — routes exclusively through
 * PracticeAreaService::activate(). Visible only for an already-inactive
 * row. Mirrors ActivatePlanAction's exact shape.
 */
class ActivatePracticeAreaAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'activatePracticeArea';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Activate');
        $this->icon(Heroicon::OutlinedCheckCircle);
        $this->color('success');

        $this->requiresConfirmation();
        $this->modalHeading('Activate practice area');
        $this->modalDescription('This makes the practice area selectable again for new matters, clients, and leads.');

        $this->visible(fn (PracticeArea $record): bool => ! $record->is_active);

        $this->action(function (PracticeArea $record, PlatformStaffAccessPolicyService $accessPolicy, PracticeAreaService $practiceAreaService): void {
            $actor = Auth::guard('platform_admin')->user();

            if (! $actor instanceof PlatformAdmin) {
                Notification::make()->title('You are not signed in as a platform admin.')->danger()->send();

                return;
            }

            $manageDecision = $accessPolicy->canManagePracticeAreaCatalog($actor);

            if (! $manageDecision->allowed) {
                Notification::make()->title('Not permitted')->body($manageDecision->reason)->danger()->send();

                return;
            }

            $mutateDecision = $accessPolicy->canMutate($actor);

            if (! $mutateDecision->allowed) {
                Notification::make()->title('Not permitted')->body($mutateDecision->reason)->danger()->send();

                return;
            }

            $target = PracticeArea::query()->find($record->getKey());

            if ($target === null) {
                Notification::make()->title('That practice area could not be found.')->danger()->send();

                return;
            }

            $practiceAreaService->activate($target, $actor);

            Notification::make()->title('Practice area activated')->success()->send();
        });
    }
}
