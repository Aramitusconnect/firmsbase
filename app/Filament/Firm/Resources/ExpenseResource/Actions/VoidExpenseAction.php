<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\ExpenseResource\Actions;

use App\Enums\ExpenseStatus;
use App\Models\Expense;
use App\Services\AccountingEntitlementPolicyService;
use App\Services\ExpenseService;
use App\Services\TenantContextService;
use App\Services\TimeExpenseAccessPolicyService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * VoidExpenseAction — calls ExpenseService::void() directly. Available
 * from any non-Voided status (that service's own guard: "throw if
 * already Voided"), gated on TimeExpenseAccessPolicyService::
 * canManageExpense() — the same ceiling as create/submit/edit, since
 * voiding a mis-entered expense is ordinary billing-office correction
 * work, not a supervisory approval decision.
 */
class VoidExpenseAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'voidExpense';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Void');
        $this->icon(Heroicon::OutlinedNoSymbol);
        $this->color('danger');
        $this->requiresConfirmation();

        $this->visible(function (Expense $record): bool {
            if ($record->status === ExpenseStatus::Voided) {
                return false;
            }

            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || (int) $firmUser->firm_id !== (int) $record->firm_id) {
                return false;
            }

            return app(AccountingEntitlementPolicyService::class)->isExpensesEnabledForFirm($firmUser->firm)
                && app(TimeExpenseAccessPolicyService::class)->canManageExpense($firmUser->role);
        });

        $this->action(function (Expense $record): void {
            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || ! app(TimeExpenseAccessPolicyService::class)->canManageExpense($firmUser->role)) {
                Notification::make()->title('Not permitted')->danger()->send();

                return;
            }

            app(TenantContextService::class)->runWithFirmContext(
                (int) $firmUser->firm_id,
                function () use ($record, $firmUser): void {
                    $fresh = Expense::query()->where('id', $record->id)->firstOrFail();

                    if ((int) $firmUser->firm_id !== (int) $fresh->firm_id) {
                        Notification::make()->title('You do not have access to this expense.')->danger()->send();

                        return;
                    }

                    try {
                        app(ExpenseService::class)->void($firmUser->firm, $fresh);
                        Notification::make()->title('Expense voided')->success()->send();
                    } catch (\RuntimeException $e) {
                        Notification::make()->title('Could not void expense')->body($e->getMessage())->danger()->send();
                    }
                },
            );
        });
    }
}
