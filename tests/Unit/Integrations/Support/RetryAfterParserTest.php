<?php

declare(strict_types=1);

namespace Tests\Unit\Integrations\Support;

use App\Integrations\Support\RetryAfterParser;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * RetryAfterParserTest — Checkpoint 8
 * (agent-8e-retry-backoff-ratelimit-design.md §4;
 * agent-8h-architecture-security-review.md §4.2). Proves both RFC 7231
 * forms (delta-seconds, HTTP-date), the clamp floor/ceiling, and the
 * never-throws contract (malformed input returns null). $now is always
 * caller-supplied — this class never calls now()/time() internally, so
 * every test below is fully deterministic with no wall-clock
 * dependency whatsoever.
 */
class RetryAfterParserTest extends TestCase
{
    private const MAX_SECONDS = 3600;

    private function parser(int $maxSeconds = self::MAX_SECONDS): RetryAfterParser
    {
        return new RetryAfterParser($maxSeconds);
    }

    private function fixedNow(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-01-01T12:00:00+00:00');
    }

    // ------------------------------------------------------------
    // Delta-seconds form
    // ------------------------------------------------------------

    public function test_a_plain_delta_seconds_value_is_parsed_correctly(): void
    {
        $this->assertSame(120, $this->parser()->parse('120', $this->fixedNow()));
    }

    public function test_a_zero_delta_seconds_value_is_parsed_as_zero(): void
    {
        $this->assertSame(0, $this->parser()->parse('0', $this->fixedNow()));
    }

    public function test_delta_seconds_with_surrounding_whitespace_is_trimmed_and_parsed(): void
    {
        $this->assertSame(45, $this->parser()->parse('  45  ', $this->fixedNow()));
    }

    public function test_a_delta_seconds_value_with_a_leading_sign_is_rejected_as_malformed(): void
    {
        $this->assertNull($this->parser()->parse('+120', $this->fixedNow()));
        $this->assertNull($this->parser()->parse('-120', $this->fixedNow()));
    }

    public function test_a_delta_seconds_value_with_a_decimal_point_is_rejected_as_malformed(): void
    {
        $this->assertNull($this->parser()->parse('120.5', $this->fixedNow()));
    }

    // ------------------------------------------------------------
    // RFC 7231 HTTP-date form
    // ------------------------------------------------------------

    public function test_an_http_date_in_the_future_is_parsed_into_the_correct_delta(): void
    {
        // 5 minutes after fixedNow().
        $result = $this->parser()->parse('Thu, 01 Jan 2026 12:05:00 GMT', $this->fixedNow());

        $this->assertSame(300, $result);
    }

    public function test_an_http_date_exactly_equal_to_now_parses_to_zero(): void
    {
        $result = $this->parser()->parse('Thu, 01 Jan 2026 12:00:00 GMT', $this->fixedNow());

        $this->assertSame(0, $result);
    }

    public function test_an_http_date_in_the_past_clamps_to_the_zero_floor_never_negative(): void
    {
        $result = $this->parser()->parse('Thu, 01 Jan 2026 11:00:00 GMT', $this->fixedNow());

        $this->assertSame(0, $result);
    }

    // ------------------------------------------------------------
    // Clamp floor/ceiling
    // ------------------------------------------------------------

    public function test_a_delta_seconds_value_below_max_seconds_is_returned_unclamped(): void
    {
        $result = $this->parser(3600)->parse('1800', $this->fixedNow());

        $this->assertSame(1800, $result);
    }

    public function test_a_delta_seconds_value_exceeding_max_seconds_is_clamped_to_the_ceiling(): void
    {
        $result = $this->parser(3600)->parse('999999', $this->fixedNow());

        $this->assertSame(3600, $result);
    }

    public function test_a_delta_seconds_value_exactly_at_max_seconds_is_returned_unclamped(): void
    {
        $result = $this->parser(3600)->parse('3600', $this->fixedNow());

        $this->assertSame(3600, $result);
    }

    public function test_an_http_date_far_in_the_future_is_clamped_to_the_ceiling(): void
    {
        $result = $this->parser(3600)->parse('Fri, 02 Jan 2026 12:00:00 GMT', $this->fixedNow());

        $this->assertSame(3600, $result, 'A full 24h-out date must clamp to the 3600s ceiling.');
    }

    public function test_the_max_seconds_ceiling_is_caller_configurable_and_independently_enforced(): void
    {
        $parser = $this->parser(60);

        $this->assertSame(60, $parser->parse('120', $this->fixedNow()));
        $this->assertSame(0, $parser->parse('0', $this->fixedNow()));
    }

    // ------------------------------------------------------------
    // Malformed input returns null, never throws
    // ------------------------------------------------------------

    #[DataProvider('malformedInputProvider')]
    public function test_malformed_input_returns_null_never_throws(string $rawValue): void
    {
        $result = $this->parser()->parse($rawValue, $this->fixedNow());

        $this->assertNull($result);
    }

    public static function malformedInputProvider(): array
    {
        return [
            'empty string' => [''],
            'whitespace only' => ['   '],
            'garbage text' => ['not-a-retry-after-value'],
            'malformed date' => ['32 Foo 2026 25:99:99 XYZ'],
            'partial http date' => ['Thu, 01 Jan 2026'],
            'sql-injection-shaped string' => ["120; DROP TABLE integration_outbox_events; --"],
            'numeric-looking but with internal whitespace' => ['1 2 0'],
            'hex-looking digits' => ['0x78'],
            'unicode digits' => ['１２０'],
            'iso8601 date (not RFC 7231)' => ['2026-01-01T12:05:00+00:00'],
        ];
    }

    public function test_malformed_input_never_throws_an_exception(): void
    {
        // Explicit assertion the contract itself is "return null", not
        // merely that these specific fixtures happen not to throw —
        // exercise a broad spread of adversarial inputs in one place.
        $adversarialInputs = [
            "\0",
            "120\nSet-Cookie: evil=1",
            '<script>alert(1)</script>',
        ];

        foreach ($adversarialInputs as $input) {
            $result = $this->parser()->parse($input, $this->fixedNow());
            $this->assertNull($result, "Input [{$input}] must degrade to null, never throw.");
        }
    }

    public function test_an_absurdly_large_all_digit_string_never_throws_and_is_clamped_rather_than_treated_as_malformed(): void
    {
        // A string of ALL digits is syntactically VALID delta-seconds
        // input per the parser's own grammar (^\d+$) regardless of its
        // magnitude — this is intentionally NOT "malformed" (unlike the
        // adversarial inputs above); the never-throws contract still
        // applies (PHP's int-cast overflow on a value this large must
        // never surface as an exception), and the result — whatever it
        // numerically resolves to — must always be clamped to
        // [0, maxSeconds], never left unbounded and never null (this is
        // a valid-shaped, not malformed, input).
        $result = $this->parser(3600)->parse(str_repeat('9', 1000), $this->fixedNow());

        $this->assertIsInt($result);
        $this->assertGreaterThanOrEqual(0, $result);
        $this->assertLessThanOrEqual(3600, $result);
    }
}
