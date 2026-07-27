<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Enums\LegalHoldStatus;
use App\Models\Firm;
use App\Models\LegalHold;
use App\Models\PlatformAdmin;
use App\Services\LegalHoldService;
use App\Services\PlatformStaffAccessPolicyService;
use App\Services\TenantContextService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * ReleaseLegalHoldAction — LegalHoldResource's row action. Operates on
 * the array-shaped row PlatformLegalHoldDirectoryService::list()/find()
 * return (see DeadLetterQueueResource's RequeueDeadLetterQueueEventAction
 * for the established "array $record" convention this mirrors), never
 * an Eloquent LegalHold instance bound directly — the underlying table
 * is FORCE RLS with no cross-firm-visible policy, so the row itself must
 * be re-fetched fresh under the correct firm's context before mutating.
 *
 * TOCTOU-safe: fresh actor resolution, fresh LegalHold re-fetch under
 * runWithFirmContext(), both canManageLegalHolds() and canMutate()
 * checked before calling LegalHoldService::release() — mirrors
 * PlaceLegalHoldAction's exact gate discipline.
 */
class ReleaseLegalHoldAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'releaseLegalHold';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Release');
        $this->icon(Heroicon::OutlinedLockOpen);
        $this->color('success');

        $this->schema([
            Textarea::make('release_reason')
                ->label('Release reason')
                ->required()
                ->rows(2),
        ]);

        $this->requiresConfirmation();
        $this->modalHeading('Release Legal Hold');
        $this->modalDescription('This releases the hold, allowing deletion/offboarding to proceed again for its scope.');
        $this->modalSubmitActionLabel('Release Hold');

        $this->visible(fn (array $record): bool => ($record['status'] ?? null) === LegalHoldStatus::Active->value);

        $this->action(function (array $record, array $data, PlatformStaffAccessPolicyService $accessPolicy, LegalHoldService $legalHoldService): void {
            $actor = Auth::guard('platform_admin')->user();

            if (! $actor instanceof PlatformAdmin) {
                Notification::make()->title('You are not signed in as a platform admin.')->danger()->send();

                return;
            }

            $manageDecision = $accessPolicy->canManageLegalHolds($actor);

            if (! $manageDecision->allowed) {
                Notification::make()->title('Not permitted')->body($manageDecision->reason)->danger()->send();

                return;
            }

            $mutateDecision = $accessPolicy->canMutate($actor);

            if (! $mutateDecision->allowed) {
                Notification::make()->title('Not permitted')->body($mutateDecision->reason)->danger()->send();

                return;
            }

            $firm = Firm::findByUuid((string) $record['firm_uuid']);

            if ($firm === null) {
                Notification::make()->title('That firm could not be found.')->danger()->send();

                return;
            }

            $hold = (new TenantContextService)->runWithFirmContext($firm, fn () => LegalHold::query()->find($record['id']));

            if ($hold === null) {
                Notification::make()->title('That legal hold could not be found.')->danger()->send();

                return;
            }

            if ($hold->status !== LegalHoldStatus::Active) {
                Notification::make()->title('This hold is already released.')->warning()->send();

                return;
            }

            $released = $legalHoldService->release($hold, $actor, (string) $data['release_reason']);

            Notification::make()
                ->title('Legal hold released')
                ->body("Hold #{$released->id} is now Released.")
                ->success()
                ->send();
        });
    }
}
