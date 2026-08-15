<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformAdmin;

use App\Enums\DeletionRequestStatus;
use App\Enums\ExportJobStatus;
use App\Enums\LegalHoldScope;
use App\Enums\OffboardingRequestStatus;
use App\Enums\PlatformRoleCode;
use App\Filament\Pages\PlatformGovernanceOverviewPage;
use App\Models\DeletionRequest;
use App\Models\ExportJob;
use App\Models\Firm;
use App\Models\OffboardingRequest;
use App\Models\PlatformAdmin;
use App\Services\GovernanceOverviewMetricsService;
use App\Services\LegalHoldService;
use App\Services\PlatformRoleService;
use App\Services\TenantContextService;
use App\ValueObjects\GovernanceAttentionItem;
use App\ValueObjects\GovernanceMetric;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Feature\Governance\Concerns\SetsUpGovernanceFirm;
use Tests\TestCase;

/**
 * PlatformGovernanceOverviewPageTest — the Governance command centre.
 *
 * The assertions that matter most here are the TRUTHFULNESS ones: that
 * unmeasurable things render as "Not monitored"/"Not supported" rather
 * than 0, and that the page never claims a capability the backend
 * lacks (a downloadable export, an expiring legal hold, an executed
 * deletion, a completed sweep).
 */
