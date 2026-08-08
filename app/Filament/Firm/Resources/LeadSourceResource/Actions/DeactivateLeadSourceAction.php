<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\LeadSourceResource\Actions;

use App\Models\LeadSource;
use App\Services\LeadSourceService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * DeactivateLeadSourceAction — routes exclusively through
 * LeadSourceService::deactivate(). A soft state flip, never a
 * destructive delete. Visible only for an already-active row.
 */
class DeactivateLeadSourceAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'deactivateLeadSource';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Deactivate');
        $this->icon(Heroicon::OutlinedXCircle);
        $this->color('danger');
        $this->requiresConfirmation();
        $this->modalHeading('Deactivate lead source');
        $this->modalDescription('This removes the source from selection on new leads/clients. Existing leads that already reference it are unaffected.');

        $this->visible(fn (LeadSource $record): bool => $record->is_active);

        $this->action(function (LeadSource $record, LeadSourceService $leadSourceService): void {
            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null) {
                Notification::make()->title('Not permitted')->danger()->send();

                return;
            }

            $leadSourceService->deactivate($firmUser->firm, $record);

            Notification::make()->title('Lead source deactivated')->success()->send();
        });
    }
}
