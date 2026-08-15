<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformAdmin;

use App\Enums\FirmUserRole;
use App\Enums\PlatformRoleCode;
use App\Enums\SupportAccessSessionStatus;
use App\Enums\SupportAccessType;
use App\Filament\Pages\PlatformSupportOverviewPage;
use App\Filament\Resources\SupportCaseResource;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\PlatformAdmin;
use App\Models\SupportAccessRequest;
use App\Models\SupportCase;
use App\Services\FirmSupportAccessService;
use App\Services\PlatformRoleService;
use App\Services\PlatformSupportAccessDirectoryService;
use App\Services\SupportAccessRequestService;
use App\Services\SupportAccessSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Prompt 6 — Support Overview and navigation truth. The overview must
 * count real rows, respect the same per-firm authorization as the lists
 * it summarizes, and describe an expired-but-still-flagged-Active session
 * accurately: as an unreconciled status, never as live access.
 */
class SupportOverviewPageTest extends TestCase
{
    use RefreshDatabase;

    private function adminWithRole(PlatformRoleCode $role): PlatformAdmin
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, $role);

        return $admin;
    }

    private function pendingRequest(Firm $firm): SupportAccessRequest
    {
        return (new SupportAccessRequestService)->request(
            $firm,
            PlatformAdmin::factory()->create(),
            SupportAccessType::Standard,
            'Investigating a reported sync failure.',
            60,
        );
    }

    private function approve(Firm $firm, SupportAccessRequest $request): SupportAccessRequest
    {
        $owner = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::FirmOwner]);

        return (new FirmSupportAccessService)->approve($owner, $request->id);
    }

    // ---------------------------------------------------------------
    // Navigation truth
    // ---------------------------------------------------------------

    public function test_the_requests_resource_is_not_labelled_as_a_support_case_domain(): void
    {
        // There is no SupportCase model, table or service anywhere in this
        // codebase — this resource reads support_access_requests. Labelling
        // it "Support Cases" advertised a domain that does not exist.
        $this->assertSame('Access Requests', SupportCaseResource::getNavigationLabel());

        $this->assertFalse(
            class_exists(SupportCase::class),
            'If a real SupportCase domain is ever introduced, this navigation decision must be revisited deliberately.'
        );
    }

    // ---------------------------------------------------------------
    // Overview counters
    // ---------------------------------------------------------------

    public function test_overview_counts_real_requests_and_sessions(): void
    {
        $firm = Firm::factory()->activated()->create();
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $this->approve($firm, $this->pendingRequest($firm));
        $this->pendingRequest($firm);

        $overview = app(PlatformSupportAccessDirectoryService::class)->supportOverview($admin);

        $this->assertSame(1, $overview['requests']['approved']);
        $this->assertSame(1, $overview['requests']['pending_firm_approval']);
        $this->assertSame(0, $overview['requests']['denied']);
        $this->assertSame(0, $overview['sessions']['active_now']);
    }

    public function test_a_measured_zero_is_reported_as_zero(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        Firm::factory()->activated()->create();

        $overview = app(PlatformSupportAccessDirectoryService::class)->supportOverview($admin);

        $this->assertSame(0, $overview['requests']['pending_firm_approval']);
        $this->assertSame(0, $overview['sessions']['active_now']);
        $this->assertSame([], $overview['attention']);
    }

    public function test_an_active_session_is_counted_and_stops_being_counted_once_expired(): void
    {
        $firm = Firm::factory()->activated()->create();
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $approved = $this->approve($firm, $this->pendingRequest($firm));
        $session = (new SupportAccessSessionService)->start($approved);

        $directory = app(PlatformSupportAccessDirectoryService::class);

        $this->assertSame(1, $directory->supportOverview($admin)['sessions']['active_now']);

        Carbon::setTestNow($session->expires_at->copy()->addSecond());

        $after = $directory->supportOverview($admin);

        $this->assertSame(
            0,
            $after['sessions']['active_now'],
            'An expired session authorizes nothing and must not be counted as active support.'
        );

        // Its stored status is still Active — nothing reconciles it — so it
        // must be surfaced honestly as an unreconciled status rather than
        // silently disappearing.
        $unreconciled = collect($after['attention'])->firstWhere('title', 'Expired sessions not yet reconciled');

        $this->assertNotNull($unreconciled);
        $this->assertSame(1, $unreconciled['count']);
        $this->assertStringContainsString('Authorization is already denied', $unreconciled['detail']);

        Carbon::setTestNow();
    }

    public function test_the_session_row_distinguishes_stored_status_from_live_authorization(): void
    {
        $firm = Firm::factory()->activated()->create();
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $approved = $this->approve($firm, $this->pendingRequest($firm));
        $session = (new SupportAccessSessionService)->start($approved);

        Carbon::setTestNow($session->expires_at->copy()->addSecond());

        $row = app(PlatformSupportAccessDirectoryService::class)
            ->listApprovedSupportSessions($admin)
            ->firstWhere('id', $session->id);

        $this->assertNotNull($row);
        $this->assertSame(SupportAccessSessionStatus::Active->value, $row['status'], 'The stored status is genuinely still Active.');
        $this->assertFalse($row['is_currently_valid'], 'But it authorizes nothing, and the row must say so.');
        $this->assertNull($row['time_remaining']);

        Carbon::setTestNow();
    }

    public function test_rows_carry_a_stable_operator_visible_reference(): void
    {
        $firm = Firm::factory()->activated()->create();
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $request = $this->pendingRequest($firm);

        $row = app(PlatformSupportAccessDirectoryService::class)
            ->listSupportCases($admin)
            ->firstWhere('id', $request->id);

        $this->assertNotNull($row);
        $this->assertStringStartsWith('SAR-', $row['reference']);

        // Derived from the row's own immutable uuid, so it is stable across
        // reads rather than positional or regenerated.
        $again = app(PlatformSupportAccessDirectoryService::class)
            ->listSupportCases($admin)
            ->firstWhere('id', $request->id);

        $this->assertSame($row['reference'], $again['reference']);
    }

    // ---------------------------------------------------------------
    // Authorization
    // ---------------------------------------------------------------

    public function test_the_overview_is_not_reachable_without_the_support_read_gate(): void
    {
        $salesRep = $this->adminWithRole(PlatformRoleCode::SalesRep);

        $this->actingAs($salesRep, 'platform_admin');

        $this->assertFalse(PlatformSupportOverviewPage::canAccess());
    }

    public function test_the_overview_never_aggregates_firms_the_admin_cannot_read(): void
    {
        $firm = Firm::factory()->activated()->create();
        $this->approve($firm, $this->pendingRequest($firm));

        // A SupportAgent holds no unconditionally-trusted ceiling role, so
        // per-firm reads require an active governed session for that exact
        // firm — which this admin does not have for any firm.
        $supportAgent = $this->adminWithRole(PlatformRoleCode::SupportAgent);

        $overview = app(PlatformSupportAccessDirectoryService::class)->supportOverview($supportAgent);

        $this->assertSame(
            0,
            $overview['requests']['approved'],
            'The overview must not become an aggregate side channel around per-firm authorization.'
        );
    }
}
