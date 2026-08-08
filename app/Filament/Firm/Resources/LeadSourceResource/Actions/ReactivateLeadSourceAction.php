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
 * ReactivateLeadSourceAction — routes exclusively through
 * LeadSourceService::reactivate(). Visible only for an already-inactive
 * row.
 */
class ReactivateLeadSourceAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'reactivateLeadSource';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Reactivate');
        $this->icon(Heroicon::OutlinedCheckCircle);
        $this->color('success');
        $this->requiresConfirmation();
        $this->modalHeading('Reactivate lead source');
        $this->modalDescription('This makes the source selectable again for new leads/clients.');

        $this->visible(fn (LeadSource $record): bool => ! $record->is_active);

        $this->action(function (LeadSource $record, LeadSourceService $leadSourceService): void {
            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null) {
                Notification::make()->title('Not permitted')->danger()->send();

                return;
            }

            $leadSourceService->reactivate($firmUser->firm, $record);

            Notification::make()->title('Lead source reactivated')->success()->send();
        });
    }
}
