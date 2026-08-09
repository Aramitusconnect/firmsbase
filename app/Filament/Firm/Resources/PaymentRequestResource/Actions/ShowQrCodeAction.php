<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\PaymentRequestResource\Actions;

use App\Enums\PaymentRequestStatus;
use App\Models\PaymentRequest;
use App\Services\PaymentRequestAccessPolicyService;
use App\Services\PaymentRequestQrCodeService;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * ShowQrCodeAction — renders PaymentRequestQrCodeService::svgFor()'s
 * inline SVG markup (server-generated, encodes only the signed URL —
 * see that service's own docblock) via a raw-HTML TextEntry. Safe to
 * render unescaped: the SVG string never incorporates any
 * request-supplied input, only the firm's own PaymentRequest data
 * already rendered server-side by chillerlan/php-qrcode.
 */
class ShowQrCodeAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'showPaymentRequestQrCode';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Show QR Code');
        $this->icon(Heroicon::OutlinedQrCode);
        $this->color('gray');
        $this->modalHeading('Payment QR Code');
        $this->modalSubmitAction(false);
        $this->modalCancelActionLabel('Close');
        $this->modalWidth('sm');

        $this->infolist(fn (PaymentRequest $record): array => [
            TextEntry::make('qr')
                ->label('')
                ->state(fn (): string => app(PaymentRequestQrCodeService::class)->svgFor($record))
                ->html(),
        ]);

        $this->visible(function (PaymentRequest $record): bool {
            if ($record->status !== PaymentRequestStatus::Active) {
                return false;
            }

            $firmUser = Auth::user()?->activeFirmUser();

            return $firmUser !== null
                && (int) $firmUser->firm_id === (int) $record->firm_id
                && app(PaymentRequestAccessPolicyService::class)->canManagePaymentRequest($firmUser->role);
        });
    }
}
