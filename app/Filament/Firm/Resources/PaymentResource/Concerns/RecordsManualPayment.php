<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\PaymentResource\Concerns;

use App\Enums\PaymentClassification;
use App\Exceptions\PaymentBlockedException;
use App\Filament\Firm\Resources\PaymentResource\Support\RecordPaymentFormFields;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Matter;
use App\Models\PaymentPlanInstallment;
use App\Services\ManualPaymentService;
use App\Services\PaymentAccessPolicyService;
use App\Services\TenantContextService;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;

/**
 * RecordsManualPayment — the ONE place both RecordPaymentAction
 * (PaymentResource header action) and RecordClientPaymentAction
 * (ClientResource row action) turn submitted form data into a call to
 * ManualPaymentService::submit(). Never calls Payment::create()/
 * ManualPaymentRecord::create() directly — this is a thin adapter, all
 * real domain logic lives in ManualPaymentService/
 * PaymentClassificationService/PaymentApplicationService.
 *
 * Tenant-context discipline: this Action's closure executes through
 * Filament's shared Livewire AJAX endpoint (no ambient
 * app.current_firm_id — see WrapsRecordMutationInFirmContext's own
 * docblock for the confirmed root cause). Client/Matter/Invoice/
 * Installment are therefore resolved fresh by primary key INSIDE an
 * explicit runWithFirmContext() wrap (TOCTOU discipline, matching
 * every other Action in this panel) BEFORE calling submit() —
 * submit() establishes its OWN separate runWithFirmContext() wrap for
 * the actual write, so this resolution step is deliberately NOT nested
 * inside that same wrap (this codebase's own "decoy wrap"/double-wrap
 * avoidance convention — see ExpenseReportPage's/PaymentApplicationService's
 * own docblocks for the same reasoning).
 *
 * Classification is always hardcoded to
 * PaymentClassification::OperatingPayment here, regardless of whatever
 * value the (disabled, single-option) classification form field
 * carries — this is the real server-side enforcement of manifest rule
 * #2 ("TrustIoltaPayment must never be offered or forced"), not merely
 * the UI's disabled Select.
 */
trait RecordsManualPayment
{
    protected function recordManualPayment(array $data): void
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null || ! app(PaymentAccessPolicyService::class)->canRecordPayment($firmUser->role)) {
            Notification::make()->title('Not permitted')->body('Your role may not record payments.')->danger()->send();

            return;
        }

        $extracted = RecordPaymentFormFields::extract($data);

        /** @var array{0: ?Client, 1: ?Matter, 2: ?Invoice, 3: ?PaymentPlanInstallment}|null $resolved */
        $resolved = app(TenantContextService::class)->runWithFirmContext(
            (int) $firmUser->firm_id,
            function () use ($extracted, $firmUser): ?array {
                /** @var Client|null $client */
                $client = Client::query()->where('id', $extracted['clientId'])->first();

                if ($client === null || (int) $client->firm_id !== (int) $firmUser->firm_id) {
                    return null;
                }

                $matter = $extracted['matterId'] !== null
                    ? Matter::query()->where('id', $extracted['matterId'])->where('client_id', $client->id)->first()
                    : null;

                $invoice = $extracted['invoiceId'] !== null
                    ? Invoice::query()->where('id', $extracted['invoiceId'])->where('client_id', $client->id)->first()
                    : null;

                $installment = $extracted['installmentId'] !== null
                    ? PaymentPlanInstallment::query()
                        ->where('id', $extracted['installmentId'])
                        ->whereHas('paymentPlan', fn ($query) => $query->where('client_id', $client->id))
                        ->first()
                    : null;

                return [$client, $matter, $invoice, $installment];
            },
        );

        if ($resolved === null) {
            Notification::make()->title('Could not record payment')->body('The selected client could not be found for your firm.')->danger()->send();

            return;
        }

        [$client, $matter, $invoice, $installment] = $resolved;

        try {
            $payment = app(ManualPaymentService::class)->submit(
                firm: $firmUser->firm,
                client: $client,
                amountCents: $extracted['amountCents'],
                method: $extracted['method'],
                requestedClassification: PaymentClassification::OperatingPayment,
                idempotencyKey: $extracted['idempotencyKey'],
                matter: $matter,
                invoice: $invoice,
                installment: $installment,
                recordedBy: $firmUser->user,
                externalReference: $extracted['externalReference'],
                methodReference: $extracted['methodReference'],
                notes: $extracted['notes'],
            );
        } catch (PaymentBlockedException $e) {
            Notification::make()->title('Payment blocked')->body($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()
            ->title('Payment recorded')
            ->body('$'.number_format($payment->amount_cents / 100, 2).' recorded for '.$client->display_name.'.')
            ->success()
            ->send();
    }
}
