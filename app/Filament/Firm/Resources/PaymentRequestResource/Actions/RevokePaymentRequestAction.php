<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\PaymentRequestResource\Actions;

use App\Enums\PaymentRequestStatus;
use App\Models\PaymentRequest;
use App\Services\PaymentRequestAccessPolicyService;
use App\Services\PaymentRequestService;
use App\Services\TenantContextService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * RevokePaymentRequestAction — the DB-side control that works
 * independently of the signed URL's own cryptographic expiry (see
 * PaymentRequestService::signedUrl()'s own docblock: a signature
 * remains mathematically valid until its own encoded expiry, so
 * revocation must be enforced by status, not merely by the signature).
 * Blocked on an already-Paid or already-Revoked request, matching
 * PaymentRequestService::revoke()'s own guard exactly.
 */
class RevokePaymentRequestAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'revokePaymentRequest';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Revoke');
        $this->icon(Heroicon::OutlinedNoSymbol);
        $this->color('danger');
        $this->modalHeading('Revoke Payment Request');
        $this->modalDescription('Immediately disables the link and QR code, regardless of the signed URL\'s own expiration.');
        $this->modalSubmitActionLabel('Revoke');

        $this->schema([
            Textarea::make('reason')->label('Reason')->required()->rows(2),
        ]);

        $this->visible(function (PaymentRequest $record): bool {
            if (in_array($record->status, [PaymentRequestStatus::Paid, PaymentRequestStatus::Revoked], true)) {
                return false;
            }

            $firmUser = Auth::user()?->activeFirmUser();

            return $firmUser !== null
                && (int) $firmUser->firm_id === (int) $record->firm_id
                && app(PaymentRequestAccessPolicyService::class)->canManagePaymentRequest($firmUser->role);
        });

        $this->action(function (PaymentRequest $record, array $data): void {
            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || ! app(PaymentRequestAccessPolicyService::class)->canManagePaymentRequest($firmUser->role)) {
                Notification::make()->title('Not permitted')->danger()->send();

                return;
            }

            app(TenantContextService::class)->runWithFirmContext($firmUser->firm, function () use ($record, $firmUser, $data): void {
                $fresh = PaymentRequest::query()->where('id', $record->id)->firstOrFail();

                if ((int) $firmUser->firm_id !== (int) $fresh->firm_id) {
                    Notification::make()->title('You do not have access to this payment request.')->danger()->send();

                    return;
                }

                try {
                    app(PaymentRequestService::class)->revoke($firmUser->firm, $fresh, $firmUser, (string) $data['reason']);
                    Notification::make()->title('Payment request revoked')->success()->send();
                } catch (Throwable $e) {
                    Notification::make()->title('Could not revoke payment request')->body($e->getMessage())->danger()->send();
                }
            });
        });
    }
}
