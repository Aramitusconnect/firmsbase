<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\PaymentResource\Actions;

use App\Filament\Firm\Resources\PaymentResource\Concerns\RecordsManualPayment;
use App\Filament\Firm\Resources\PaymentResource\Support\RecordPaymentFormFields;
use App\Services\PaymentAccessPolicyService;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * RecordPaymentAction — the prominent "Record Payment" header action
 * on PaymentResource's list page (Firm Feature Manifest §6,
 * cross-cutting finding #11). No preselected client — the acting user
 * picks any of the firm's own clients. Wired directly to
 * ManualPaymentService::submit() via RecordsManualPayment; never a
 * bare `Payment::create()`. Copy is deliberately "Record Payment" /
 * "External Payment Recorded" throughout — never "Charge Card"/
 * "Process Payment" (manifest rule #1: this never claims a real
 * Stripe/LawPay processor charge happened).
 */
class RecordPaymentAction extends Action
{
    use RecordsManualPayment;

    public static function getDefaultName(): ?string
    {
        return 'recordPayment';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Record Payment');
        $this->modalHeading('Record an externally received payment');
        $this->modalDescription('This records a payment already received outside this system (cash, check, bank transfer, or manually-entered card) — it never charges a card or contacts a payment processor.');
        $this->modalSubmitActionLabel('Record Payment');
        $this->modalWidth('xl');
        $this->icon(Heroicon::OutlinedBanknotes);
        $this->color('primary');

        $this->schema(RecordPaymentFormFields::schema());

        $this->visible(function (): bool {
            $firmUser = Auth::user()?->activeFirmUser();

            return $firmUser !== null && app(PaymentAccessPolicyService::class)->canRecordPayment($firmUser->role);
        });

        $this->action(function (array $data): void {
            $this->recordManualPayment($data);
        });
    }
}
