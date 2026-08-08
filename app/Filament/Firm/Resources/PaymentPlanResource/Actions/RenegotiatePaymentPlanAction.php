<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\PaymentPlanResource\Actions;

use App\Enums\PaymentPlanStatus;
use App\Filament\Firm\Resources\PaymentPlanResource;
use App\Models\PaymentPlan;
use App\Services\BillingAccessPolicyService;
use App\Services\PaymentPlanService;
use App\Services\TenantContextService;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * RenegotiatePaymentPlanAction — calls PaymentPlanService::renegotiate()
 * directly. Per that service's own docblock: "Creates a new plan
 * version superseding $plan. The old plan transitions to Renegotiated"
 * — this is NEVER an in-place edit of the existing plan's installments.
 * The UI makes that fact impossible to miss two ways:
 *
 *   1. The success notification explicitly names the NEW plan's id
 *      ("Payment plan renegotiated — new Plan #N created, superseding
 *      Plan #M"), never a generic "Payment plan updated".
 *   2. `$this->redirect()` navigates the browser to the brand new
 *      plan's own ViewPaymentPlan page — the user lands on Plan #N,
 *      not back on the now-Renegotiated Plan #M (mirrors
 *      ConvertLeadToClientAction's/AddClientAction's own "redirect to
 *      the newly created record" precedent).
 *
 * Visible only for an Active plan (matches the service's own guard).
 * Gated on BillingAccessPolicyService::canRenegotiatePaymentPlan() —
 * FirmOwner/Attorney only, the same narrow ceiling as Activate/Cancel/
 * MarkDefaulted: renegotiating supersedes a binding schedule with a
 * brand new one.
 */
class RenegotiatePaymentPlanAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'renegotiatePaymentPlan';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Renegotiate');
        $this->modalHeading('Renegotiate Payment Plan');
        $this->modalDescription('This does NOT edit the current plan. It creates a brand new payment plan with the installments below, marks this plan as Renegotiated, and takes you to the new plan.');
        $this->modalSubmitActionLabel('Create New Plan');
        $this->modalWidth('2xl');
        $this->icon(Heroicon::OutlinedArrowPathRoundedSquare);
        $this->color('warning');

        $this->schema([
            // ->defaultItems(0) — see CreatePaymentPlanAction's identical
            // Repeater for why this overrides Filament's own built-in
            // default of 1.
            Repeater::make('installments')
                ->label('New Installments')
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

        $this->visible(function (PaymentPlan $record): bool {
            if ($record->status !== PaymentPlanStatus::Active) {
                return false;
            }

            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || (int) $firmUser->firm_id !== (int) $record->firm_id) {
                return false;
            }

            return app(BillingAccessPolicyService::class)->canRenegotiatePaymentPlan($firmUser->role);
        });

        $this->action(function (array $data, PaymentPlan $record): void {
            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || ! app(BillingAccessPolicyService::class)->canRenegotiatePaymentPlan($firmUser->role)) {
                Notification::make()->title('Not permitted')->danger()->send();

                return;
            }

            $newPlan = app(TenantContextService::class)->runWithFirmContext(
                (int) $firmUser->firm_id,
                function () use ($record, $firmUser, $data) {
                    $fresh = PaymentPlan::query()->where('id', $record->id)->first();

                    if ($fresh === null || (int) $firmUser->firm_id !== (int) $fresh->firm_id) {
                        return null;
                    }

                    $installments = collect($data['installments'] ?? [])
                        ->map(fn (array $row): array => [
                            'amount_cents' => (int) round(((float) $row['amount']) * 100),
                            'due_at' => Carbon::parse($row['due_at']),
                        ])
                        ->all();

                    try {
                        return app(PaymentPlanService::class)->renegotiate($fresh, $installments, $firmUser->user);
                    } catch (\RuntimeException|\InvalidArgumentException $e) {
                        report($e);

                        return null;
                    }
                },
            );

            if ($newPlan === null) {
                Notification::make()->title('Could not renegotiate payment plan')->danger()->send();

                return;
            }

            Notification::make()
                ->title('Payment plan renegotiated')
                ->body("New Plan #{$newPlan->id} created, superseding Plan #{$record->id}. The old plan is now marked Renegotiated.")
                ->success()
                ->send();

            $this->redirect(PaymentPlanResource::getUrl('view', ['record' => $newPlan]));
        });
    }
}