final class PlatformGovernanceOverviewPageTest extends TestCase
{
    use RefreshDatabase, SetsUpGovernanceFirm;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function adminWithRole(PlatformRoleCode $role): PlatformAdmin
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, $role);

        return $admin;
    }

    private function metrics(): GovernanceOverviewMetricsService
    {
        return app(GovernanceOverviewMetricsService::class);
    }

    /**
     * @param  array<int, GovernanceMetric>  $metrics
     */
    private function metric(array $metrics, string $label): GovernanceMetric
    {
        foreach ($metrics as $metric) {
            if ($metric->label === $label) {
                return $metric;
            }
        }

        $this->fail("No metric labelled '{$label}' was produced.");
    }

    // --- Authorization ---

    public function test_navigation_is_hidden_for_a_guest(): void
    {
        $this->assertFalse(PlatformGovernanceOverviewPage::shouldRegisterNavigation());
    }

    public function test_navigation_is_visible_for_a_super_admin(): void
    {
        $this->actingAs($this->adminWithRole(PlatformRoleCode::SuperAdmin), 'platform_admin');

        $this->assertTrue(PlatformGovernanceOverviewPage::shouldRegisterNavigation());
    }

    public function test_guest_is_redirected_from_the_overview_page(): void
    {
        $this->get(PlatformGovernanceOverviewPage::getUrl())->assertRedirect($this->adminUrl('/login'));
    }

    public function test_a_platform_admin_with_no_role_is_forbidden(): void
    {
        $this->actingAs(PlatformAdmin::factory()->create(['is_active' => true]), 'platform_admin');

        $this->get(PlatformGovernanceOverviewPage::getUrl())->assertForbidden();
    }

    public function test_a_super_admin_can_load_the_overview_page(): void
    {
        $this->actingAs($this->adminWithRole(PlatformRoleCode::SuperAdmin), 'platform_admin');

        $this->get(PlatformGovernanceOverviewPage::getUrl())->assertSuccessful();
    }

    /**
     * The service re-asserts authorization itself rather than trusting
     * the page's canAccess() gate.
     */
    public function test_the_metrics_service_refuses_an_unauthorized_admin(): void
    {
        $this->expectException(RuntimeException::class);

        $this->metrics()->summary(PlatformAdmin::factory()->create(['is_active' => true]));
    }

    // --- Real counts ---

    public function test_active_legal_holds_are_counted_across_firms(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $firmA = $this->makeGovernanceFirm();
        $firmB = $this->makeGovernanceFirm();

        app(LegalHoldService::class)->place($firmA, LegalHoldScope::Firm, 'Litigation.', $admin);
        app(LegalHoldService::class)->place($firmB, LegalHoldScope::Firm, 'Litigation.', $admin);

        $summary = $this->metrics()->summary($admin);

        $this->assertSame(2, $this->metric($summary['legal_holds'], 'Active holds')->value);
    }

    public function test_a_measured_zero_is_still_shown_as_zero(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->makeGovernanceFirm();

        $metric = $this->metric($this->metrics()->summary($admin)['legal_holds'], 'Active holds');

        $this->assertTrue($metric->isAvailable(), 'a real, queryable count must stay AVAILABLE');
        $this->assertSame(0, $metric->value);
        $this->assertSame('0', $metric->display());
    }

    // --- Truthfulness: unmeasurable things must not render as 0 ---

    public function test_retention_sweep_history_is_not_monitored_rather_than_zero(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $retention = $this->metrics()->summary($admin)['retention'];

        foreach (['Last successful sweep', 'Failed sweeps'] as $label) {
            $metric = $this->metric($retention, $label);

            $this->assertSame(
                GovernanceMetric::NOT_MONITORED,
                $metric->availability,
                "'{$label}' must be NOT_MONITORED — sweep evidence is flat-log only, so reporting a number "
                .'would assert an execution history that no durable evidence supports'
            );
            $this->assertNull($metric->value);
            $this->assertSame('Not monitored', $metric->display());
            $this->assertNotNull($metric->explanation, 'the operator must be told why it cannot be counted');
        }
    }

    public function test_legal_hold_expiry_and_review_are_not_supported_rather_than_zero(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $holds = $this->metrics()->summary($admin)['legal_holds'];

        foreach (['Holds requiring review', 'Holds nearing expiry', 'Pending release requests'] as $label) {
            $metric = $this->metric($holds, $label);

            $this->assertSame(
                GovernanceMetric::NOT_SUPPORTED,
                $metric->availability,
                "'{$label}' must be NOT_SUPPORTED — legal_holds has no review_date and no expires_at, and "
                .'a hold never lapses on its own'
            );
            $this->assertSame('Not supported', $metric->display());
        }
    }

    public function test_export_download_expiry_is_not_supported_because_no_file_is_produced(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $metric = $this->metric($this->metrics()->summary($admin)['exports'], 'Expired downloads');

        $this->assertSame(GovernanceMetric::NOT_SUPPORTED, $metric->availability);
        $this->assertStringContainsString('no file path', $metric->explanation ?? '');
    }

    public function test_deletion_execution_is_not_supported_because_nothing_is_ever_executed(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $deletion = $this->metrics()->summary($admin)['deletion'];

        $execution = $this->metric($deletion, 'In execution / completed');
        $this->assertSame(GovernanceMetric::NOT_SUPPORTED, $execution->availability);

        $overdue = $this->metric($deletion, 'Overdue');
        $this->assertSame(
            GovernanceMetric::NOT_SUPPORTED,
            $overdue->availability,
            'deletion_requests stores no due date, so no request may be called overdue'
        );
    }

    public function test_migration_cutover_and_blocked_are_not_supported(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $migrations = $this->metrics()->summary($admin)['migrations'];

        foreach (['Awaiting cutover', 'Blocked'] as $label) {
            $this->assertSame(
                GovernanceMetric::NOT_SUPPORTED,
                $this->metric($migrations, $label)->availability,
                "migration_projects models no '{$label}' concept"
            );
        }
    }

    // --- Requires Attention ---

    public function test_hold_blocked_deletion_requests_raise_a_blocker(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $firm = $this->makeGovernanceFirm();

        (new TenantContextService)->runWithFirmContext($firm, fn () => DeletionRequest::factory()->create([
            'firm_id' => $firm->id,
            'status' => DeletionRequestStatus::LegalHoldBlocked,
        ]));

        $conditions = array_map(
            static fn (GovernanceAttentionItem $item): string => $item->condition,
            $this->metrics()->summary($admin)['attention'],
        );

        $this->assertContains('Deletion requests blocked by legal hold', $conditions);
    }

    public function test_hold_blocked_offboarding_raises_a_blocker(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $firm = $this->makeGovernanceFirm();

        (new TenantContextService)->runWithFirmContext($firm, fn () => OffboardingRequest::factory()->create([
            'firm_id' => $firm->id,
            'status' => OffboardingRequestStatus::LegalHoldBlocked,
        ]));

        $item = $this->attentionItem($admin, 'Offboarding blocked by legal hold');

        $this->assertSame(GovernanceAttentionItem::SEVERITY_BLOCKER, $item->severity);
        $this->assertSame(1, $item->count);
    }

    public function test_failed_exports_raise_a_warning(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $firm = $this->makeGovernanceFirm();

        (new TenantContextService)->runWithFirmContext($firm, fn () => ExportJob::factory()->create([
            'firm_id' => $firm->id,
            'status' => ExportJobStatus::Failed,
        ]));

        $this->assertSame(
            GovernanceAttentionItem::SEVERITY_WARNING,
            $this->attentionItem($admin, 'Export jobs failed')->severity,
        );
    }

    /**
     * §30: unresolved hold coverage must become either VERIFIED or a
     * visible blocker — never a passive permanent banner.
     */
    public function test_unresolved_legal_hold_coverage_is_a_visible_blocker(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $item = $this->attentionItem($admin, 'Retention categories with unresolved legal hold coverage');

        $this->assertSame(GovernanceAttentionItem::SEVERITY_BLOCKER, $item->severity);
        $this->assertGreaterThan(0, $item->count);
        $this->assertStringContainsString('sync_runs', $item->detail);
    }

    /**
     * The two structural gaps are reported as "not evaluated" every
     * time. They are properties of the schema, so no query can clear
     * them, and silently omitting them would let the page imply it had
     * checked something it cannot check.
     */
    public function test_structural_gaps_are_reported_as_not_evaluated(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        foreach (['Retention sweep execution history', 'Retention policy row level security'] as $condition) {
            $this->assertSame(
                GovernanceAttentionItem::SEVERITY_UNEVALUATED,
                $this->attentionItem($admin, $condition)->severity,
                "'{$condition}' cannot be evaluated and must say so rather than being omitted"
            );
        }
    }

    public function test_a_clean_platform_still_reports_the_unevaluable_gaps(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->makeGovernanceFirm();

        $items = $this->metrics()->summary($admin)['attention'];

        $actionable = array_filter(
            $items,
            static fn (GovernanceAttentionItem $i): bool => $i->severity !== GovernanceAttentionItem::SEVERITY_UNEVALUATED
                && $i->condition !== 'Retention categories with unresolved legal hold coverage',
        );

        $this->assertSame([], $actionable, 'a clean platform must raise no actionable governance conditions');
        $this->assertNotEmpty($items, 'the unevaluable structural gaps must still be listed');
    }

    // --- Tenant isolation ---

    public function test_counts_respect_force_rls_and_leave_no_tenant_context_behind(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $firm = $this->makeGovernanceFirm();

        app(LegalHoldService::class)->place($firm, LegalHoldScope::Firm, 'Litigation.', $admin);

        $this->assertNoDatabaseTenantContext();
        $this->metrics()->summary($admin);
        $this->assertNoDatabaseTenantContext(
            'the cross-firm loop must restore context after every firm it visits'
        );
    }

    public function test_a_firm_with_no_governance_records_contributes_nothing(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $firmA = $this->makeGovernanceFirm();
        Firm::factory()->create();

        app(LegalHoldService::class)->place($firmA, LegalHoldScope::Firm, 'Litigation.', $admin);

        $this->assertSame(
            1,
            $this->metric($this->metrics()->summary($admin)['legal_holds'], 'Active holds')->value,
            'the empty firm must not inflate or suppress the count'
        );
    }

    private function attentionItem(PlatformAdmin $admin, string $condition): GovernanceAttentionItem
    {
        foreach ($this->metrics()->summary($admin)['attention'] as $item) {
            if ($item->condition === $condition) {
                return $item;
            }
        }

        $this->fail("No attention item for condition '{$condition}'.");
    }
}
