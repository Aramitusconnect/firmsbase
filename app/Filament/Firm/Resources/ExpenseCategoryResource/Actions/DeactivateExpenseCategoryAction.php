<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\ExpenseCategoryResource\Actions;

use App\Models\ExpenseCategory;
use App\Services\ExpenseCategoryService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * DeactivateExpenseCategoryAction — routes exclusively through
 * ExpenseCategoryService::deactivate(). A soft state flip, never a
 * destructive delete — an already-referenced category must remain a
 * valid foreign key target for every existing Expense. Visible only
 * for an already-active row.
 */
class DeactivateExpenseCategoryAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'deactivateExpenseCategory';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Deactivate');
        $this->icon(Heroicon::OutlinedXCircle);
        $this->color('danger');
        $this->requiresConfirmation();
        $this->modalHeading('Deactivate expense category');
        $this->modalDescription('This removes the category from selection on new expenses. Existing expenses that already reference it are unaffected.');

        $this->visible(fn (ExpenseCategory $record): bool => $record->is_active);

        $this->action(function (ExpenseCategory $record, ExpenseCategoryService $expenseCategoryService): void {
            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null) {
                Notification::make()->title('Not permitted')->danger()->send();

                return;
            }

            $expenseCategoryService->deactivate($firmUser->firm, $record);

            Notification::make()->title('Expense category deactivated')->success()->send();
        });
    }
}
