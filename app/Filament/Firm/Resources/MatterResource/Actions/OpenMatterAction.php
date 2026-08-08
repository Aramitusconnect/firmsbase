<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\MatterResource\Actions;

use App\Enums\MatterStatus;
use App\Models\Matter;
use App\Services\MatterAccessPolicyService;
use App\Services\MatterCreationAccessPolicyService;
use App\Services\MatterOpeningService;
use App\Services\TenantContextService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * OpenMatterAction — the ONLY UI path from `conflict_review` to `open`,
 * closing Firm Feature Manifest §2's "any Open Matter action must call
 * MatterOpeningService::openMatter(), never set status directly"
 * requirement. Does not duplicate that service's own gating logic —
 * fetches the matter's own latest ConflictCheckRun and hands both
 * straight to `MatterOpeningService::openMatter()`, then surfaces
 * whatever it actually decides (including its real
 * `ConflictCheckSummary::isClearToProceed()`-driven RuntimeException
 * message when the check isn't clear) rather than re-implementing that
 * check here.
 *
 * Visible only when the matter is already in `conflict_review` status
 * (the exact precondition `openMatter()` itself enforces) — a matter
 * still in Draft/ConflictCheckRequired has no completed run to open
 * against yet; use "Run Conflict Check" (ConflictChecksRelationManager)
 * first.
 *
 * Gated on BOTH MatterAccessPolicyService::canAccessMatter() (the same
 * real per-record boundary every other Matter action/tab uses) AND
 * MatterCreationAccessPolicyService::canOpenMatter() (FirmOwner/
 * Attorney/Paralegal/LegalAssistant) — a user must pass both.
 */
class OpenMatterAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'openMatter';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Open Matter');
        $this->icon(Heroicon::OutlinedLockOpen);
        $this->color('success');
        $this->requiresConfirmation();
        $this->modalDescription('Opens this matter, provided its most recent conflict check is completed and clear (no confirmed conflicts, no unresolved possible matches). This is the only way a matter may become Open.');

        $this->visible(function (Matter $record): bool {
            if ($record->status !== MatterStatus::ConflictReview) {
                return false;
            }

            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || (int) $firmUser->firm_id !== (int) $record->firm_id) {
                return false;
            }

            if (! app(MatterAccessPolicyService::class)->canAccessMatter(Auth::user(), $record)) {
                return false;
            }

            return app(MatterCreationAccessPolicyService::class)->canOpenMatter($firmUser->role);
        });

        $this->action(function (Matter $record): void {
            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null) {
                Notification::make()->title('You do not have access to this matter.')->danger()->send();

                return;
            }

            if (! app(MatterCreationAccessPolicyService::class)->canOpenMatter($firmUser->role)) {
                Notification::make()->title('Not permitted')->body('Your role may not open matters.')->danger()->send();

                return;
            }

            app(TenantContextService::class)->runWithFirmContext(
                (int) $firmUser->firm_id,
                function () use ($record, $firmUser): void {
                    $fresh = Matter::query()->where('id', $record->id)->firstOrFail();

                    if ((int) $firmUser->firm_id !== (int) $fresh->firm_id) {
                        Notification::make()->title('You do not have access to this matter.')->danger()->send();

                        return;
                    }

                    if (! app(MatterAccessPolicyService::class)->canAccessMatter($firmUser->user, $fresh)) {
                        Notification::make()->title('Not permitted')->danger()->send();

                        return;
                    }

                    $conflictCheckRun = $fresh->conflictCheckRuns()->latest('id')->first();

                    if ($conflictCheckRun === null) {
                        Notification::make()->title('Could not open matter')->body('No conflict check run exists for this matter yet.')->danger()->send();

                        return;
                    }

                    try {
                        app(MatterOpeningService::class)->openMatter($fresh, $conflictCheckRun, $firmUser->user);

                        Notification::make()->title('Matter opened')->success()->send();
                    } catch (\RuntimeException $e) {
                        Notification::make()->title('Could not open matter')->body($e->getMessage())->danger()->send();
                    }
                },
            );
        });
    }
}
