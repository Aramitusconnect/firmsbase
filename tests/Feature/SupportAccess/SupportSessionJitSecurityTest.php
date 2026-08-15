<?php

declare(strict_types=1);

namespace Tests\Feature\SupportAccess;

use App\Enums\FirmUserRole;
use App\Enums\HighRiskChangeType;
use App\Enums\PlatformRoleCode;
use App\Enums\SupportAccessSessionStatus;
use App\Enums\SupportAccessType;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\HighRiskChangeRequest;
use App\Models\PlatformAdmin;
use App\Models\SupportAccessRequest;
use App\Services\FirmSupportAccessService;
use App\Services\HighRiskPlatformChangePolicyService;
use App\Services\PlatformFirmIntegrationBoundedAccessService;
use App\Services\PlatformRoleService;
use App\Services\SupportAccessPolicyService;
use App\Services\SupportAccessRequestService;
use App\Services\SupportAccessSessionService;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

/**
 * Prompt 6 — just-in-time session security. A firm approval is a
 * point-in-time consent to one bounded session, not a standing licence.
 * These tests prove: one approval issues at most one session, a stale
 * approval stops working, a platform-terminated request cannot be revived
 * through the emergency path, and expiry is enforced synchronously at the
 * exact boundary rather than by any background reaper.
 */
class SupportSessionJitSecurityTest extends TestCase
{
    use RefreshDatabase;

    private SupportAccessRequestService $requests;

    private SupportAccessSessionService $sessions;

