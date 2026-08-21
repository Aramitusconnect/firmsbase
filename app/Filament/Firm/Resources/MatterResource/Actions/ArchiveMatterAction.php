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
 * ArchiveMatterAction — Mission 5A. Sibling of CloseMatterAction,
 * mirroring the same TOCTOU-safe shape (re-fetch fresh inside
 * runWithFirmContext(), re-check both MatterAccessPolicyService and
 * MatterCreationAccessPolicyService, try/catch RuntimeException,
 * Notification). Only visible on an already-Closed matter — the exact
 * precondition MatterClosingService::archive() itself enforces.
 */
class ArchiveMatterAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'archiveMatter';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Archive Matter');
        $this->icon(Heroicon::OutlinedArchiveBoxArrowDown);
        $this->color('gray');
        $this->requiresConfirmation();
        $this->modalDescription('Archives this closed matter. Archiving is a final housekeeping step and cannot be undone through this action.');

        $this->visible(function (Matter $record): bool {
            if ($record->status !== MatterStatus::Closed) {
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
                Notification::make()->title('Not permitted')->body('Your role may not archive matters.')->danger()->send();

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
                        app(MatterClosingService::class)->archive($fresh, $firmUser);

                        Notification::make()->title('Matter archived')->success()->send();
                    } catch (\RuntimeException $e) {
                        Notification::make()->title('Could not archive matter')->body($e->getMessage())->danger()->send();
                    }
                },
            );
        });
    }
}
