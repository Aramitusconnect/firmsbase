<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\PaymentRequestResource\Actions;

use App\Enums\PaymentRequestStatus;
use App\Models\PaymentRequest;
use App\Services\PaymentRequestAccessPolicyService;
use App\Services\PaymentRequestService;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * CopyPaymentLinkAction — shows the signed URL
 * (PaymentRequestService::signedUrl(), the exact same
 * URL::temporarySignedRoute() mechanism FirmOwnerInvitationNotification
 * already uses) in a copyable field. Only ever displays the link — it
 * never regenerates or alters it; the URL a firm user sees here is
 * byte-for-byte what a payer's QR code/link would resolve to.
 *
 * Only visible for an Active request — a Draft/Revoked/Expired/Paid
 * request's link is never the thing a firm user should be handing to
 * a payer.
 */
class CopyPaymentLinkAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'copyPaymentLink';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Copy Link');
        $this->icon(Heroicon::OutlinedClipboardDocument);
        $this->color('gray');
        $this->modalHeading('Payment Link');
        $this->modalSubmitAction(false);
        $this->modalCancelActionLabel('Close');

        $this->infolist(fn (PaymentRequest $record): array => [
            TextEntry::make('url')
                ->label('')
                ->state(fn (): string => app(PaymentRequestService::class)->signedUrl($record))
                ->copyable()
                ->copyMessage('Link copied')
                ->weight('bold'),
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
