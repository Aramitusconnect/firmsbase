<?php

namespace App\Enums;

/**
 * ManualPaymentMethod — the PDF's payments.payment_method field has no
 * enumerated value list; this set is a recommendation covering the
 * manual-entry methods a firm would record today (no Stripe/card-on-
 * file processing exists yet). Adding a Phase 6 Stripe-sourced method
 * later is an additive enum change, not a redesign of this one.
 */
enum ManualPaymentMethod: string
{
    case Cash = 'cash';
    case Check = 'check';
    case BankTransfer = 'bank_transfer';
    case CardManualEntry = 'card_manual_entry';
    case Other = 'other';

    /**
     * Payment Link / QR Routing phase — the additive enum change this
     * class's own docblock explicitly anticipated ("Adding a Phase 6
     * Stripe-sourced method later is an additive enum change, not a
     * redesign of this one"). Set only by
     * PaymentRequestCheckoutService when a payer completes payment
     * through a public payment-request link/QR code, confirmed via
     * the existing StripeGateway abstraction — never by any other
     * caller.
     */
    case PaymentLink = 'payment_link';
}
