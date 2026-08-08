<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Models\PlatformAdmin;
use App\Models\PracticeArea;
use App\Services\PlatformStaffAccessPolicyService;
use App\Services\PracticeAreaService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

/**
 * EditPracticeAreaAction — a record action on the Practice Areas table,
 * purpose-built (not Filament's generic EditAction) so every mutation
 * routes through PracticeAreaService::update(). Mirrors EditPlanAction's
 * exact shape.
 */
class EditPracticeAreaAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'editPracticeArea';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Edit');
        $this->icon(Heroicon::OutlinedPencilSquare);
        $this->color('gray');

        $this->fillForm(fn (PracticeArea $record): array => [
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
        $this->modalHeading('Edit practice area');

        $this->action(function (array $data, PracticeArea $record, PlatformStaffAccessPolicyService $accessPolicy, PracticeAreaService $practiceAreaService): void {
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

            try {
                $practiceAreaService->update($target, [
                    'name' => $data['name'],
                    'code' => $data['code'],
                    'description' => $data['description'] ?? null,
                ], $actor);
            } catch (InvalidArgumentException $e) {
                Notification::make()->title('Could not update practice area')->body($e->getMessage())->danger()->send();

                return;
            }

            Notification::make()->title('Practice area updated')->success()->send();
        });
    }
}
