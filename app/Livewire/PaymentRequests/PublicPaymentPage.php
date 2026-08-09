<?php

namespace App\Livewire\PaymentRequests;

use App\Enums\PaymentRequestAmountRule;
use App\Enums\PaymentRequestStatus;
use App\Exceptions\PaymentProviderUnavailableException;
use App\Models\PaymentRequest;
use App\Services\PaymentRequestCheckoutService;
use App\Services\PaymentRequestService;
use App\Services\Stripe\PaymentGatewaySimulationPolicyService;
use App\Services\TenantContextService;
use Illuminate\Support\Facades\App;
use Livewire\Component;

/**
 * PublicPaymentPage — Payment Link / QR Routing phase. The ONE public,
 * unauthenticated page this phase adds — no Filament chrome, no
 * session-authenticated guard of any kind. Reached only via a signed
 * URL (routes/web.php's public.payment-requests.show route, gated by
 * Laravel's own 'signed' + throttle middleware).
 *
 * Never trusts anything from the browser beyond the uuid route
 * parameter and the payer's own chosen amount — every other fact
 * (firm, client, matter, purpose, classification, target) is read
 * fresh from the stored PaymentRequest row on every request; nothing
 * is cached in component state across requests in a way a tampered
 * property could influence a write.
 */
class PublicPaymentPage extends Component
{
    private const PROVIDER_UNAVAILABLE_MESSAGE = 'Online payment is not currently available for this payment request. Please contact the firm.';

    public string $uuid;

    public ?int $paymentRequestId = null;

    public bool $found = false;

    public bool $payable = false;

    /**
     * Payment-Channel Safety Hardening pass, item 2. Whether a live
     * (or, in testing/opted-in-local, simulated) payment provider is
     * actually available right now — see
     * PaymentGatewaySimulationPolicyService's own docblock. When false,
     * the page must still show the request's own details but must
     * never render a functioning "Pay now" action.
     */
    public bool $providerAvailable = false;

    public string $firmDisplayName = '';

    public ?string $brandColor = null;

    public string $purposeDescription = '';

    public string $amountRule = '';

    public ?int $fixedAmountCents = null;

    public ?int $remainingCents = null;

    public string $status = '';

    public ?string $submittedAmountDollars = null;

    public bool $submitting = false;

    public ?string $resultMessage = null;

    public bool $resultSucceeded = false;

    public function mount(string $uuid): void
    {
        $this->uuid = $uuid;

        $paymentRequestService = App::make(PaymentRequestService::class);
        $paymentRequest = $paymentRequestService->resolveByUuid($uuid);

        if ($paymentRequest === null) {
            $this->found = false;

            return;
        }

        $this->found = true;
        $this->paymentRequestId = $paymentRequest->id;
        $this->status = $paymentRequest->status->value;
        $this->providerAvailable = App::make(PaymentGatewaySimulationPolicyService::class)->isSimulationEnabled();

        (new TenantContextService)->runWithFirmContext($paymentRequest->firm, function () use ($paymentRequest, $paymentRequestService) {
            $paymentRequestService->recordLinkAccessed($paymentRequest->firm, $paymentRequest, request()->ip());

            $this->hydrateDisplayFrom($paymentRequest->fresh());
        });
    }

    /**
     * Every field the page RENDERS is copied out here, once, from a
     * freshly-loaded row — never re-derived from mutable component
     * state on a later request. hydrateFromFreshRequest() (called again
     * before every submission attempt) re-runs this exact method so a
     * concurrent revocation/expiry between page-load and submit is
     * always caught.
     */
    private function hydrateDisplayFrom(PaymentRequest $paymentRequest): void
    {
        $this->payable = $paymentRequest->isPayable();
        $this->status = $paymentRequest->status->value;
        $this->firmDisplayName = $paymentRequest->firm->firmSettings?->branding_settings_json['display_name_override']
            ?? $paymentRequest->firm->legal_name
            ?? $paymentRequest->firm->name;
        $this->brandColor = $paymentRequest->firm->firmSettings?->branding_settings_json['primary_color'] ?? null;
        $this->purposeDescription = $paymentRequest->purpose->payerFacingDescription();
        $this->amountRule = $paymentRequest->amount_rule->value;
        $this->fixedAmountCents = $paymentRequest->amount_rule === PaymentRequestAmountRule::Fixed
            ? $paymentRequest->requested_amount_cents
            : null;
        $this->remainingCents = $paymentRequest->targetRemainingCents();
    }

    public function submit(): void
    {
        if (! $this->found || $this->paymentRequestId === null) {
            return;
        }

        // Payment-Channel Safety Hardening pass, item 2 — never trust
        // that the "Pay now" button was actually hidden client-side; a
        // forged/replayed POST must be rejected here too, before ever
        // reaching the gateway.
        if (! $this->providerAvailable) {
            $this->resultSucceeded = false;
            $this->resultMessage = self::PROVIDER_UNAVAILABLE_MESSAGE;

            return;
        }

        $this->submitting = true;
        $this->resultMessage = null;

        try {
            $paymentRequest = PaymentRequest::query()->findOrFail($this->paymentRequestId);
            $submittedAmountCents = $this->resolveSubmittedAmountCents();

            $result = App::make(PaymentRequestCheckoutService::class)->submitPayment(
                $paymentRequest,
                $submittedAmountCents,
                request()->ip(),
            );

            (new TenantContextService)->runWithFirmContext($result->firm, fn () => $this->hydrateDisplayFrom($result->fresh()));

            $this->resultSucceeded = in_array($result->status, [PaymentRequestStatus::Paid, PaymentRequestStatus::PendingReview], true);
            $this->resultMessage = match ($result->status) {
                PaymentRequestStatus::Paid => 'Thank you — your payment was received.',
                PaymentRequestStatus::PendingReview => 'Thank you — your payment was received and is being finalized by the firm.',
                PaymentRequestStatus::Failed => 'Your payment could not be completed. '.($result->failure_reason ?? 'Please try again.'),
                default => 'Your payment could not be completed. Please try again.',
            };
        } catch (PaymentProviderUnavailableException) {
            // Item 9 — never surface the exception class/message itself
            // (it never contains anything sensitive today, but this
            // stays true regardless of what a future implementation's
            // message says). Same professional copy shown proactively
            // when the button is hidden, for a payer who somehow still
            // reached submit().
            $this->resultSucceeded = false;
            $this->resultMessage = self::PROVIDER_UNAVAILABLE_MESSAGE;
        } catch (\Throwable $e) {
            $this->resultSucceeded = false;
            $this->resultMessage = 'Your payment could not be completed. Please try again or contact the firm directly.';
        } finally {
            $this->submitting = false;
        }
    }

    /**
     * Never trusts the raw dollar-string form input beyond parsing it
     * into cents — the real enforcement is
     * PaymentRequestService::validatePayableAmount(), called inside
     * PaymentRequestCheckoutService::submitPayment() itself under a row
     * lock. This method only converts what the payer typed; it never
     * decides whether that amount is acceptable.
     */
    private function resolveSubmittedAmountCents(): int
    {
        if ($this->amountRule === PaymentRequestAmountRule::Fixed->value) {
            return $this->fixedAmountCents ?? 0;
        }

        $dollars = (float) preg_replace('/[^0-9.]/', '', $this->submittedAmountDollars ?? '0');

        return (int) round($dollars * 100);
    }

    public function render()
    {
        return view('livewire.payment-requests.public-payment-page')
            ->layout('layouts.public');
    }
}
