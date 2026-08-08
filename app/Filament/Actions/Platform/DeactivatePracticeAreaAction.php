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
 * DeactivatePracticeAreaAction — routes exclusively through
 * PracticeAreaService::deactivate(). A soft state flip, never a
 * destructive delete — see PracticeAreaService's own docblock. Visible
 * only for an already-active row.
 */
class DeactivatePracticeAreaAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'deactivatePracticeArea';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Deactivate');
        $this->icon(Heroicon::OutlinedXCircle);
        $this->color('danger');

        $this->requiresConfirmation();
        $this->modalHeading('Deactivate practice area');
        $this->modalDescription('This removes the practice area from selection on new matters, clients, and leads. Existing records that already reference it are unaffected.');

        $this->visible(fn (PracticeArea $record): bool => $record->is_active);

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

            $practiceAreaService->deactivate($target, $actor);

            Notification::make()->title('Practice area deactivated')->success()->send();
        });
    }
}
