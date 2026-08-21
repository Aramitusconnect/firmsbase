<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\MatterResource\Actions;

use App\Enums\MatterStatus;
use App\Models\Matter;
use App\Services\MatterAccessPolicyService;
use App\Services\MatterClosingService;
use App\Services\MatterCreationAccessPolicyService;
use App\Services\TenantContextService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * CloseMatterAction — Mission 5A. Mirrors OpenMatterAction's exact
 * TOCTOU-safe shape: re-fetches the record fresh inside
 * TenantContextService::runWithFirmContext(), re-checks both the
 * per-record MatterAccessPolicyService::canAccessMatter() boundary and
 * the MatterCreationAccessPolicyService::canOpenMatter() role ceiling
 * (deliberately reused rather than inventing a third, narrower
 * *AccessPolicyService method for this mission — closing/archiving a
 * matter is the same "consequential matter lifecycle action" tier as
 * opening one), then hands off to MatterClosingService::close(),
 * surfacing its own RuntimeException message rather than
 * re-implementing the transition guard here.
 *
 * Visible only when the matter is in a closable status (the same set
 * MatterClosingService::close() itself enforces) — kept in sync with
 * that service intentionally, both are read from the same enum list
 * conceptually, verified by MatterClosingServiceTest.
 */
class CloseMatterAction extends Action
{
    private const CLOSABLE_STATUSES = [
        MatterStatus::Open,
        MatterStatus::Active,
        MatterStatus::WaitingOnClient,
        MatterStatus::ReadyForReview,
        MatterStatus::FiledSubmitted,
    ];

    public static function getDefaultName(): ?string
    {
        return 'closeMatter';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Close Matter');
        $this->icon(Heroicon::OutlinedLockClosed);
        $this->color('danger');
        $this->requiresConfirmation();
        $this->modalDescription('Closes this matter. A closed matter can later be archived, but cannot be reopened through this action.');

        $this->visible(function (Matter $record): bool {
            if (! in_array($record->status, self::CLOSABLE_STATUSES, true)) {
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
                Notification::make()->title('Not permitted')->body('Your role may not close matters.')->danger()->send();

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

                    try {
                        app(MatterClosingService::class)->close($fresh, $firmUser);

                        Notification::make()->title('Matter closed')->success()->send();
                    } catch (\RuntimeException $e) {
                        Notification::make()->title('Could not close matter')->body($e->getMessage())->danger()->send();
                    }
                },
            );
        });
    }
}
