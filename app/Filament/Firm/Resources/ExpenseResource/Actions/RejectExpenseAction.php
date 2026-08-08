<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\ExpenseResource\Actions;

use App\Enums\ExpenseStatus;
use App\Models\Expense;
use App\Services\AccountingEntitlementPolicyService;
use App\Services\ExpenseApprovalService;
use App\Services\TenantContextService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * RejectExpenseAction — calls ExpenseApprovalService::reject()
 * directly, requiring a reason (that service's own required
 * `string $reason` parameter). Same AccountingEntitlementPolicyService::
 * canApprove() ceiling as ApproveExpenseAction.
 */
class RejectExpenseAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'rejectExpense';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Reject');
        $this->icon(Heroicon::OutlinedXCircle);
        $this->color('danger');
        $this->requiresConfirmation();

        $this->schema([
            Textarea::make('reason')
                ->label('Reason')
                ->required()
                ->rows(2),
        ]);

        $this->visible(function (Expense $record): bool {
            if ($record->status !== ExpenseStatus::Submitted) {
                return false;
            }

            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || (int) $firmUser->firm_id !== (int) $record->firm_id) {
                return false;
            }

            return app(AccountingEntitlementPolicyService::class)->isExpensesEnabledForFirm($firmUser->firm)
                && app(AccountingEntitlementPolicyService::class)->canApprove($firmUser->role);
        });

        $this->action(function (Expense $record, array $data): void {
            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || ! app(AccountingEntitlementPolicyService::class)->canApprove($firmUser->role)) {
                Notification::make()->title('Not permitted')->danger()->send();

                return;
            }

            app(TenantContextService::class)->runWithFirmContext(
                (int) $firmUser->firm_id,
                function () use ($record, $data, $firmUser): void {
                    $fresh = Expense::query()->where('id', $record->id)->firstOrFail();

                    if ((int) $firmUser->firm_id !== (int) $fresh->firm_id) {
                        Notification::make()->title('You do not have access to this expense.')->danger()->send();

                        return;
                    }

                    try {
                        app(ExpenseApprovalService::class)->reject($firmUser->firm, $fresh, $firmUser, (string) $data['reason']);
                        Notification::make()->title('Expense rejected')->success()->send();
                    } catch (\RuntimeException $e) {
                        Notification::make()->title('Could not reject expense')->body($e->getMessage())->danger()->send();
                    }
                },
            );
        });
    }
}
