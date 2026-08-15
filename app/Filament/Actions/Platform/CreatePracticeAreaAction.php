<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Models\PlatformAdmin;
use App\Services\Configuration\PracticeAreaCanonicalizationService;
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
 * CreatePracticeAreaAction — a header action on the Practice Areas
 * list, purpose-built (not Filament's generic CreateAction) so every
 * mutation routes through PracticeAreaService, never a bare
 * `PracticeArea::create()`. Mirrors CreatePlanAction's exact shape.
 *
 * Configuration Control Plane (mission section 28): shows a live
 * POTENTIALLY EQUIVALENT warning, with evidence, when the typed name/
 * code normalizes onto an existing practice area, and requires a
 * written reason before allowing the create to proceed anyway. Both
 * the warning and the requirement are presentational conveniences —
 * PracticeAreaService::create() performs the same check server-side
 * and throws regardless of what this form rendered, so a stale or
 * manipulated form can never create past a detected duplicate
 * unjustified.
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
                ->maxLength(255)
                ->live(onBlur: true),
            TextInput::make('code')
                ->label('Code')
                ->helperText('A unique, stable machine identifier, e.g. "family_law". Used as the foreign-key target for matter types — choose carefully. Once other records reference this practice area, the code can no longer be changed.')
                ->required()
                ->maxLength(255)
                ->alphaDash()
                ->live(onBlur: true),
            Placeholder::make('duplicate_warning')
                ->hiddenLabel()
                ->content(fn (callable $get): string => self::duplicateWarningFor($get))
                ->visible(fn (callable $get): bool => self::duplicateWarningFor($get) !== ''),
            Textarea::make('duplicate_override_reason')
                ->label('Reason for creating despite a potential duplicate')
                ->rows(2)
                ->maxLength(500)
                ->helperText('Required because an existing practice area normalizes to the same identifier. Explain why this is genuinely distinct taxonomy.')
                ->visible(fn (callable $get): bool => self::duplicateWarningFor($get) !== '')
                ->required(fn (callable $get): bool => self::duplicateWarningFor($get) !== ''),
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
                $practiceAreaService->create(
                    [
                        'name' => $data['name'],
                        'code' => $data['code'],
                        'description' => $data['description'] ?? null,
                    ],
                    $actor,
                    duplicateOverrideReason: $data['duplicate_override_reason'] ?? null,
                );
            } catch (InvalidArgumentException $e) {
                Notification::make()->title('Could not create practice area')->body($e->getMessage())->danger()->send();

                return;
            }

            Notification::make()->title('Practice area created')->success()->send();
        });
    }

    /**
     * Operator-facing duplicate evidence for the values currently typed
     * into the form, or '' when nothing collides. Returning the rendered
     * string (rather than a boolean plus a second lookup) keeps the
     * Placeholder's content and every visible()/required() decision
     * derived from exactly one evaluation of the same rule.
     */
    private static function duplicateWarningFor(callable $get): string
    {
        $name = $get('name');
        $code = $get('code');

        if (! filled($name) && ! filled($code)) {
            return '';
        }

        $candidates = app(PracticeAreaCanonicalizationService::class)->duplicateCandidatesFor(
            name: is_string($name) ? $name : null,
            code: is_string($code) ? $code : null,
        );

        if ($candidates->isEmpty()) {
            return '';
        }

        return '⚠ Potentially equivalent to an existing practice area: '.$candidates
            ->map(fn ($candidate): string => $candidate->summaryLine().' — matched because: '.implode('; ', $candidate->matchReasons))
            ->implode(' | ');
    }
}
