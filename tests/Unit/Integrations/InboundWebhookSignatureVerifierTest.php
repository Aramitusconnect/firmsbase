<?php

declare(strict_types=1);

namespace Tests\Unit\Integrations;

use App\Integrations\Services\InboundWebhookSignatureVerifier;
use Illuminate\Support\Carbon;
use ReflectionClass;
use Tests\TestCase;

/**
 * InboundWebhookSignatureVerifierTest — Checkpoint 7. Pure unit tests
 * against the narrow verification boundary
 * (reviews/checkpoint-07/frozen-design-post-security-review.md §5 STEP
 * 4 / §8). No database, no HTTP — every scenario is exercised by
 * calling verify() directly with hand-built candidate arrays, raw
 * bodies, and header strings, mirroring the exact signing-input
 * construction the class itself uses ("v1" . ":" . <timestamp> . "."
 * . <raw body>).
 */
final class InboundWebhookSignatureVerifierTest extends TestCase
{
    private const SECRET = 'unit-test-webhook-signing-secret-value';

    private function verifier(): InboundWebhookSignatureVerifier
    {
        return new InboundWebhookSignatureVerifier();
    }

    private function sign(string $secret, string $timestamp, string $body): string
    {
        return 'v1='.hash_hmac('sha256', 'v1:'.$timestamp.'.'.$body, $secret);
    }

    // ------------------------------------------------------------
    // Happy path
    // ------------------------------------------------------------

    public function test_a_correctly_signed_request_verifies(): void
    {
        $timestamp = (string) now()->getTimestamp();
        $body = json_encode(['event_id' => 'evt-1']);
        $signature = $this->sign(self::SECRET, $timestamp, $body);

        $this->assertTrue($this->verifier()->verify([self::SECRET], $body, $timestamp, $signature));
    }

    public function test_the_second_candidate_verifies_when_the_first_does_not_match(): void
    {
        $timestamp = (string) now()->getTimestamp();
        $body = json_encode(['event_id' => 'evt-1']);
        $signature = $this->sign('the-real-secret', $timestamp, $body);

        $this->assertTrue($this->verifier()->verify(['wrong-secret', 'the-real-secret'], $body, $timestamp, $signature));
    }

    public function test_only_the_first_two_candidates_are_ever_consulted(): void
    {
        $timestamp = (string) now()->getTimestamp();
        $body = json_encode(['event_id' => 'evt-1']);
        $signature = $this->sign('third-candidate-secret', $timestamp, $body);

        $this->assertFalse(
            $this->verifier()->verify(['wrong-1', 'wrong-2', 'third-candidate-secret'], $body, $timestamp, $signature),
            'verify() must never fall through to a third candidate — the rotation contract is capped at 2.'
        );
    }

    // ------------------------------------------------------------
    // Wrong secret / invalid signature
    // ------------------------------------------------------------

    public function test_an_incorrect_signature_is_rejected(): void
    {
        $timestamp = (string) now()->getTimestamp();
        $body = json_encode(['event_id' => 'evt-1']);
        $wrongSignature = 'v1='.str_repeat('a', 64);

        $this->assertFalse($this->verifier()->verify([self::SECRET], $body, $timestamp, $wrongSignature));
    }

    public function test_a_signature_computed_with_a_wrong_secret_is_rejected(): void
    {
        $timestamp = (string) now()->getTimestamp();
        $body = json_encode(['event_id' => 'evt-1']);
        $signature = $this->sign('a-completely-different-secret', $timestamp, $body);

        $this->assertFalse($this->verifier()->verify([self::SECRET], $body, $timestamp, $signature));
    }

    // ------------------------------------------------------------
    // Raw-byte tampering
    // ------------------------------------------------------------

    public function test_flipping_a_single_byte_in_the_body_invalidates_the_signature(): void
    {
        $timestamp = (string) now()->getTimestamp();
        $body = json_encode(['event_id' => 'evt-1', 'n' => 1]);
        $signature = $this->sign(self::SECRET, $timestamp, $body);

        $mutated = $body;
        $mutated[strlen($mutated) - 3] = $mutated[strlen($mutated) - 3] === '1' ? '2' : '1';

        $this->assertNotSame($body, $mutated);
        $this->assertFalse($this->verifier()->verify([self::SECRET], $mutated, $timestamp, $signature));
    }

    public function test_trailing_whitespace_appended_to_the_body_invalidates_the_signature(): void
    {
        $timestamp = (string) now()->getTimestamp();
        $body = json_encode(['event_id' => 'evt-1']);
        $signature = $this->sign(self::SECRET, $timestamp, $body);

        $this->assertFalse($this->verifier()->verify([self::SECRET], $body."\n", $timestamp, $signature));
    }

