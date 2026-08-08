<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Models\PlatformAdmin;
use App\Models\PracticeArea;
use App\Services\MatterTypeService;
use App\Services\PlatformStaffAccessPolicyService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

/**
 * CreateMatterTypeAction — a header action on
 * PracticeAreaResource\RelationManagers\MatterTypesRelationManager, so
 * Matter Types are always created nested under the practice area
 * they're viewed from ("Practice Area → Matter Types" — this mission's
 * own required navigation shape), never as an independent top-level
 * resource. Routes exclusively through MatterTypeService, never a bare
 * `MatterType::create()`. Mirrors CreatePracticeAreaAction's shape,
 * with the owning PracticeArea resolved from the RelationManager.
 */
class CreateMatterTypeAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'createMatterType';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Add matter type');
        $this->icon(Heroicon::OutlinedPlusCircle);
        $this->color('primary');

        $this->schema([
            TextInput::make('name')
                ->label('Name')
                ->required()
                ->maxLength(255),
            TextInput::make('code')
                ->label('Code')
                ->helperText('A unique, stable machine identifier within this practice area, e.g. "divorce".')
                ->required()
                ->maxLength(255)
                ->alphaDash(),
            Textarea::make('description')
                ->label('Description')
                ->maxLength(2000),
        ]);

        $this->requiresConfirmation();
        $this->modalHeading('Add matter type');

        $this->action(function (array $data, RelationManager $livewire, PlatformStaffAccessPolicyService $accessPolicy, MatterTypeService $matterTypeService): void {
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

            /** @var PracticeArea $practiceArea */
            $practiceArea = $livewire->getOwnerRecord();

            try {
                $matterTypeService->create($practiceArea, [
                    'name' => $data['name'],
                    'code' => $data['code'],
                    'description' => $data['description'] ?? null,
                ], $actor);
            } catch (InvalidArgumentException $e) {
                Notification::make()->title('Could not create matter type')->body($e->getMessage())->danger()->send();

                return;
            }

            Notification::make()->title('Matter type created')->success()->send();
        });
    }
}
