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
 * ApproveAutomationActionAction — Event-Driven Automation Engine, item
 * 7/15. The ONE UI path to AutomationApprovalService::approve() —
 * "automation may not approve itself," so this is exclusively a real
 * human, Firm-Owner-gated action (AutomationApprovalService itself
 * re-checks the role; this action's own visibility check is a UX
 * convenience, never the real gate).
 */
class ApproveAutomationActionAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'approveAutomationAction';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Approve');
        $this->color('success');
        $this->icon('heroicon-o-check-circle');
        $this->modalDescription('This automated action will run once approved.');
        $this->schema([
            Textarea::make('notes')->label('Notes (optional)')->rows(2),
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
                    fn () => app(AutomationApprovalService::class)->approve(
                        Firm::query()->findOrFail($firmUser->firm_id),
                        $record,
                        $firmUser,
                        $data['notes'] ?? null,
                    ),
                );
            } catch (Throwable $e) {
                Notification::make()->title('Could not approve')->body($e->getMessage())->danger()->send();

                return;
            }

            Notification::make()->title('Action approved')->success()->send();
        });
    }
}
