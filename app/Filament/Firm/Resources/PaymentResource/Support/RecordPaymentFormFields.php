<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\PaymentResource\Support;

use App\Enums\InvoiceStatus;
use App\Enums\ManualPaymentMethod;
use App\Enums\PaymentPlanInstallmentStatus;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Matter;
use App\Models\PaymentPlanInstallment;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Str;

/**
 * RecordPaymentFormFields — shared "Record Payment" form schema reused
 * by both RecordPaymentAction (PaymentResource's header action, no
 * preselected client) and RecordClientPaymentAction (ClientResource's
 * row action, client locked to the row). Mirrors
 * ClientConversionFormFields's own "a shared conversion/record form
 * component is fine" precedent (see ConvertLeadToClientAction's/
 * AddClientAction's docblocks).
 *
 * Domain safety rules baked into this schema (Firm Feature Manifest
 * §6, cross-cutting finding #11):
 *
 *   1. Idempotency: `idempotency_key` is a Hidden field whose
 *      `->default()` closure runs exactly once per form fill (Filament
 *      evaluates a field default closure once, not on every re-render
 *      — confirmed precedent: ProvisionFirmAction's own identical
 *      comment). A double-click/resubmit within the same mounted
 *      action always carries the same key, so
 *      ManualPaymentService::submit()'s own idempotent-replay check
 *      (unique on firm_id+idempotency_key) can never create a second
 *      Payment row for one submission.
 *
 *   2. Classification is NEVER a real user choice: the `classification`
 *      field only ever offers "Operating Payment" (the one
 *      classification PaymentClassificationService actually accepts
 *      today — confirmed by direct source read).
 *      `trust_iolta_payment`/`blocked_payment` are never rendered as
 *      options. The submitted value of this field is also never
 *      trusted server-side — both Record actions hardcode
 *      `PaymentClassification::OperatingPayment` when calling
 *      `submit()` regardless of what this field carries, so tampering
 *      with the disabled field client-side cannot force a different
 *      classification through.
 *
 *   3. Copy never claims a real payment processor was involved — no
 *      "Charge Card"/"Process Payment" language anywhere in this
 *      schema or its labels/helper text; `ManualPaymentMethod` options
 *      are presented plainly as manually-recorded methods.
 *
 *   4. Matter/Invoice/Installment selects are tenant-safe (plain
 *      Eloquent queries evaluated inside the action's own
 *      runWithFirmContext() wrap — see RecordPaymentAction/
 *      RecordClientPaymentAction) and are scoped to the selected
 *      client — an invoice/installment belonging to a different client
 *      can never be chosen once a client is selected. Invoice options
 *      are additionally restricted to statuses
 *      PaymentApplicationService::applyToInvoice() actually accepts
 *      (Sent/Approved/PartiallyPaid) and installment options to
 *      statuses that are not already fully resolved
 *      (Scheduled/Due/PartiallyPaid/Missed) — this is a UX narrowing
 *      only; PaymentApplicationService's own guards remain the real
 *      boundary.
 */
