<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\PaymentPlanResource\Actions;

use App\Enums\PaymentPlanStatus;
use App\Models\PaymentPlan;
use App\Services\BillingAccessPolicyService;
use App\Services\PaymentPlanService;
use App\Services\TenantContextService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * ActivatePaymentPlanAction — calls PaymentPlanService::activate()
 * directly. Visible only for a Draft plan (matches that service's own
 * guard exactly). Gated on BillingAccessPolicyService::
 * canActivatePaymentPlan() — FirmOwner/Attorney only: activating LOCKS
 * the schedule (PaymentPlan's own model docblock) — the point of no
 * return before it becomes a binding, defaultable obligation.
 */
class ActivatePaymentPlanAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'activatePaymentPlan';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Activate');
        $this->icon(Heroicon::OutlinedCheckCircle);
        $this->color('success');
        $this->requiresConfirmation();
        $this->modalDescription('Activates this payment plan and locks the installment schedule. This cannot be undone directly — use Renegotiate to supersede it with a new plan.');

        $this->visible(function (PaymentPlan $record): bool {
            if ($record->status !== PaymentPlanStatus::Draft) {
                return false;
            }

            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || (int) $firmUser->firm_id !== (int) $record->firm_id) {
                return false;
            }

            return app(BillingAccessPolicyService::class)->canActivatePaymentPlan($firmUser->role);
        });

        $this->action(function (PaymentPlan $record): void {
            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || ! app(BillingAccessPolicyService::class)->canActivatePaymentPlan($firmUser->role)) {
                Notification::make()->title('Not permitted')->danger()->send();

                return;
            }

            app(TenantContextService::class)->runWithFirmContext(
                (int) $firmUser->firm_id,
                function () use ($record, $firmUser): void {
                    $fresh = PaymentPlan::query()->where('id', $record->id)->firstOrFail();

                    if ((int) $firmUser->firm_id !== (int) $fresh->firm_id) {
                        Notification::make()->title('You do not have access to this payment plan.')->danger()->send();

                        return;
                    }

                    try {
                        app(PaymentPlanService::class)->activate($fresh);
                        Notification::make()->title('Payment plan activated')->success()->send();
                    } catch (\RuntimeException $e) {
                        Notification::make()->title('Could not activate payment plan')->body($e->getMessage())->danger()->send();
                    }
                },
            );
        });
    }
}