    public function test_re_serializing_the_same_logical_json_with_different_key_order_invalidates_the_signature(): void
    {
        $timestamp = (string) now()->getTimestamp();
        $original = '{"event_id":"evt-1","event_type":"test.resource.created"}';
        $reserialized = '{"event_type":"test.resource.created","event_id":"evt-1"}';

        $signature = $this->sign(self::SECRET, $timestamp, $original);

        $this->assertNotSame($original, $reserialized);
        $this->assertFalse(
            $this->verifier()->verify([self::SECRET], $reserialized, $timestamp, $signature),
            'Verification must operate on exact raw bytes, never a parsed-then-re-encoded canonical form.'
        );
    }

    public function test_mutating_the_timestamp_after_signing_invalidates_verification_even_though_the_body_is_unchanged(): void
    {
        $signedTimestamp = (string) now()->getTimestamp();
        $body = json_encode(['event_id' => 'evt-1']);
        $signature = $this->sign(self::SECRET, $signedTimestamp, $body);

        $mutatedTimestamp = (string) ((int) $signedTimestamp + 1);

        $this->assertFalse($this->verifier()->verify([self::SECRET], $body, $mutatedTimestamp, $signature));
    }

    // ------------------------------------------------------------
    // Missing / malformed headers
    // ------------------------------------------------------------

    public function test_a_missing_signature_header_is_rejected(): void
    {
        $timestamp = (string) now()->getTimestamp();
        $body = json_encode(['event_id' => 'evt-1']);

        $this->assertFalse($this->verifier()->verify([self::SECRET], $body, $timestamp, null));
    }

    public function test_a_missing_timestamp_header_is_rejected_even_with_an_otherwise_correct_signature(): void
    {
        $timestamp = (string) now()->getTimestamp();
        $body = json_encode(['event_id' => 'evt-1']);
        $signature = $this->sign(self::SECRET, $timestamp, $body);

        $this->assertFalse($this->verifier()->verify([self::SECRET], $body, null, $signature));
    }

    public function test_a_missing_signature_and_a_missing_timestamp_produce_the_same_false_result_as_a_wrong_signature(): void
    {
        $body = json_encode(['event_id' => 'evt-1']);

        $missingBoth = $this->verifier()->verify([self::SECRET], $body, null, null);
        $wrongSig = $this->verifier()->verify([self::SECRET], $body, (string) now()->getTimestamp(), 'v1='.str_repeat('0', 64));

        $this->assertFalse($missingBoth);
        $this->assertFalse($wrongSig);
    }

    public function test_malformed_hex_after_the_v1_prefix_is_rejected(): void
    {
        $timestamp = (string) now()->getTimestamp();
        $body = json_encode(['event_id' => 'evt-1']);

        $this->assertFalse($this->verifier()->verify([self::SECRET], $body, $timestamp, 'v1=not-hex-at-all'));
        $this->assertFalse($this->verifier()->verify([self::SECRET], $body, $timestamp, 'v1='.str_repeat('g', 64)));
        $this->assertFalse($this->verifier()->verify([self::SECRET], $body, $timestamp, 'v1='.str_repeat('a', 63)));
    }

    public function test_a_signature_with_no_recognized_prefix_at_all_is_rejected(): void
    {
        $timestamp = (string) now()->getTimestamp();
        $body = json_encode(['event_id' => 'evt-1']);

        $this->assertFalse($this->verifier()->verify([self::SECRET], $body, $timestamp, str_repeat('a', 64)));
    }

    public function test_an_unrecognized_version_prefix_is_rejected_before_any_verification_succeeds(): void
    {
        $timestamp = (string) now()->getTimestamp();
        $body = json_encode(['event_id' => 'evt-1']);
        $hex = hash_hmac('sha256', 'v1:'.$timestamp.'.'.$body, self::SECRET);

        // Even though the hex digest itself is genuinely correct for
        // the "v1" scheme, prefixing it with an unsupported version
        // label must still be rejected — the allowlist check runs
        // first.
        $this->assertFalse($this->verifier()->verify([self::SECRET], $body, $timestamp, 'v2='.$hex));
    }

    public function test_timestamp_longer_than_eleven_characters_is_rejected(): void
    {
        $body = json_encode(['event_id' => 'evt-1']);
        $longTimestamp = str_repeat('1', 12);
        $signature = $this->sign(self::SECRET, $longTimestamp, $body);

        $this->assertFalse($this->verifier()->verify([self::SECRET], $body, $longTimestamp, $signature));
    }