class RecordPaymentFormFields
{
    /**
     * @return array<int, Component>
     */
    public static function schema(bool $lockClient = false): array
    {
        return [
            Hidden::make('idempotency_key')
                ->default(fn (): string => (string) Str::uuid())
                ->dehydrated(),

            Section::make('Payment')
                ->columns(2)
                ->schema([
                    Select::make('client_id')
                        ->label('Client')
                        ->options(fn (): array => Client::query()->orderBy('display_name')->pluck('display_name', 'id')->all())
                        ->searchable()
                        ->required()
                        ->live()
                        ->disabled($lockClient)
                        ->dehydrated(),

                    Select::make('matter_id')
                        ->label('Matter (optional)')
                        ->options(fn (Get $get): array => filled($get('client_id'))
                            ? Matter::query()
                                ->where('client_id', $get('client_id'))
                                ->get()
                                ->mapWithKeys(fn (Matter $matter): array => [$matter->id => $matter->stage ?? "Matter #{$matter->id}"])
                                ->all()
                            : [])
                        ->searchable()
                        ->nullable()
                        ->helperText('Only matters belonging to the selected client are shown.'),

                    Select::make('invoice_id')
                        ->label('Invoice (optional)')
                        ->options(fn (Get $get): array => filled($get('client_id'))
                            ? Invoice::query()
                                ->where('client_id', $get('client_id'))
                                ->whereIn('status', [InvoiceStatus::Sent, InvoiceStatus::Approved, InvoiceStatus::PartiallyPaid])
                                ->get()
                                ->mapWithKeys(fn (Invoice $invoice): array => [$invoice->id => "Invoice #{$invoice->id} — ".number_format($invoice->total_cents / 100, 2)])
                                ->all()
                            : [])
                        ->searchable()
                        ->nullable()
                        ->helperText('Only this client\'s open invoices (Sent/Approved/Partially Paid) are shown.'),

                    Select::make('payment_plan_installment_id')
                        ->label('Payment Plan Installment (optional)')
                        ->options(fn (Get $get): array => filled($get('client_id'))
                            ? PaymentPlanInstallment::query()
                                ->whereHas('paymentPlan', fn ($query) => $query->where('client_id', $get('client_id')))
                                ->whereIn('status', [
                                    PaymentPlanInstallmentStatus::Scheduled,
                                    PaymentPlanInstallmentStatus::Due,
                                    PaymentPlanInstallmentStatus::PartiallyPaid,
                                    PaymentPlanInstallmentStatus::Missed,
                                ])
                                ->get()
                                ->mapWithKeys(fn (PaymentPlanInstallment $installment): array => [$installment->id => "Installment #{$installment->sequence} — ".number_format($installment->amount_cents / 100, 2)])
                                ->all()
                            : [])
                        ->searchable()
                        ->nullable()
                        ->helperText('Only this client\'s unpaid/partially paid installments are shown.'),

                    TextInput::make('amount')
                        ->label('Amount')
                        ->numeric()
                        ->minValue(0.01)
                        ->prefix('$')
                        ->required(),

                    Select::make('method')
                        ->label('Payment Method')
                        ->options(collect(ManualPaymentMethod::cases())->mapWithKeys(
                            fn (ManualPaymentMethod $method): array => [$method->value => str($method->value)->headline()]
                        )->all())
                        ->required()
                        ->native(false)
                        ->helperText('This records a payment already received outside this system — it never charges a card or processor.'),

                    Select::make('classification')
                        ->label('Classification')
                        ->options(['operating_payment' => 'Operating Payment'])
                        ->default('operating_payment')
                        ->required()
                        ->disabled()
                        ->dehydrated()
                        ->helperText('Trust/IOLTA payment recording is not available until the Trust module ships — every payment recorded here is an operating payment.'),

                    TextInput::make('external_reference')
                        ->label('External Reference')
                        ->maxLength(255)
                        ->helperText('E.g. a check number or bank transfer reference.'),

                    TextInput::make('method_reference')
                        ->label('Method Reference')
                        ->maxLength(255),

                    Textarea::make('notes')
                        ->rows(3)
                        ->columnSpanFull(),
                ]),
        ];
    }

    /**
     * @return array{clientId: int, matterId: ?int, invoiceId: ?int, installmentId: ?int, amountCents: int, method: ManualPaymentMethod, externalReference: ?string, methodReference: ?string, notes: ?string, idempotencyKey: string}
     */
    public static function extract(array $data): array
    {
        return [
            'clientId' => (int) $data['client_id'],
            'matterId' => filled($data['matter_id'] ?? null) ? (int) $data['matter_id'] : null,
            'invoiceId' => filled($data['invoice_id'] ?? null) ? (int) $data['invoice_id'] : null,
            'installmentId' => filled($data['payment_plan_installment_id'] ?? null) ? (int) $data['payment_plan_installment_id'] : null,
            'amountCents' => (int) round(((float) $data['amount']) * 100),
            'method' => ManualPaymentMethod::from($data['method']),
            'externalReference' => filled($data['external_reference'] ?? null) ? (string) $data['external_reference'] : null,
            'methodReference' => filled($data['method_reference'] ?? null) ? (string) $data['method_reference'] : null,
            'notes' => filled($data['notes'] ?? null) ? (string) $data['notes'] : null,
            'idempotencyKey' => (string) $data['idempotency_key'],
        ];
    }
}
