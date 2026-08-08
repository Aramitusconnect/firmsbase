<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\ExpenseResource\Actions;

use App\Enums\ExpenseStatus;
use App\Models\Expense;
use App\Services\AccountingEntitlementPolicyService;
use App\Services\ExpenseApprovalService;
use App\Services\TenantContextService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * ApproveExpenseAction — calls ExpenseApprovalService::approve()
 * directly. Approver role ceiling is deliberately NOT re-derived here —
 * it defers entirely to AccountingEntitlementPolicyService::canApprove()
 * (FirmOwner, BillingStaff — Phase 12 correction #5), the single source
 * of truth for this decision (see TimeExpenseAccessPolicyService's own
 * docblock for why this ceiling is not duplicated there).
 */
class ApproveExpenseAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'approveExpense';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Approve');
        $this->icon(Heroicon::OutlinedCheckCircle);
        $this->color('success');
        $this->requiresConfirmation();

        $this->visible(function (Expense $record): bool {
            if (! in_array($record->status, [ExpenseStatus::Submitted, ExpenseStatus::Rejected], true)) {
                return false;
            }

            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || (int) $firmUser->firm_id !== (int) $record->firm_id) {
                return false;
            }

            return app(AccountingEntitlementPolicyService::class)->isExpensesEnabledForFirm($firmUser->firm)
                && app(AccountingEntitlementPolicyService::class)->canApprove($firmUser->role);
        });

        $this->action(function (Expense $record): void {
            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || ! app(AccountingEntitlementPolicyService::class)->canApprove($firmUser->role)) {
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
                        app(ExpenseApprovalService::class)->approve($firmUser->firm, $fresh, $firmUser);
                        Notification::make()->title('Expense approved')->success()->send();
                    } catch (\RuntimeException $e) {
                        Notification::make()->title('Could not approve expense')->body($e->getMessage())->danger()->send();
                    }
                },
            );
        });
    }
}
