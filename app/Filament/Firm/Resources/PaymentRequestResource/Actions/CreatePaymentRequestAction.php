<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\PaymentRequestResource\Actions;

use App\Enums\PaymentRequestAmountRule;
use App\Enums\PaymentRequestPurpose;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Matter;
use App\Models\PaymentPlanInstallment;
use App\Services\PaymentRequestAccessPolicyService;
use App\Services\PaymentRequestService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Throwable;

/**
 * CreatePaymentRequestAction — the "+ Create Payment Request" header
 * action on ListPaymentRequests (master prompt item 12/17). Mirrors
 * InviteFirmUserAction's modal-form-Action shape (never a generic
 * Filament CreateRecord page bound to PaymentRequest — this is a
 * financial-adjacent entry-channel model, same "never a raw editable
 * form on a real domain write" discipline as Payment/Expense).
 *
 * Wired directly to PaymentRequestService::create() — never a bare
 * PaymentRequest::create(). Always creates in Draft; a separate
 * ActivatePaymentRequestAction is required before the link/QR code is
 * payable, matching PaymentRequestService::activate()'s own Draft-only
 * guard.
 */
class CreatePaymentRequestAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'createPaymentRequest';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('+ Create Payment Request');
        $this->icon(Heroicon::OutlinedLink);
        $this->color('primary');
        $this->modalHeading('Create Payment Request');
        $this->modalDescription('Creates a Draft request. Activate it afterward to generate a payable link and QR code.');
        $this->modalSubmitActionLabel('Create');
        $this->modalWidth('lg');

        $this->schema([
            Select::make('client_id')
                ->label('Client')
                ->options(fn (): array => Client::query()->orderBy('display_name')->pluck('display_name', 'id')->all())
                ->searchable()
                ->required()
                ->live(),
            Select::make('matter_id')
                ->label('Matter (optional)')
                ->options(fn (Get $get): array => Matter::query()
                    ->when($get('client_id'), fn ($q, $clientId) => $q->where('client_id', $clientId))
                    ->get()
                    ->mapWithKeys(fn (Matter $matter): array => [$matter->id => "Matter #{$matter->id}"])
                    ->all())
                ->searchable()
                ->nullable(),
            Select::make('purpose')
                ->label('Purpose')
                ->options(collect(PaymentRequestPurpose::cases())->mapWithKeys(fn (PaymentRequestPurpose $case): array => [$case->value => Str::headline($case->value)])->all())
                ->required()
                ->live()
                ->native(false),
            Select::make('invoice_id')
                ->label('Invoice (optional)')
                ->options(fn (Get $get): array => Invoice::query()
                    ->when($get('client_id'), fn ($q, $clientId) => $q->where('client_id', $clientId))
                    ->get()
                    ->mapWithKeys(fn (Invoice $invoice): array => [$invoice->id => "Invoice #{$invoice->id}"])
                    ->all())
                ->searchable()
                ->nullable()
                ->visible(fn (Get $get): bool => $get('purpose') !== PaymentRequestPurpose::PaymentPlanInstallment->value),
            Select::make('payment_plan_installment_id')
                ->label('Installment')
                ->options(fn (Get $get): array => PaymentPlanInstallment::query()
                    ->whereHas('paymentPlan', fn ($q) => $q->when($get('client_id'), fn ($qq, $clientId) => $qq->where('client_id', $clientId)))
                    ->with('paymentPlan')
                    ->get()
                    ->mapWithKeys(fn (PaymentPlanInstallment $installment): array => [
                        $installment->id => "Plan #{$installment->payment_plan_id} — Installment #{$installment->sequence}",
                    ])
                    ->all())
                ->searchable()
                ->requiredIf('purpose', PaymentRequestPurpose::PaymentPlanInstallment->value)
                ->visible(fn (Get $get): bool => $get('purpose') === PaymentRequestPurpose::PaymentPlanInstallment->value),
            Select::make('amount_rule')
                ->label('Amount Rule')
                ->options(collect(PaymentRequestAmountRule::cases())->mapWithKeys(fn (PaymentRequestAmountRule $case): array => [$case->value => Str::headline($case->value)])->all())
                ->required()
                ->live()
                ->native(false),
            TextInput::make('requested_amount_dollars')
                ->label('Fixed Amount (USD)')
                ->numeric()
                ->minValue(0.01)
                ->prefix('$')
                ->requiredIf('amount_rule', PaymentRequestAmountRule::Fixed->value)
                ->visible(fn (Get $get): bool => $get('amount_rule') === PaymentRequestAmountRule::Fixed->value),
            DatePicker::make('expires_at')
                ->label('Expires (optional — defaults to 30 days after activation)')
                ->native(false)
                ->minDate(now()),
        ]);

        $this->visible(function (): bool {
            $firmUser = Auth::user()?->activeFirmUser();

            return $firmUser !== null && app(PaymentRequestAccessPolicyService::class)->canManagePaymentRequest($firmUser->role);
        });

        $this->action(function (array $data): void {
            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || ! app(PaymentRequestAccessPolicyService::class)->canManagePaymentRequest($firmUser->role)) {
                Notification::make()->title('Not permitted')->danger()->send();

                return;
            }

            try {
                $client = Client::query()->where('firm_id', $firmUser->firm_id)->findOrFail($data['client_id']);
                $matter = filled($data['matter_id'] ?? null) ? Matter::query()->where('firm_id', $firmUser->firm_id)->find($data['matter_id']) : null;
                $invoice = filled($data['invoice_id'] ?? null) ? Invoice::query()->where('firm_id', $firmUser->firm_id)->find($data['invoice_id']) : null;
                $installment = filled($data['payment_plan_installment_id'] ?? null)
                    ? PaymentPlanInstallment::query()->whereHas('paymentPlan', fn ($q) => $q->where('firm_id', $firmUser->firm_id))->find($data['payment_plan_installment_id'])
                    : null;

                $requestedAmountCents = filled($data['requested_amount_dollars'] ?? null)
                    ? (int) round(((float) $data['requested_amount_dollars']) * 100)
                    : null;

                app(PaymentRequestService::class)->create(
                    $firmUser->firm,
                    $client,
                    PaymentRequestPurpose::from($data['purpose']),
                    PaymentRequestAmountRule::from($data['amount_rule']),
                    $firmUser,
                    $requestedAmountCents,
                    $matter,
                    $invoice,
                    $installment,
                    filled($data['expires_at'] ?? null) ? Carbon::parse($data['expires_at'])->endOfDay() : null,
                );
            } catch (Throwable $e) {
                Notification::make()->title('Could not create payment request')->body($e->getMessage())->danger()->send();

                return;
            }

            Notification::make()->title('Payment request created as Draft')->success()->send();
        });
    }
}
