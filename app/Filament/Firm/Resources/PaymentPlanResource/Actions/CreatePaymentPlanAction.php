<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\PaymentPlanResource\Actions;

use App\Enums\InvoiceStatus;
use App\Filament\Firm\Resources\PaymentPlanResource;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Matter;
use App\Services\BillingAccessPolicyService;
use App\Services\PaymentPlanService;
use App\Services\TenantContextService;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * CreatePaymentPlanAction — the "+ Create Payment Plan" header action
 * on ListPaymentPlans, wired directly to PaymentPlanService::create()
 * — never a bare `PaymentPlan::create()`. `total_cents`/
 * `installment_count` are never form fields — PaymentPlanService
 * recomputes both from the submitted installments internally. The
 * created plan starts in Draft (still fully editable/undoable) —
 * ActivatePaymentPlanAction is the separate, narrower-ceiling Action
 * that locks it.
 */
class CreatePaymentPlanAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'createPaymentPlan';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('+ Create Payment Plan');
        $this->modalHeading('Create Payment Plan');
        $this->modalDescription('Creates a new draft payment plan (a schedule, not a parallel ledger). It stays editable until it is activated.');
        $this->modalSubmitActionLabel('Create Plan');
        $this->modalWidth('2xl');
        $this->icon(Heroicon::OutlinedCalendarDateRange);
        $this->color('primary');

        $this->schema([
            Select::make('client_id')
                ->label('Client')
                ->options(fn (): array => self::firmScoped(fn () => Client::query()->orderBy('display_name')->pluck('display_name', 'id')->all()))
                ->searchable()
                ->required()
                ->live(),

            Select::make('matter_id')
                ->label('Matter (optional)')
                ->options(fn (Get $get): array => filled($get('client_id'))
                    ? self::firmScoped(fn () => Matter::query()
                        ->where('client_id', $get('client_id'))
                        ->get()
                        ->mapWithKeys(fn (Matter $matter): array => [$matter->id => $matter->stage ?? "Matter #{$matter->id}"])
                        ->all())
                    : [])
                ->searchable()
                ->nullable()
                ->helperText('Only matters belonging to the selected client are shown.'),

            Select::make('invoice_id')
                ->label('Invoice (optional)')
                ->options(fn (Get $get): array => filled($get('client_id'))
                    ? self::firmScoped(fn () => Invoice::query()
                        ->where('client_id', $get('client_id'))
                        ->whereIn('status', [InvoiceStatus::Sent, InvoiceStatus::Approved, InvoiceStatus::PartiallyPaid])
                        ->get()
                        ->mapWithKeys(fn (Invoice $invoice): array => [$invoice->id => "Invoice #{$invoice->id} — ".number_format($invoice->total_cents / 100, 2)])
                        ->all())
                    : [])
                ->searchable()
                ->nullable()
                ->helperText('Only this client\'s open invoices (Sent/Approved/Partially Paid) are shown.'),

            // ->defaultItems(0) deliberately overrides Repeater's own
            // built-in default of 1 (Filament\Forms\Components\Repeater's
            // own setUp() calls $this->defaultItems(1) unconditionally) —
            // a pre-seeded, empty, UUID-keyed row mounted inside a
            // Filament Action's schema is never fully replaceable by a
            // sequential-array submission afterward (Filament's own
            // fillForm() test helper only prunes stale array-list
            // siblings, not a stray UUID key), which would otherwise leave
            // a phantom empty installment causing spurious required-field
            // validation errors. minItems(1) + required() below is
            // unaffected — the user must still click "+ Add Installment"
            // at least once before submitting.
            Repeater::make('installments')
                ->label('Installments')
                ->schema([
                    TextInput::make('amount')->label('Amount')->numeric()->minValue(0.01)->prefix('$')->required(),
                    DatePicker::make('due_at')->label('Due Date')->native(false)->required(),
                ])
                ->columns(2)
                ->minItems(1)
                ->required()
                ->addActionLabel('+ Add Installment')
                ->defaultItems(0),
        ]);

        $this->visible(function (): bool {
            $firmUser = Auth::user()?->activeFirmUser();

            return $firmUser !== null && app(BillingAccessPolicyService::class)->canCreatePaymentPlan($firmUser->role);
        });

        $this->action(function (array $data): void {
            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || ! app(BillingAccessPolicyService::class)->canCreatePaymentPlan($firmUser->role)) {
                Notification::make()->title('Not permitted')->body('Your role may not create payment plans.')->danger()->send();

                return;
            }

            $plan = app(TenantContextService::class)->runWithFirmContext(
                (int) $firmUser->firm_id,
                function () use ($data, $firmUser) {
                    $client = Client::query()->where('id', $data['client_id'])->first();

                    if ($client === null || (int) $client->firm_id !== (int) $firmUser->firm_id) {
                        return null;
                    }

                    $matter = filled($data['matter_id'] ?? null)
                        ? Matter::query()->where('id', $data['matter_id'])->where('client_id', $client->id)->first()
                        : null;

                    $invoice = filled($data['invoice_id'] ?? null)
                        ? Invoice::query()->where('id', $data['invoice_id'])->where('client_id', $client->id)->first()
                        : null;

                    $installments = collect($data['installments'] ?? [])
                        ->map(fn (array $row): array => [
                            'amount_cents' => (int) round(((float) $row['amount']) * 100),
                            'due_at' => Carbon::parse($row['due_at']),
                        ])
                        ->all();

                    return app(PaymentPlanService::class)->create(
                        firm: $firmUser->firm,
                        client: $client,
                        installments: $installments,
                        matter: $matter,
                        invoice: $invoice,
                        createdBy: $firmUser->user,
                    );
                },
            );

            if ($plan === null) {
                Notification::make()->title('Could not create payment plan')->body('The selected client could not be found for your firm.')->danger()->send();

                return;
            }

            Notification::make()->title('Payment plan created')->success()->send();

            $this->redirect(PaymentPlanResource::getUrl('view', ['record' => $plan]));
        });
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private static function firmScoped(callable $callback)
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null) {
            return [];
        }

        return app(TenantContextService::class)->runWithFirmContext((int) $firmUser->firm_id, $callback);
    }
}
