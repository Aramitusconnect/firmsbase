<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\TimeEntryResource\Actions;

use App\Enums\TimeEntryStatus;
use App\Models\TimeEntry;
use App\Services\TenantContextService;
use App\Services\TimeEntryApprovalService;
use App\Services\TimeExpenseAccessPolicyService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * ApproveTimeEntryAction — calls TimeEntryApprovalService::approve()
 * directly, which snapshots the employee's CURRENT billing rate onto
 * the entry (see that service's own docblock). Gated on
 * TimeExpenseAccessPolicyService::canApproveTimeEntry() — FirmOwner/
 * Attorney only, deliberately narrower than canManageTimeEntry() (see
 * that service's own docblock for the reasoning).
 */
class ApproveTimeEntryAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'approveTimeEntry';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Approve');
        $this->icon(Heroicon::OutlinedCheckCircle);
        $this->color('success');
        $this->requiresConfirmation();

        $this->visible(function (TimeEntry $record): bool {
            if ($record->status !== TimeEntryStatus::Submitted) {
                return false;
            }

            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || (int) $firmUser->firm_id !== (int) $record->firm_id) {
                return false;
            }

            return app(TimeExpenseAccessPolicyService::class)->canApproveTimeEntry($firmUser->role);
        });

        $this->action(function (TimeEntry $record): void {
            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || ! app(TimeExpenseAccessPolicyService::class)->canApproveTimeEntry($firmUser->role)) {
                Notification::make()->title('Not permitted')->danger()->send();

                return;
            }

            app(TenantContextService::class)->runWithFirmContext(
                (int) $firmUser->firm_id,
                function () use ($record, $firmUser): void {
                    $fresh = TimeEntry::query()->where('id', $record->id)->firstOrFail();

                    if ((int) $firmUser->firm_id !== (int) $fresh->firm_id) {
                        Notification::make()->title('You do not have access to this time entry.')->danger()->send();

                        return;
                    }

                    try {
                        app(TimeEntryApprovalService::class)->approve($fresh, $firmUser->user);
                        Notification::make()->title('Time entry approved')->success()->send();
                    } catch (\RuntimeException $e) {
                        Notification::make()->title('Could not approve time entry')->body($e->getMessage())->danger()->send();
                    }
                },
            );
        });
    }
}
