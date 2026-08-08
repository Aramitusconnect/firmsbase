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
 * MarkPaymentPlanDefaultedAction — calls
 * PaymentPlanService::markDefaulted() directly. Per that service's own
 * docblock: "Explicit, human-triggered only — never called
 * automatically purely from a missed-installment count... plan may
 * move to defaulted only under firm-confirmed rules; no automatic
 * legal-data consequences." This is the most severe transition in this
 * module, so it carries the heaviest UI friction of any Action here:
 * a REQUIRED reason (not optional, unlike Void/Cancel's reason
 * fields), plus `requiresConfirmation()` with an explicit warning
 * modal — a plain click can never trigger this.
 *
 * Visible only for an Active plan (matches the service's own guard).
 * Gated on BillingAccessPolicyService::canMarkPaymentPlanDefaulted() —
 * FirmOwner/Attorney only.
 */
class MarkPaymentPlanDefaultedAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'markPaymentPlanDefaulted';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Mark Defaulted');
        $this->icon(Heroicon::OutlinedExclamationTriangle);
        $this->color('danger');
        $this->requiresConfirmation();
        $this->modalHeading('Mark this payment plan as Defaulted?');
        $this->modalDescription('This is a severe, human-confirmed decision — it is never triggered automatically by missed installments. A reason is required and will be recorded on this plan\'s audit log.');
        $this->modalSubmitActionLabel('Mark Defaulted');

        $this->schema([
            Textarea::make('reason')->label('Reason')->required()->rows(3),
        ]);

        $this->visible(function (PaymentPlan $record): bool {
            if ($record->status !== PaymentPlanStatus::Active) {
                return false;
            }

            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || (int) $firmUser->firm_id !== (int) $record->firm_id) {
                return false;
            }

            return app(BillingAccessPolicyService::class)->canMarkPaymentPlanDefaulted($firmUser->role);
        });

        $this->action(function (array $data, PaymentPlan $record): void {
            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || ! app(BillingAccessPolicyService::class)->canMarkPaymentPlanDefaulted($firmUser->role)) {
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
                        app(PaymentPlanService::class)->markDefaulted($fresh, $firmUser->user, (string) $data['reason']);
                        Notification::make()->title('Payment plan marked defaulted')->success()->send();
                    } catch (\RuntimeException $e) {
                        Notification::make()->title('Could not mark payment plan defaulted')->body($e->getMessage())->danger()->send();
                    }
                },
            );
        });
    }
}
