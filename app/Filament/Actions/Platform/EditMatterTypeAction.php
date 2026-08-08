<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Models\MatterType;
use App\Models\PlatformAdmin;
use App\Services\MatterTypeService;
use App\Services\PlatformStaffAccessPolicyService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

/**
 * EditMatterTypeAction — a record action on MatterTypesRelationManager,
 * routes exclusively through MatterTypeService::update(). Mirrors
 * EditPracticeAreaAction's exact shape.
 */
class EditMatterTypeAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'editMatterType';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Edit');
        $this->icon(Heroicon::OutlinedPencilSquare);
        $this->color('gray');

        $this->fillForm(fn (MatterType $record): array => [
            'name' => $record->name,
            'code' => $record->code,
            'description' => $record->description,
        ]);

        $this->schema([
            TextInput::make('name')
                ->label('Name')
                ->required()
                ->maxLength(255),
            TextInput::make('code')
                ->label('Code')
                ->required()
                ->maxLength(255)
                ->alphaDash(),
            Textarea::make('description')
                ->label('Description')
                ->maxLength(2000),
        ]);

        $this->requiresConfirmation();
        $this->modalHeading('Edit matter type');

        $this->action(function (array $data, MatterType $record, PlatformStaffAccessPolicyService $accessPolicy, MatterTypeService $matterTypeService): void {
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

            try {
                $matterTypeService->update($target, [
                    'name' => $data['name'],
                    'code' => $data['code'],
                    'description' => $data['description'] ?? null,
                ], $actor);
            } catch (InvalidArgumentException $e) {
                Notification::make()->title('Could not update matter type')->body($e->getMessage())->danger()->send();

                return;
            }

            Notification::make()->title('Matter type updated')->success()->send();
        });
    }
}
