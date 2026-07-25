<?php

declare(strict_types=1);

namespace Tests\Feature\Security\PlatformAdminMfa;

use App\Models\PlatformAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use SensitiveParameterValue;
use Tests\TestCase;
use Throwable;

/**
 * PlatformAdminMfaSecretRedactionTest — MFA design proposal §9's
 * "secret/recovery-code never appearing in any log output or
 * test-failure-printable location" requirement.
 *
 * Two distinct guarantees, tested separately:
 *  1. PlatformAdmin::$hidden already covers two_factor_secret/
 *     two_factor_recovery_codes for normal array/JSON serialization
 *     (toArray()/toJson()) — the ordinary "don't expose it in an API
 *     response" guarantee.
 *  2. PHP 8.2's #[SensitiveParameter] attribute (already applied
 *     throughout Filament's own vendor MultiFactor code, and mirrored
 *     on every one of PlatformAdmin's own MFA methods —
 *     saveAppAuthenticationSecret()/saveAppAuthenticationRecoveryCodes())
 *     redacts the ARGUMENT VALUE from any stack trace generated while
 *     that call frame is still active — the "don't leak it into an
 *     uncaught-exception stack trace / error log" guarantee, which
 *     $hidden alone does not cover (a stack trace is not a model
 *     serialization).
 *
 * Deliberately does NOT prove guarantee #2 via a naive
 * assertStringNotContainsString($secret, $traceAsString) pattern — per
 * this checkpoint's own explicit instruction to avoid an assertion
 * style that could itself leak a value into CI output. Instead, this
 * walks the exception's structured getTrace() array and asserts the
 * SPECIFIC argument slot is an instance of SensitiveParameterValue
 * (the redaction marker object PHP itself substitutes) rather than the
 * raw string — a structural, type-level check, not a substring search
 * over a giant rendered trace string.
 */
class PlatformAdminMfaSecretRedactionTest extends TestCase
{
    use RefreshDatabase;

    private string|false $originalExceptionIgnoreArgsIni = false;

    protected function setUp(): void
    {
        parent::setUp();

        // This environment's default php.ini sets
        // zend.exception_ignore_args=On, which strips ALL argument
        // values (redacted or not) from Exception::getTrace() — that
        // would make assertRedactedInTrace() below unable to
        // distinguish "correctly redacted" from "args weren't captured
        // at all", a false pass for the wrong reason. Forcing it off
        // for the duration of this test class only (restored in
        // tearDown()) makes the trace actually carry argument values,
        // so the SensitiveParameterValue check below is a real,
        // meaningful assertion.
        $this->originalExceptionIgnoreArgsIni = ini_set('zend.exception_ignore_args', '0');
    }

    protected function tearDown(): void
    {
        if ($this->originalExceptionIgnoreArgsIni !== false) {
            ini_set('zend.exception_ignore_args', $this->originalExceptionIgnoreArgsIni);
        }

        parent::tearDown();
    }

    public function test_two_factor_columns_are_hidden_from_array_and_json_serialization(): void
    {
        $admin = PlatformAdmin::factory()->create([
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'two_factor_recovery_codes' => ['hash-one', 'hash-two'],
        ]);

        $array = $admin->toArray();

        $this->assertArrayNotHasKey('two_factor_secret', $array);
        $this->assertArrayNotHasKey('two_factor_recovery_codes', $array);
        $this->assertArrayNotHasKey('password', $array);
    }

    public function test_saving_an_app_authentication_secret_that_fails_redacts_the_secret_from_the_exception_trace(): void
    {
        // An unpersisted admin missing every other required NOT NULL
        // column (name/email/password) forces a real database
        // constraint failure inside saveAppAuthenticationSecret()'s own
        // ->save() call, with the secret still on the call stack.
        $admin = new PlatformAdmin;

        $secret = 'JBSWY3DPEHPK3PXP-DO-NOT-LEAK';

        $caught = null;

        try {
            $admin->saveAppAuthenticationSecret($secret);
            $this->fail('Expected a database exception from saving an incomplete PlatformAdmin.');
        } catch (Throwable $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught);
        $this->assertRedactedInTrace($caught, 'saveAppAuthenticationSecret');
    }

    public function test_saving_recovery_codes_that_fails_redacts_the_codes_from_the_exception_trace(): void
    {
        $admin = new PlatformAdmin;

        $caught = null;

        try {
            $admin->saveAppAuthenticationRecoveryCodes(['DO-NOT-LEAK-1', 'DO-NOT-LEAK-2']);
            $this->fail('Expected a database exception from saving an incomplete PlatformAdmin.');
        } catch (Throwable $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught);
        $this->assertRedactedInTrace($caught, 'saveAppAuthenticationRecoveryCodes');
    }

    /**
     * Walks $exception->getTrace() looking for the named method's call
     * frame and asserts every one of its arguments is either a
     * redaction marker (SensitiveParameterValue) or, for any argument
     * NOT marked #[SensitiveParameter] on that method (e.g. $this),
     * simply not the kind of value under test here. Fails loudly if
     * the frame is not found at all (a false negative would be worse
     * than a false positive for a security-critical test like this).
     */
    private function assertRedactedInTrace(Throwable $exception, string $methodName): void
    {
        $frame = null;

        foreach ($exception->getTrace() as $candidate) {
            if (($candidate['function'] ?? null) === $methodName) {
                $frame = $candidate;

                break;
            }
        }

        $this->assertNotNull($frame, "Expected to find a [{$methodName}] frame in the exception trace.");

        $foundRedactedArgument = false;

        foreach ($frame['args'] ?? [] as $arg) {
            if ($arg instanceof SensitiveParameterValue) {
                $foundRedactedArgument = true;
            }
        }

        $this->assertTrue(
            $foundRedactedArgument,
            "Expected [{$methodName}]'s #[SensitiveParameter] argument to appear as a SensitiveParameterValue redaction marker in the exception trace, not its raw value."
        );
    }
}
