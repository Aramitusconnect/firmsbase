<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\PaymentRequestResource\Actions;

use App\Enums\PaymentRequestStatus;
use App\Models\PaymentRequest;
use App\Services\PaymentRequestAccessPolicyService;
use App\Services\PaymentRequestService;
use App\Services\TenantContextService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * ActivatePaymentRequestAction — Draft -> Active only, per
 * PaymentRequestService::activate()'s own guard. A Draft request has
 * no payable link/QR code yet (PublicPaymentPage::mount() and
 * PaymentRequestCheckoutService::submitPayment() both re-check
 * PaymentRequest::isPayable(), which requires Active); activating is
 * the deliberate, separate step that makes the link live.
 */
class ActivatePaymentRequestAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'activatePaymentRequest';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Activate');
        $this->icon(Heroicon::OutlinedPlayCircle);
        $this->color('success');
        $this->requiresConfirmation();
        $this->modalDescription('Makes this request payable — the link and QR code become live immediately.');

        $this->visible(function (PaymentRequest $record): bool {
            if ($record->status !== PaymentRequestStatus::Draft) {
                return false;
            }

            $firmUser = Auth::user()?->activeFirmUser();

            return $firmUser !== null
                && (int) $firmUser->firm_id === (int) $record->firm_id
                && app(PaymentRequestAccessPolicyService::class)->canManagePaymentRequest($firmUser->role);
        });

        $this->action(function (PaymentRequest $record): void {
            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || ! app(PaymentRequestAccessPolicyService::class)->canManagePaymentRequest($firmUser->role)) {
                Notification::make()->title('Not permitted')->danger()->send();

                return;
            }

            app(TenantContextService::class)->runWithFirmContext($firmUser->firm, function () use ($record, $firmUser): void {
                $fresh = PaymentRequest::query()->where('id', $record->id)->firstOrFail();

                if ((int) $firmUser->firm_id !== (int) $fresh->firm_id) {
                    Notification::make()->title('You do not have access to this payment request.')->danger()->send();

                    return;
                }

                try {
                    app(PaymentRequestService::class)->activate($firmUser->firm, $fresh, $firmUser);
                    Notification::make()->title('Payment request activated')->success()->send();
                } catch (Throwable $e) {
                    Notification::make()->title('Could not activate payment request')->body($e->getMessage())->danger()->send();
                }
            });
        });
    }
}