    private SupportAccessPolicyService $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requests = new SupportAccessRequestService;
        $this->sessions = new SupportAccessSessionService;
        $this->policy = new SupportAccessPolicyService;
    }

    private function inFirm(Firm $firm, callable $callback): mixed
    {
        return (new TenantContextService)->runWithFirmContext($firm->id, $callback);
    }

    private function approvedRequest(Firm $firm, ?PlatformAdmin $admin = null): SupportAccessRequest
    {
        $request = $this->requests->request(
            $firm,
            $admin ?? PlatformAdmin::factory()->create(),
            SupportAccessType::Standard,
            'Diagnosing a reported document sync failure.',
            60,
        );

        $owner = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::FirmOwner]);

        return (new FirmSupportAccessService)->approve($owner, $request->id);
    }

    // ---------------------------------------------------------------
    // One approval, one session
    // ---------------------------------------------------------------

    public function test_one_approval_issues_at_most_one_session(): void
    {
        $firm = Firm::factory()->create();
        $request = $this->approvedRequest($firm);

        $this->assertTrue($this->policy->canStartSession($request)->allowed);

        $this->sessions->start($request);

        $decision = $this->policy->canStartSession($this->inFirm($firm, fn () => $request->fresh()));

        $this->assertFalse(
            $decision->allowed,
            'A single firm approval must not be reusable as an unbounded licence to re-enter the firm.'
        );
    }

    public function test_a_session_cannot_be_restarted_from_the_same_approval_after_it_ends(): void
    {
        $firm = Firm::factory()->create();
        $request = $this->approvedRequest($firm);

        $session = $this->sessions->start($request);
        $this->inFirm($firm, fn () => $this->sessions->end($session));

        $this->assertFalse(
            $this->policy->canStartSession($this->inFirm($firm, fn () => $request->fresh()))->allowed,
            'Ending a session must not release the approval for silent reuse.'
        );
    }

    public function test_two_concurrent_starts_cannot_both_issue_a_session(): void
    {
        $firm = Firm::factory()->activated()->create();
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        $this->attachRole($admin, PlatformRoleCode::SupportAgent);
        $request = $this->approvedRequest($firm, $admin);

        $chokepoint = app(PlatformFirmIntegrationBoundedAccessService::class);

        $chokepoint->enterSupportAccessSession($admin, $request);

        try {
            $chokepoint->enterSupportAccessSession($admin, $this->inFirm($firm, fn () => $request->fresh()));
            $this->fail('A second session must not be issuable from the same approval.');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame(1, $this->inFirm($firm, fn () => $request->sessions()->count()));
    }

    // ---------------------------------------------------------------
    // Stale approval
    // ---------------------------------------------------------------

    public function test_an_approval_left_unconsumed_past_its_window_stops_authorizing(): void
    {
        $firm = Firm::factory()->create();
        $request = $this->approvedRequest($firm);

        $this->assertTrue($this->policy->canStartSession($request)->allowed);

        Carbon::setTestNow(now()->addMinutes(SupportAccessRequestService::APPROVAL_CONSUMPTION_WINDOW_MINUTES + 1));

        $this->assertFalse(
            $this->policy->canStartSession($this->inFirm($firm, fn () => $request->fresh()))->allowed,
            'Consent given for a situation an hour ago must not silently authorize a privileged session now.'
        );

        Carbon::setTestNow();
    }

    // ---------------------------------------------------------------
    // Platform-terminated requests are dead on every path
    // ---------------------------------------------------------------

    public function test_an_expired_request_cannot_start_a_session(): void
    {
        $firm = Firm::factory()->create();
        $request = $this->approvedRequest($firm);

        $this->inFirm($firm, fn () => $this->requests->expire($request));

        $this->assertFalse($this->policy->canStartSession($this->inFirm($firm, fn () => $request->fresh()))->allowed);
    }

    public function test_an_expired_emergency_request_cannot_start_a_session_either(): void
    {
        $firm = Firm::factory()->create();
        $admin = PlatformAdmin::factory()->create();

        $request = $this->requests->request(
            $firm,
            $admin,
            SupportAccessType::Emergency,
            'Firm-wide outage affecting all users.',
            30,
            'Production incident: the firm cannot access any matters.',
        );

        $this->approveLinkedHighRiskRequest($request);

        // Sanity: the emergency path genuinely works before termination.
        $this->assertTrue($this->policy->canStartSession($this->inFirm($firm, fn () => $request->fresh()))->allowed);

        $this->inFirm($firm, fn () => $this->requests->expire($request));

        $this->assertFalse(
            $this->policy->canStartSession($this->inFirm($firm, fn () => $request->fresh()))->allowed,
            'Emergency access bypasses the firm-consent step only — never the request\'s own lifecycle. '
            .'A platform-terminated emergency request must not remain indefinitely startable.'
        );
    }

    public function test_emergency_access_still_requires_high_risk_approval(): void
    {
        $firm = Firm::factory()->create();

        $request = $this->requests->request(
            $firm,
            PlatformAdmin::factory()->create(),
            SupportAccessType::Emergency,
            'Firm-wide outage.',
            30,
            'Production incident.',
        );

        $this->assertFalse(
            $this->policy->canStartSession($request)->allowed,
            'Emergency access must not be self-authorizing on the strength of a justification string alone.'
        );
    }

    // ---------------------------------------------------------------
    // Synchronous expiration
    // ---------------------------------------------------------------

    public function test_a_session_is_valid_immediately_before_expiry_and_invalid_at_the_boundary(): void
    {
        $firm = Firm::factory()->create();
        $session = $this->sessions->start($this->approvedRequest($firm));

        Carbon::setTestNow($session->expires_at->copy()->subSecond());
        $this->assertTrue($this->sessions->isValid($this->inFirm($firm, fn () => $session->fresh())));

        // The exact instant of expires_at is already too late: expiry is
        // enforced with isFuture(), so the boundary itself denies.
        Carbon::setTestNow($session->expires_at->copy());
        $this->assertFalse(
            $this->sessions->isValid($this->inFirm($firm, fn () => $session->fresh())),
            'Authorization must fail at the first invalid instant, not one tick later.'
        );

        Carbon::setTestNow();
    }

    public function test_expiry_is_enforced_without_any_reaper_touching_the_row(): void
    {
        $firm = Firm::factory()->activated()->create();
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        $this->attachRole($admin, PlatformRoleCode::SupportAgent);
        $request = $this->approvedRequest($firm, $admin);

        $chokepoint = app(PlatformFirmIntegrationBoundedAccessService::class);
        $session = $chokepoint->enterSupportAccessSession($admin, $request);

        $this->assertTrue($chokepoint->hasActiveSupportAccessSessionFor($admin, $firm));

        Carbon::setTestNow($session->expires_at->copy()->addSecond());

        // The row is still persisted as Active — nothing has reconciled it,
        // because no reaper exists for this domain. Authorization must
        // nonetheless refuse, proving the clock is checked synchronously on
        // every access rather than trusted from the status column.
        $this->assertSame(
            SupportAccessSessionStatus::Active,
            $this->inFirm($firm, fn () => $session->fresh())->status
        );

        $this->assertFalse(
            $chokepoint->hasActiveSupportAccessSessionFor($admin, $firm),
            'An expired session must stop authorizing access even while its persisted status still says Active.'
        );

        $this->expectException(RuntimeException::class);
        $chokepoint->assertCanAccessFirm($admin, $firm);

        Carbon::setTestNow();
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function attachRole(PlatformAdmin $admin, PlatformRoleCode $role): void
    {
        app(PlatformRoleService::class)->grant($admin, $role);
    }

    private function approveLinkedHighRiskRequest(SupportAccessRequest $request): void
    {
        $highRisk = HighRiskChangeRequest::query()
            ->where('change_type', HighRiskChangeType::EmergencySupportAccess->value)
            ->get()
            ->first(fn (HighRiskChangeRequest $row): bool => (int) ($row->metadata['support_access_request_id'] ?? 0) === $request->id);

        $this->assertNotNull($highRisk, 'Every emergency request must raise a high-risk change request.');

        (new HighRiskPlatformChangePolicyService)->firstApprove(
            $highRisk,
            PlatformAdmin::factory()->create(),
        );
    }
}