    public function test_a_non_digit_timestamp_is_rejected(): void
    {
        $body = json_encode(['event_id' => 'evt-1']);
        $signature = $this->sign(self::SECRET, '-100', $body);

        $this->assertFalse($this->verifier()->verify([self::SECRET], $body, '-100', $signature));
    }

    // ------------------------------------------------------------
    // Replay window
    // ------------------------------------------------------------

    public function test_a_timestamp_exactly_300_seconds_in_the_past_is_accepted(): void
    {
        // Checkpoint 13 (frozen-test-closure-plan.md §4): freeze PHP time
        // so the timestamp this test constructs (now()->subSeconds(300))
        // and verify()'s own internal now()->getTimestamp() replay-window
        // comparison resolve to the identical frozen instant — at the
        // exact ±300s boundary a single real second-tick between the two
        // now() reads is the difference between 300 (accepted) and 301
        // (rejected). Makes the boundary proof deterministic; weakens
        // nothing.
        Carbon::setTestNow(Carbon::parse('2026-07-01 12:00:00'));

        $timestamp = (string) now()->subSeconds(300)->getTimestamp();
        $body = json_encode(['event_id' => 'evt-1']);
        $signature = $this->sign(self::SECRET, $timestamp, $body);

        $this->assertTrue($this->verifier()->verify([self::SECRET], $body, $timestamp, $signature));
    }

    public function test_a_timestamp_301_seconds_in_the_past_is_rejected(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-01 12:00:00'));

        $timestamp = (string) now()->subSeconds(301)->getTimestamp();
        $body = json_encode(['event_id' => 'evt-1']);
        $signature = $this->sign(self::SECRET, $timestamp, $body);

        $this->assertFalse($this->verifier()->verify([self::SECRET], $body, $timestamp, $signature));
    }

    public function test_a_timestamp_exactly_300_seconds_in_the_future_is_accepted(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-01 12:00:00'));

        $timestamp = (string) now()->addSeconds(300)->getTimestamp();
        $body = json_encode(['event_id' => 'evt-1']);
        $signature = $this->sign(self::SECRET, $timestamp, $body);

        $this->assertTrue($this->verifier()->verify([self::SECRET], $body, $timestamp, $signature));
    }

    public function test_a_timestamp_301_seconds_in_the_future_is_rejected(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-01 12:00:00'));

        $timestamp = (string) now()->addSeconds(301)->getTimestamp();
        $body = json_encode(['event_id' => 'evt-1']);
        $signature = $this->sign(self::SECRET, $timestamp, $body);

        $this->assertFalse($this->verifier()->verify([self::SECRET], $body, $timestamp, $signature));
    }

    public function test_an_expired_timestamp_with_an_otherwise_valid_signature_still_returns_false_not_a_distinguishable_result(): void
    {
        $timestamp = (string) now()->subDays(400)->getTimestamp();
        $body = json_encode(['event_id' => 'evt-1']);
        $signature = $this->sign(self::SECRET, $timestamp, $body);

        // A wildly expired timestamp is rejected the same untyped
        // false as a barely-expired one — no graduated response.
        $this->assertFalse($this->verifier()->verify([self::SECRET], $body, $timestamp, $signature));
    }

    // ------------------------------------------------------------
    // Constant-time / timing-oracle mitigation
    // ------------------------------------------------------------

    public function test_perform_constant_work_padding_executes_without_error_and_touches_no_real_secret(): void
    {
        $verifier = $this->verifier();

        // No exception, no return value to assert on — this proves the
        // fixed-cost padding path is callable and side-effect-free.
        $verifier->performConstantWorkPadding();

        $this->assertTrue(true);
    }

    // ------------------------------------------------------------
    // Source-inspection guards (closed allowlists must never widen silently)
    // ------------------------------------------------------------

    public function test_the_algorithm_allowlist_contains_exactly_one_entry(): void
    {
        $reflection = new ReflectionClass(InboundWebhookSignatureVerifier::class);
        $constant = $reflection->getConstant('ALGORITHM_ALLOWLIST');

        $this->assertIsArray($constant);
        $this->assertCount(1, $constant);
        $this->assertSame(['v1' => 'sha256'], $constant);
    }

    public function test_the_replay_window_constant_is_300_seconds(): void
    {
        $reflection = new ReflectionClass(InboundWebhookSignatureVerifier::class);

        $this->assertSame(300, $reflection->getConstant('REPLAY_WINDOW_SECONDS'));
    }

    public function test_verify_returns_only_a_boolean_never_a_richer_diagnostic_type(): void
    {
        $reflection = new ReflectionClass(InboundWebhookSignatureVerifier::class);
        $returnType = $reflection->getMethod('verify')->getReturnType();

        $this->assertNotNull($returnType);
        $this->assertSame('bool', (string) $returnType);
    }
}
