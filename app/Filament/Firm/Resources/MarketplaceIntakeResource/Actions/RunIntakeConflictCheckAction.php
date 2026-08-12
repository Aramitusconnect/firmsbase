<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\MarketplaceIntakeResource\Actions;

use App\Enums\MarketplaceIntakeStatus;
use App\Marketplace\Models\MarketplaceIntake;
use App\Marketplace\Services\MarketplaceIntakeConflictCheckService;
use App\Models\Firm;
use App\Services\ClientCrmAccessPolicyService;
use App\Services\TenantContextService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * RunIntakeConflictCheckAction — Mission 3 (MyAttorney Conversion + AI
 * Intake), checkpoint 9. Calls
 * MarketplaceIntakeConflictCheckService::evaluate() directly — makes
 * no legal determination itself, mirrors RunConflictCheckAction's own
 * "the modal never implies more than the backend actually does"
 * convention. Gated on the same ClientCrmAccessPolicyService::
 * canRunConflictCheck() ceiling the Matter-level action uses.
 */
class RunIntakeConflictCheckAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'runIntakeConflictCheck';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Run Conflict Check');
        $this->icon(Heroicon::OutlinedMagnifyingGlass);
        $this->color('primary');
        $this->modalHeading('Run Conflict Check');
        $this->modalDescription('Searches this firm\'s existing clients, contacts, parties, and matter parties against the opposing-party names captured during intake. This makes no legal judgment — every match is flagged for human review.');
        $this->modalSubmitActionLabel('Run Check');
        $this->requiresConfirmation();

        $this->visible(function (MarketplaceIntake $record): bool {
            if ($record->status !== MarketplaceIntakeStatus::UnderReview) {
                return false;
            }

            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || (int) $firmUser->firm_id !== (int) $record->firm_id) {
                return false;
            }

            return app(ClientCrmAccessPolicyService::class)->canRunConflictCheck($firmUser->role);
        });

        $this->action(function (MarketplaceIntake $record, MarketplaceIntakeConflictCheckService $conflictCheck): void {
            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || ! app(ClientCrmAccessPolicyService::class)->canRunConflictCheck($firmUser->role)) {
                Notification::make()->title('Not permitted')->danger()->send();

                return;
            }

            try {
                $result = app(TenantContextService::class)->runWithFirmContext(
                    (int) $firmUser->firm_id,
                    function () use ($record, $firmUser, $conflictCheck) {
                        $firm = Firm::query()->findOrFail($firmUser->firm_id);
                        $fresh = MarketplaceIntake::query()->where('id', $record->id)->firstOrFail();

                        return $conflictCheck->evaluate($firm, $fresh, $firmUser);
                    },
                );
            } catch (RuntimeException $e) {
                Notification::make()->title('Could not run conflict check')->body($e->getMessage())->danger()->send();

                return;
            }

            Notification::make()
                ->title($result->status === MarketplaceIntakeStatus::ConflictReviewRequired ? 'Possible matches found' : 'No possible matches found')
                ->body('Every match is flagged for human review only — no legal determination has been made.')
                ->success()
                ->send();
        });
    }
}
