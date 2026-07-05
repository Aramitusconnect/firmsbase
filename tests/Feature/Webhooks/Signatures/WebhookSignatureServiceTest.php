<?php

namespace Tests\Feature\Webhooks\Signatures;

use App\Services\WebhookSignatureService;
use Tests\TestCase;

/**
 * Correction #6: HMAC SHA-256 over "{timestamp}.{canonical_payload}",
 * header format "sha256=<hex>", hash_equals() comparison, deterministic,
 * and verification must fail on payload/timestamp/signature mutation or
 * timestamp outside tolerance.
 */
class WebhookSignatureServiceTest extends TestCase
{
    private WebhookSignatureService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new WebhookSignatureService();
    }

    public function test_signing_is_deterministic_for_identical_inputs(): void
    {
        $secret = 'whsec_test';
        $timestamp = '1700000000';
        $payload = '{"matter_uuid":"abc-123"}';

        $first = $this->service->sign($secret, $timestamp, $payload);
        $second = $this->service->sign($secret, $timestamp, $payload);

        $this->assertSame($first, $second);
        $this->assertStringStartsWith('sha256=', $first);
    }

    public function test_signature_format_is_sha256_prefixed_hex(): void
    {
        $signature = $this->service->sign('secret', '1700000000', 'payload');

        $this->assertMatchesRegularExpression('/^sha256=[0-9a-f]{64}$/', $signature);
    }

    public function test_verify_succeeds_for_an_unmodified_request(): void
    {
        $secret = 'whsec_test';
        $timestamp = (string) time();
        $payload = '{"matter_uuid":"abc-123"}';
        $signature = $this->service->sign($secret, $timestamp, $payload);

        $this->assertTrue($this->service->verify($secret, $timestamp, $payload, $signature));
    }

    public function test_verify_fails_when_payload_changes(): void
    {
        $secret = 'whsec_test';
        $timestamp = (string) time();
        $signature = $this->service->sign($secret, $timestamp, '{"a":1}');

        $this->assertFalse($this->service->verify($secret, $timestamp, '{"a":2}', $signature));
    }

    public function test_verify_fails_when_timestamp_changes(): void
    {
        $secret = 'whsec_test';
        $timestamp = (string) time();
        $payload = '{"a":1}';
        $signature = $this->service->sign($secret, $timestamp, $payload);

        $this->assertFalse($this->service->verify($secret, (string) (((int) $timestamp) + 1), $payload, $signature));
    }

    public function test_verify_fails_when_signature_changes(): void
    {
        $secret = 'whsec_test';
        $timestamp = (string) time();
        $payload = '{"a":1}';
        $signature = $this->service->sign($secret, $timestamp, $payload);
        $tampered = substr($signature, 0, -1).(substr($signature, -1) === 'a' ? 'b' : 'a');

        $this->assertFalse($this->service->verify($secret, $timestamp, $payload, $tampered));
    }

    public function test_verify_fails_outside_the_tolerance_window(): void
    {
        $secret = 'whsec_test';
        $oldTimestamp = (string) (time() - 600);
        $payload = '{"a":1}';
        $signature = $this->service->sign($secret, $oldTimestamp, $payload);

        $this->assertFalse($this->service->verify($secret, $oldTimestamp, $payload, $signature, toleranceSeconds: 300));
    }

    public function test_verify_succeeds_within_a_custom_wider_tolerance(): void
    {
        $secret = 'whsec_test';
        $oldTimestamp = (string) (time() - 600);
        $payload = '{"a":1}';
        $signature = $this->service->sign($secret, $oldTimestamp, $payload);

        $this->assertTrue($this->service->verify($secret, $oldTimestamp, $payload, $signature, toleranceSeconds: 900));
    }

    public function test_verify_rejects_a_non_numeric_timestamp(): void
    {
        $this->assertFalse($this->service->verify('secret', 'not-a-timestamp', 'payload', 'sha256=abc'));
    }
}
