<?php

namespace Tests\Feature\Security\LoginPolicy;

use App\Enums\FirmUserStatus;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\User;
use App\Services\LoginPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TenantAwareLoginPolicyTest — Section 39D. Proves canAttemptFirmLogin()
 * is tenant-aware: only an ACTIVE FirmUser membership on the exact Firm
 * authorizes firm-context login, and loginAuditPayload() assembles a
 * read-only payload without writing to the database.
 */
class TenantAwareLoginPolicyTest extends TestCase
{
    use RefreshDatabase;

    private LoginPolicyService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new LoginPolicyService();
    }

    public function test_active_firm_user_membership_allows_firm_login(): void
    {
        $firm = Firm::factory()->create();
        $user = User::factory()->create();
        FirmUser::factory()->forFirm($firm)->forUser($user)->create(['status' => FirmUserStatus::Active]);

        $this->assertTrue($this->service->canAttemptFirmLogin($user, $firm));
    }

    public function test_non_active_firm_user_membership_denies_firm_login(): void
    {
        foreach ([FirmUserStatus::Invited, FirmUserStatus::Suspended, FirmUserStatus::Removed] as $status) {
            $firm = Firm::factory()->create();
            $user = User::factory()->create();
            FirmUser::factory()->forFirm($firm)->forUser($user)->create(['status' => $status]);

            $this->assertFalse($this->service->canAttemptFirmLogin($user, $firm), "Status {$status->value} must deny firm login.");
        }
    }

    public function test_user_from_another_firm_cannot_login_to_the_wrong_firm_context(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $user = User::factory()->create();
        FirmUser::factory()->forFirm($firmA)->forUser($user)->create(['status' => FirmUserStatus::Active]);

        $this->assertTrue($this->service->canAttemptFirmLogin($user, $firmA));
        $this->assertFalse($this->service->canAttemptFirmLogin($user, $firmB));
    }

    public function test_user_with_no_firm_user_membership_at_all_cannot_login_to_any_firm(): void
    {
        $firm = Firm::factory()->create();
        $user = User::factory()->create();

        $this->assertFalse($this->service->canAttemptFirmLogin($user, $firm));
    }

    public function test_login_audit_payload_includes_expected_fields_without_writing_to_the_database(): void
    {
        $firm = Firm::factory()->create();
        $user = User::factory()->create();

        $payload = $this->service->loginAuditPayload($user, $firm, '203.0.113.7', 'Mozilla/5.0 TestAgent');

        $this->assertSame($user->id, $payload['user_id']);
        $this->assertSame($user->email, $payload['user_email']);
        $this->assertSame($firm->id, $payload['firm_id']);
        $this->assertSame('203.0.113.7', $payload['ip']);
        $this->assertSame('Mozilla/5.0 TestAgent', $payload['user_agent']);
        $this->assertArrayHasKey('occurred_at', $payload);
        $this->assertNotEmpty($payload['occurred_at']);

        // No audit/login table exists yet — this method must never
        // write anything of its own.
        $this->assertDatabaseCount('security_events', 0);
    }

    public function test_login_audit_payload_allows_a_null_firm_for_pre_firm_context_attempts(): void
    {
        $user = User::factory()->create();

        $payload = $this->service->loginAuditPayload($user);

        $this->assertNull($payload['firm_id']);
        $this->assertNull($payload['ip']);
        $this->assertNull($payload['user_agent']);
    }

    public function test_policy_summary_returns_a_structured_breakdown(): void
    {
        $summary = $this->service->policySummary();

        $this->assertArrayHasKey('min_password_length', $summary);
        $this->assertArrayHasKey('max_failed_attempts', $summary);
        $this->assertArrayHasKey('lockout_window_minutes', $summary);
        $this->assertArrayHasKey('session_idle_timeout_minutes', $summary);
    }
}
