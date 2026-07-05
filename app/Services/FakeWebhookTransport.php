<?php

namespace App\Services;

use App\Models\WebhookDelivery;
use App\ValueObjects\WebhookTransportResult;

/**
 * FakeWebhookTransport — the ONLY implementation of
 * WebhookTransportInterface in Phase 14 (correction #4). Performs NO
 * real network I/O whatsoever: no outbound HTTP client, no HTTP client
 * library, no low-level socket or remote-stream primitives, no reading
 * of remote URLs, no DNS resolution, no real outbound sockets. It
 * simply records what it would have sent and returns a configurable,
 * deterministic result — tests construct this class directly (or a
 * test subclass) to simulate success/failure/timeout scenarios without
 * any transport-layer dependency.
 *
 * Future real transport can be wired later via a NEW class implementing
 * WebhookTransportInterface, bound explicitly wherever
 * WebhookDispatchJob is constructed — never by modifying this class or
 * any service container configuration in this phase.
 */
class FakeWebhookTransport implements WebhookTransportInterface
{
    /**
     * @var list<array{delivery_id: int, payload: string, headers: array}>
     */
    private array $sentRecords = [];

    public function __construct(private readonly WebhookTransportResult $resultToReturn = new WebhookTransportResult(
        outcome: \App\Enums\WebhookDeliveryAttemptOutcome::Success,
        httpStatusCode: 200,
        responseSnippet: 'ok',
    )) {
    }

    public function send(WebhookDelivery $delivery, string $signedPayload, array $headers): WebhookTransportResult
    {
        $this->sentRecords[] = [
            'delivery_id' => $delivery->id,
            'payload' => $signedPayload,
            'headers' => $headers,
        ];

        return $this->resultToReturn;
    }

    /**
     * @return list<array{delivery_id: int, payload: string, headers: array}>
     */
    public function sentRecords(): array
    {
        return $this->sentRecords;
    }
}
