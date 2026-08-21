<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\ChartOfAccountResource\Actions;

use App\Models\ChartOfAccount;
use App\Services\ChartOfAccountsService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * DeactivateChartOfAccountAction — routes exclusively through
 * ChartOfAccountsService::deactivate(). A soft state flip, never a
 * destructive delete — an already-referenced account (expense
 * categories, accounting export lines) must remain a valid foreign key
 * target. Visible only for an already-active row. Deactivating the
 * account real posting code currently requires for its purpose will
 * make the next such business event fail closed with
 * AccountingSetupIncompleteException, exactly as intended — this action
 * does not warn about that specifically, since the same information is
 * already visible on the Accounting Overview page's required-purposes
 * checklist.
 */
class DeactivateChartOfAccountAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'deactivateChartOfAccount';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Deactivate');
        $this->icon(Heroicon::OutlinedXCircle);
        $this->color('danger');
        $this->requiresConfirmation();
        $this->modalHeading('Deactivate account');
        $this->modalDescription('This removes the account from active use. If it is the account a required accounting purpose currently resolves to, related actions will fail until a replacement is created.');

        $this->visible(fn (ChartOfAccount $record): bool => $record->is_active);

        $this->action(function (ChartOfAccount $record, ChartOfAccountsService $chartOfAccounts): void {
            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null) {
                Notification::make()->title('Not permitted')->danger()->send();

                return;
            }

            $chartOfAccounts->deactivate($firmUser->firm, $record);

            Notification::make()->title('Account deactivated')->success()->send();
        });
    }
}
