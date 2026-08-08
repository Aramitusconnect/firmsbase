<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\PaymentPlanResource\Actions;

use App\Enums\PaymentPlanStatus;
use App\Models\PaymentPlan;
use App\Services\BillingAccessPolicyService;
use App\Services\PaymentPlanService;
use App\Services\TenantContextService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * CancelPaymentPlanAction — calls PaymentPlanService::cancel()
 * directly. Visible for any status except Completed/Cancelled (matches
 * that service's own guard exactly). Gated on
 * BillingAccessPolicyService::canCancelPaymentPlan() — FirmOwner/
 * Attorney only, the same narrow ceiling as Activate/Renegotiate/
 * MarkDefaulted: terminating a plan (which may already be a binding,
 * Active schedule) carries the same financial-liability weight as
 * voiding an invoice.
 */
class CancelPaymentPlanAction extends Action
{
    private const BLOCKED_STATUSES = [
        PaymentPlanStatus::Completed,
        PaymentPlanStatus::Cancelled,
    ];

    public static function getDefaultName(): ?string
    {
        return 'cancelPaymentPlan';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Cancel');
        $this->icon(Heroicon::OutlinedXCircle);
        $this->color('danger');
        $this->requiresConfirmation();
        $this->modalDescription('Cancels this payment plan. This cannot be undone through this UI.');
        $this->modalSubmitActionLabel('Cancel Plan');

        $this->schema([
            Textarea::make('reason')->label('Reason (optional)')->rows(2),
        ]);

        $this->visible(function (PaymentPlan $record): bool {
            if (in_array($record->status, self::BLOCKED_STATUSES, true)) {
                return false;
            }

            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || (int) $firmUser->firm_id !== (int) $record->firm_id) {
                return false;
            }

            return app(BillingAccessPolicyService::class)->canCancelPaymentPlan($firmUser->role);
        });

        $this->action(function (array $data, PaymentPlan $record): void {
            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || ! app(BillingAccessPolicyService::class)->canCancelPaymentPlan($firmUser->role)) {
                Notification::make()->title('Not permitted')->danger()->send();

                return;
            }

            app(TenantContextService::class)->runWithFirmContext(
                (int) $firmUser->firm_id,
                function () use ($record, $firmUser, $data): void {
                    $fresh = PaymentPlan::query()->where('id', $record->id)->firstOrFail();

                    if ((int) $firmUser->firm_id !== (int) $fresh->firm_id) {
                        Notification::make()->title('You do not have access to this payment plan.')->danger()->send();

                        return;
                    }

                    try {
                        app(PaymentPlanService::class)->cancel($fresh, $firmUser->user, filled($data['reason'] ?? null) ? (string) $data['reason'] : null);
                        Notification::make()->title('Payment plan cancelled')->success()->send();
                    } catch (\RuntimeException $e) {
                        Notification::make()->title('Could not cancel payment plan')->body($e->getMessage())->danger()->send();
                    }
                },
            );
        });
    }
}
