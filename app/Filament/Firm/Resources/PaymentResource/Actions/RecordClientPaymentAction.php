<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\PaymentResource\Actions;

use App\Filament\Firm\Resources\PaymentResource\Concerns\RecordsManualPayment;
use App\Filament\Firm\Resources\PaymentResource\Support\RecordPaymentFormFields;
use App\Models\Client;
use App\Services\PaymentAccessPolicyService;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * RecordClientPaymentAction — the same "Record Payment" flow as
 * RecordPaymentAction, reachable as a row action from Client context
 * (ClientResource's own table — this mission's own "a row action
 * reachable from Client/Matter context" instruction). The client field
 * is pre-filled AND locked to the row's own client (`lockClient: true`
 * — see RecordPaymentFormFields::schema()) so this can never be used
 * to record a payment against a different client than the row it was
 * opened from. Shares 100% of its submission logic with
 * RecordPaymentAction via RecordsManualPayment — no duplicated call to
 * ManualPaymentService::submit().
 */
class RecordClientPaymentAction extends Action
{
    use RecordsManualPayment;

    public static function getDefaultName(): ?string
    {
        return 'recordClientPayment';
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

        $this->schema(RecordPaymentFormFields::schema(lockClient: true));

        $this->fillForm(fn (Client $record): array => [
            'client_id' => $record->id,
        ]);

        $this->visible(function (Client $record): bool {
            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || (int) $firmUser->firm_id !== (int) $record->firm_id) {
                return false;
            }

            return app(PaymentAccessPolicyService::class)->canRecordPayment($firmUser->role);
        });

        $this->action(function (array $data): void {
            $this->recordManualPayment($data);
        });
    }
}
