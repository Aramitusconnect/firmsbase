<?php

namespace App\Services;

use App\Models\PaymentRequest;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/**
 * PaymentRequestQrCodeService — Payment Link / QR Routing phase.
 * Generates a QR code encoding ONLY the signed payment URL
 * (PaymentRequestService::signedUrl()) — no client name, trust
 * balance, invoice detail, bank information, account number, internal
 * id, or classification data is ever encoded. Uses chillerlan/php-qrcode
 * (already a vendored, locked dependency via pragmarx/google2fa-qrcode
 * — this is the SAME package, called directly instead of duplicating
 * it via a second QR library); no custom encoder is written here.
 */
class PaymentRequestQrCodeService
{
    public function __construct(private readonly PaymentRequestService $paymentRequests) {}

    /**
     * Inline SVG markup — printable and displayable without any
     * further asset pipeline; safe to embed directly in a Blade view.
     */
    public function svgFor(PaymentRequest $paymentRequest): string
    {
        $options = new QROptions([
            'outputType' => QRCode::OUTPUT_MARKUP_SVG,
            'addQuietzone' => true,
        ]);

        return (new QRCode($options))->render($this->paymentRequests->signedUrl($paymentRequest));
    }
}
