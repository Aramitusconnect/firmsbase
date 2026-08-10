<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\AutomationActionExecutionResource\Actions;

use App\Models\AutomationActionExecution;
use App\Models\Firm;
use App\Services\Automation\AutomationApprovalService;
use App\Services\TenantContextService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * RejectAutomationActionAction — Event-Driven Automation Engine, item
 * 7/15. The ONE UI path to AutomationApprovalService::reject() —
 * terminal, never retried, never re-submitted automatically.
 */
class RejectAutomationActionAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'rejectAutomationAction';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Reject');
        $this->color('danger');
        $this->icon('heroicon-o-x-circle');
        $this->requiresConfirmation();
        $this->schema([
            Textarea::make('reason')->label('Reason')->required()->rows(2),
        ]);

        $this->visible(fn (AutomationActionExecution $record): bool => $record->isAwaitingApproval());

        $this->action(function (AutomationActionExecution $record, array $data): void {
            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null) {
                Notification::make()->title('Not permitted')->danger()->send();

                return;
            }

            try {
                app(TenantContextService::class)->runWithFirmContext(
                    (int) $firmUser->firm_id,
                    fn () => app(AutomationApprovalService::class)->reject(
                        Firm::query()->findOrFail($firmUser->firm_id),
                        $record,
                        $firmUser,
                        $data['reason'],
                    ),
                );
            } catch (Throwable $e) {
                Notification::make()->title('Could not reject')->body($e->getMessage())->danger()->send();

                return;
            }

            Notification::make()->title('Action rejected')->success()->send();
        });
    }
}
