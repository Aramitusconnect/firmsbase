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
 * ActivateMatterTypeAction — routes exclusively through
 * MatterTypeService::activate(). Visible only for an already-inactive
 * row. Mirrors ActivatePracticeAreaAction's exact shape.
 */
class ActivateMatterTypeAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'activateMatterType';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Activate');
        $this->icon(Heroicon::OutlinedCheckCircle);
        $this->color('success');

        $this->requiresConfirmation();
        $this->modalHeading('Activate matter type');
        $this->modalDescription('This makes the matter type selectable again for new matters.');

        $this->visible(fn (MatterType $record): bool => ! $record->is_active);

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

            $matterTypeService->activate($target, $actor);

            Notification::make()->title('Matter type activated')->success()->send();
        });
    }
}
