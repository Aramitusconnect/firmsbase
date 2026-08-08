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
 * ReactivateExpenseCategoryAction — routes exclusively through
 * ExpenseCategoryService::reactivate(). Visible only for an already-
 * inactive row.
 */
class ReactivateExpenseCategoryAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'reactivateExpenseCategory';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Reactivate');
        $this->icon(Heroicon::OutlinedCheckCircle);
        $this->color('success');
        $this->requiresConfirmation();
        $this->modalHeading('Reactivate expense category');
        $this->modalDescription('This makes the category selectable again for new expenses.');

        $this->visible(fn (ExpenseCategory $record): bool => ! $record->is_active);

        $this->action(function (ExpenseCategory $record, ExpenseCategoryService $expenseCategoryService): void {
            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null) {
                Notification::make()->title('Not permitted')->danger()->send();

                return;
            }

            $expenseCategoryService->reactivate($firmUser->firm, $record);

            Notification::make()->title('Expense category reactivated')->success()->send();
        });
    }
}
