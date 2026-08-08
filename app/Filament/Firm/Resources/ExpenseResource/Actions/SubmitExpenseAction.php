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
 * SubmitExpenseAction — calls ExpenseService::submit() directly, never
 * a hand-set `status` field. Visible only for a Draft expense belonging
 * to the acting user's own firm; gated on
 * TimeExpenseAccessPolicyService::canManageExpense() (the same ceiling
 * ExpensePolicy checks for create/edit) AND the `expenses` entitlement
 * (re-checked here, not merely at nav/resource level — matches
 * FirmIntegrationResource's Action-level discipline).
 */
class SubmitExpenseAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'submitExpense';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Submit');
        $this->icon(Heroicon::OutlinedPaperAirplane);
        $this->color('info');
        $this->requiresConfirmation();

        $this->visible(function (Expense $record): bool {
            if ($record->status !== ExpenseStatus::Draft) {
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
                        app(ExpenseService::class)->submit($firmUser->firm, $fresh);
                        Notification::make()->title('Expense submitted')->success()->send();
                    } catch (\RuntimeException $e) {
                        Notification::make()->title('Could not submit expense')->body($e->getMessage())->danger()->send();
                    }
                },
            );
        });
    }
}
