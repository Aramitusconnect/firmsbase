<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Models\PlatformAdmin;
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
 * CreatePracticeAreaAction — a header action on the Practice Areas
 * list, purpose-built (not Filament's generic CreateAction) so every
 * mutation routes through PracticeAreaService, never a bare
 * `PracticeArea::create()`. Mirrors CreatePlanAction's exact shape.
 */
class CreatePracticeAreaAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'createPracticeArea';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Create practice area');
        $this->icon(Heroicon::OutlinedPlusCircle);
        $this->color('primary');

        $this->schema([
            TextInput::make('name')
                ->label('Name')
                ->required()
                ->maxLength(255),
            TextInput::make('code')
                ->label('Code')
                ->helperText('A unique, stable machine identifier, e.g. "family_law". Used as the foreign-key target for matter types — choose carefully.')
                ->required()
                ->maxLength(255)
                ->alphaDash(),
            Textarea::make('description')
                ->label('Description')
                ->maxLength(2000),
        ]);

        $this->requiresConfirmation();
        $this->modalHeading('Create practice area');

        $this->action(function (array $data, PlatformStaffAccessPolicyService $accessPolicy, PracticeAreaService $practiceAreaService): void {
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

            try {
                $practiceAreaService->create([
                    'name' => $data['name'],
                    'code' => $data['code'],
                    'description' => $data['description'] ?? null,
                ], $actor);
            } catch (InvalidArgumentException $e) {
                Notification::make()->title('Could not create practice area')->body($e->getMessage())->danger()->send();

                return;
            }

            Notification::make()->title('Practice area created')->success()->send();
        });
    }
}
