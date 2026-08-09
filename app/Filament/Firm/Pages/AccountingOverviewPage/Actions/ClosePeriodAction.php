<?php

declare(strict_types=1);

namespace App\Filament\Firm\Pages\AccountingOverviewPage\Actions;

use App\Services\AccountingEntitlementPolicyService;
use App\Services\AccountingPeriodCloseService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * ClosePeriodAction — "Close Period" header action on
 * AccountingOverviewPage, wired directly to
 * AccountingPeriodCloseService::close(). The only money/state-changing
 * operation this page exposes; every figure it snapshots comes from
 * the already-canonical AccountingReportingService/AccountingBalanceService/
 * TrustBalanceService — this Action decides WHEN to close, never
 * computes a balance itself.
 */
class ClosePeriodAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'closePeriod';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Close Period');
        $this->modalHeading('Close an Accounting Period');
        $this->modalDescription('Snapshots opening/closing balances, AR aging, and trust liability, then blocks new journal postings dated inside this period. Reopen the period explicitly if a correction is later required.');
        $this->modalSubmitActionLabel('Close');
        $this->icon(Heroicon::OutlinedLockClosed);
        $this->color('warning');
        $this->requiresConfirmation(false);

        $this->schema([
            DatePicker::make('period_start')->label('Period Start')->native(false)->required(),
            DatePicker::make('period_end')->label('Period End')->native(false)->required(),
        ]);

        $this->visible(fn (): bool => self::isFirmAccountingApprover());

        $this->action(function (array $data): void {
            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || ! self::isFirmAccountingApprover()) {
                Notification::make()->title('Not permitted')->danger()->send();

                return;
            }

            try {
                app(AccountingPeriodCloseService::class)->close(
                    $firmUser->firm,
                    Carbon::parse($data['period_start']),
                    Carbon::parse($data['period_end']),
                    $firmUser,
                );
                Notification::make()->title('Period closed')->success()->send();
            } catch (\RuntimeException $e) {
                Notification::make()->title('Could not close period')->body($e->getMessage())->danger()->send();
            }
        });
    }

    private static function isFirmAccountingApprover(): bool
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null) {
            return false;
        }

        return app(AccountingEntitlementPolicyService::class)->canApprove($firmUser->role);
    }
}
