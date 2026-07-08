<?php

namespace Tests\Feature\Security\LoginPolicy;

use App\Services\LoginPolicyService;
use Tests\TestCase;

/**
 * PasswordPolicyTest — Section 39D. Proves the backend password
 * policy: strong passwords pass, weak passwords fail with specific
 * reasons, and common default/test passwords are rejected.
 */
class PasswordPolicyTest extends TestCase
{
    private LoginPolicyService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new LoginPolicyService();
    }

    public function test_strong_passwords_pass(): void
    {
        foreach (['Str0ng!Passw0rd', 'C0rrect#Horse$Battery', 'Tr0ub4dor&3!!'] as $strongPassword) {
            $this->assertTrue($this->service->passwordMeetsPolicy($strongPassword), "Expected '{$strongPassword}' to meet policy.");
            $this->assertEmpty($this->service->passwordPolicyFailures($strongPassword));
        }
    }

    public function test_weak_passwords_fail_with_specific_reasons(): void
    {
        $this->assertContains('too_short', $this->service->passwordPolicyFailures('Ab1!'));
        $this->assertContains('missing_uppercase', $this->service->passwordPolicyFailures('lowercase123!'));
        $this->assertContains('missing_lowercase', $this->service->passwordPolicyFailures('UPPERCASE123!'));
        $this->assertContains('missing_number', $this->service->passwordPolicyFailures('NoNumbersHere!'));
        $this->assertContains('missing_symbol', $this->service->passwordPolicyFailures('NoSymbolsHere123'));
    }

    public function test_common_default_test_passwords_fail(): void
    {
        foreach (['password', 'password123', 'Password123', 'PASSWORD123', 'letmein', 'admin123', 'changeme'] as $weakPassword) {
            $this->assertFalse($this->service->passwordMeetsPolicy($weakPassword), "Expected '{$weakPassword}' to fail policy.");
            $this->assertContains('common_weak_value', $this->service->passwordPolicyFailures($weakPassword));
        }
    }

    public function test_password_meets_policy_matches_empty_failures(): void
    {
        $this->assertSame(
            empty($this->service->passwordPolicyFailures('Str0ng!Passw0rd')),
            $this->service->passwordMeetsPolicy('Str0ng!Passw0rd'),
        );
    }
}
