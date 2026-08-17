<?php

declare(strict_types=1);

namespace App\Services\Pay;

use App\Exceptions\PaymentProviderUnavailableException;
use App\Services\Pay\Contracts\PaymentProviderAdapter;
use App\Services\Pay\Data\ProviderPaymentRequest;
use App\Services\Pay\Data\ProviderResult;

/**
 * UnavailablePaymentProviderAdapter — FirmsVault Pay Gate A3. The
 * fail-closed default binding for PaymentProviderAdapter outside
 * explicit simulation, mirroring the existing
 * UnavailablePaymentGateway / PaymentGatewaySimulationPolicyService
 * precedent verbatim: no environment may ever appear to have executed a
 * real provider operation because a fake silently answered. A real
 * connector (Finix, Gate B) is an explicit, disclosed addition — never
 * something this binding fakes into looking live.
 */
class UnavailablePaymentProviderAdapter implements PaymentProviderAdapter
{
    public function createCardPayment(ProviderPaymentRequest $request): ProviderResult
    {
        throw $this->unavailable();
    }

    public function getPaymentOutcome(ProviderPaymentRequest $request): ProviderResult
    {
        throw $this->unavailable();
    }

    public function refundPayment(ProviderPaymentRequest $request): ProviderResult
    {
        throw $this->unavailable();
    }

    public function getRefundOutcome(ProviderPaymentRequest $request): ProviderResult
    {
        throw $this->unavailable();
    }

    public function getSettlementEvidence(string $providerResourceReference): array
    {
        throw $this->unavailable();
    }

    public function getFeeEvidence(string $providerResourceReference): array
    {
        throw $this->unavailable();
    }

    private function unavailable(): PaymentProviderUnavailableException
    {
        return new PaymentProviderUnavailableException(
            'No real payment provider is connected in this environment, and provider simulation is not '
            .'enabled. FirmsVault Pay fails closed rather than fabricating a provider response.'
        );
    }
}
