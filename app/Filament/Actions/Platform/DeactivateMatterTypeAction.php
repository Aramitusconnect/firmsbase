<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Models\MatterType;
use App\Models\PlatformAdmin;
use App\Services\MatterTypeService;
use App\Services\PlatformStaffAccessPolicyService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * DeactivateMatterTypeAction — routes exclusively through
 * MatterTypeService::deactivate(). A soft state flip, never a
 * destructive delete. Visible only for an already-active row.
 */
class DeactivateMatterTypeAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'deactivateMatterType';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Deactivate');
        $this->icon(Heroicon::OutlinedXCircle);
        $this->color('danger');

        $this->requiresConfirmation();
        $this->modalHeading('Deactivate matter type');
        $this->modalDescription('This removes the matter type from selection on new matters. Existing matters that already reference it are unaffected.');

        $this->visible(fn (MatterType $record): bool => $record->is_active);

        $this->action(function (MatterType $record, PlatformStaffAccessPolicyService $accessPolicy, MatterTypeService $matterTypeService): void {
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

            $target = MatterType::query()->find($record->getKey());

            if ($target === null) {
                Notification::make()->title('That matter type could not be found.')->danger()->send();

                return;
            }

            $matterTypeService->deactivate($target, $actor);

            Notification::make()->title('Matter type deactivated')->success()->send();
        });
    }
}
