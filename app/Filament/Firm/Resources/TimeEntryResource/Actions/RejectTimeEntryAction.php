<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\TimeEntryResource\Actions;

use App\Enums\TimeEntryStatus;
use App\Models\TimeEntry;
use App\Services\TenantContextService;
use App\Services\TimeEntryApprovalService;
use App\Services\TimeExpenseAccessPolicyService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * RejectTimeEntryAction — calls TimeEntryApprovalService::reject()
 * directly, requiring a reason (that service's own required
 * `string $reason` parameter — `rejected_reason` is never a raw
 * editable form field on TimeEntryResource itself). Same
 * canApproveTimeEntry() ceiling as ApproveTimeEntryAction — rejecting
 * is the same supervisory judgment call as approving.
 */
class RejectTimeEntryAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'rejectTimeEntry';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Reject');
        $this->icon(Heroicon::OutlinedXCircle);
        $this->color('danger');
        $this->requiresConfirmation();

        $this->schema([
            Textarea::make('rejected_reason')
                ->label('Reason')
                ->required()
                ->rows(2),
        ]);

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

        $this->action(function (TimeEntry $record, array $data): void {
            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || ! app(TimeExpenseAccessPolicyService::class)->canApproveTimeEntry($firmUser->role)) {
                Notification::make()->title('Not permitted')->danger()->send();

                return;
            }

            app(TenantContextService::class)->runWithFirmContext(
                (int) $firmUser->firm_id,
                function () use ($record, $data, $firmUser): void {
                    $fresh = TimeEntry::query()->where('id', $record->id)->firstOrFail();

                    if ((int) $firmUser->firm_id !== (int) $fresh->firm_id) {
                        Notification::make()->title('You do not have access to this time entry.')->danger()->send();

                        return;
                    }

                    try {
                        app(TimeEntryApprovalService::class)->reject($fresh, $firmUser->user, (string) $data['rejected_reason']);
                        Notification::make()->title('Time entry rejected')->success()->send();
                    } catch (\RuntimeException $e) {
                        Notification::make()->title('Could not reject time entry')->body($e->getMessage())->danger()->send();
                    }
                },
            );
        });
    }
}
