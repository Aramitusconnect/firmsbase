<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Models\PlatformAdmin;
use App\Models\PracticeArea;
use App\Services\Configuration\PracticeAreaCanonicalizationService;
use App\Services\Configuration\PracticeAreaDependencyAnalysisService;
use App\Services\PlatformStaffAccessPolicyService;
use App\Services\PracticeAreaService;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
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
 *
 * Configuration Control Plane (mission sections 28/33):
 *
 *   The canonical `code` field is disabled once the practice area has
 *   real references — other records resolve this taxonomy by code and
 *   no rename/migration service exists to carry them across. The
 *   disabled input is a courtesy, not the control:
 *   PracticeAreaService::update() refuses the change server-side.
 *
 *   A duplicate warning + required override reason applies to edits for
 *   the same reason it applies to creates — renaming one practice area
 *   onto another's identity is just as damaging as creating a duplicate
 *   outright, and is likewise re-checked inside the service.
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
                ->maxLength(255)
                ->live(onBlur: true),
            TextInput::make('code')
                ->label('Canonical code')
                ->required()
                ->maxLength(255)
                ->alphaDash()
                ->live(onBlur: true)
                ->disabled(fn (PracticeArea $record): bool => self::isReferenced($record))
                ->helperText(fn (PracticeArea $record): string => self::isReferenced($record)
                    ? 'Locked: other records already reference this practice area. Changing a referenced canonical code would orphan them, and there is no rename/migration service to carry them across. Create a new practice area and deactivate this one instead.'
                    : 'A stable machine identifier. This becomes locked as soon as anything references this practice area.'),
            Placeholder::make('duplicate_warning')
                ->hiddenLabel()
                ->content(fn (callable $get, PracticeArea $record): string => self::duplicateWarningFor($get, $record))
                ->visible(fn (callable $get, PracticeArea $record): bool => self::duplicateWarningFor($get, $record) !== ''),
            Textarea::make('duplicate_override_reason')
                ->label('Reason for saving despite a potential duplicate')
                ->rows(2)
                ->maxLength(500)
                ->helperText('Required because these values normalize onto a different existing practice area.')
                ->visible(fn (callable $get, PracticeArea $record): bool => self::duplicateWarningFor($get, $record) !== '')
                ->required(fn (callable $get, PracticeArea $record): bool => self::duplicateWarningFor($get, $record) !== ''),
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

            // A disabled input submits no value, so fall back to the
            // stored code rather than sending null into the service and
            // tripping its "a code is required" guard.
            $submittedCode = $data['code'] ?? $target->code;

            try {
                $practiceAreaService->update(
                    $target,
                    [
                        'name' => $data['name'],
                        'code' => $submittedCode,
                        'description' => $data['description'] ?? null,
                    ],
                    $actor,
                    duplicateOverrideReason: $data['duplicate_override_reason'] ?? null,
                );
            } catch (InvalidArgumentException $e) {
                Notification::make()->title('Could not update practice area')->body($e->getMessage())->danger()->send();

                return;
            }

            Notification::make()->title('Practice area updated')->success()->send();
        });
    }

    private static function isReferenced(PracticeArea $record): bool
    {
        return app(PracticeAreaDependencyAnalysisService::class)->hasGlobalReferences($record);
    }

    /**
     * @see CreatePracticeAreaAction::duplicateWarningFor() — same rule,
     *      additionally excluding the record being edited so a practice
     *      area never flags itself.
     */
    private static function duplicateWarningFor(callable $get, PracticeArea $record): string
    {
        $name = $get('name');
        $code = $get('code');

        if (! filled($name) && ! filled($code)) {
            return '';
        }

        $candidates = app(PracticeAreaCanonicalizationService::class)->duplicateCandidatesFor(
            name: is_string($name) ? $name : null,
            code: is_string($code) ? $code : null,
            excludingId: $record->getKey(),
        );

        if ($candidates->isEmpty()) {
            return '';
        }

        return '⚠ Potentially equivalent to an existing practice area: '.$candidates
            ->map(fn ($candidate): string => $candidate->summaryLine().' — matched because: '.implode('; ', $candidate->matchReasons))
            ->implode(' | ');
    }
}
