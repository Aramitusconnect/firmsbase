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
 * SubmitTimeEntryAction — calls TimeEntryApprovalService::submit()
 * directly, never a hand-set `status` field. Visible only for a Draft
 * (or previously Rejected) entry belonging to the acting user's own
 * firm; gated on TimeExpenseAccessPolicyService::canManageTimeEntry(),
 * the same ceiling TimeEntryPolicy checks for create/edit. TOCTOU +
 * tenant-context discipline mirrors StartTaskAction/CompleteDeadlineAction
 * exactly: re-fetches the entry fresh by primary key INSIDE
 * runWithFirmContext() before calling the service.
 */
class SubmitTimeEntryAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'submitTimeEntry';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Submit');
        $this->icon(Heroicon::OutlinedPaperAirplane);
        $this->color('info');
        $this->requiresConfirmation();

        $this->visible(function (TimeEntry $record): bool {
            if (! in_array($record->status, [TimeEntryStatus::Draft, TimeEntryStatus::Rejected], true)) {
                return false;
            }

            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || (int) $firmUser->firm_id !== (int) $record->firm_id) {
                return false;
            }

            return app(TimeExpenseAccessPolicyService::class)->canManageTimeEntry($firmUser->role);
        });

        $this->action(function (TimeEntry $record): void {
            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || ! app(TimeExpenseAccessPolicyService::class)->canManageTimeEntry($firmUser->role)) {
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
                        app(TimeEntryApprovalService::class)->submit($fresh);
                        Notification::make()->title('Time entry submitted')->success()->send();
                    } catch (\RuntimeException $e) {
                        Notification::make()->title('Could not submit time entry')->body($e->getMessage())->danger()->send();
                    }
                },
            );
        });
    }
}
