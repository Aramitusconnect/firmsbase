<?php

namespace Tests\Feature\Downgrades;

use App\Enums\EntitlementSource;
use App\Enums\PlanLimitMetric;
use App\Enums\SeatClass;
use App\Enums\UsageRollupMetric;
use App\Models\BillingAccount;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\ModuleCatalog;
use App\Models\Plan;
use App\Services\DowngradeEvaluationService;
use App\Services\EntitlementService;
use App\Services\PlanLimitService;
use App\Services\SeatAllocationService;
use App\Services\SeatEnforcementService;
use App\Services\UsageRollupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DowngradeEvaluationServiceTest extends TestCase
{
    use RefreshDatabase;

    private DowngradeEvaluationService $service;
    private PlanLimitService $planLimitService;
    private SeatAllocationService $seatAllocationService;
    private EntitlementService $entitlementService;
    private UsageRollupService $usageRollupService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->planLimitService = new PlanLimitService();
        $this->seatAllocationService = new SeatAllocationService();
        $this->entitlementService = new EntitlementService();
        $this->usageRollupService = new UsageRollupService();

        $this->service = new DowngradeEvaluationService(
            new SeatEnforcementService(),
            $this->entitlementService,
            $this->planLimitService,
            $this->usageRollupService,
        );
    }

    public function test_safe_when_within_all_new_plan_limits(): void
    {
        $firm = Firm::factory()->create();
        $this->seatAllocationService->allocateDirect($firm, SeatClass::Attorney, 5);
        FirmUser::factory()->forFirm($firm)->role(\App\Enums\FirmUserRole::Attorney)->create();

        $newPlan = Plan::factory()->create();
        $this->planLimitService->setLimit($newPlan, PlanLimitMetric::SeatsAttorney, 5);

        $result = $this->service->evaluate($firm, $newPlan);

        $this->assertTrue($result->safe);
    }

    public function test_blocked_when_attorney_seats_in_use_exceed_the_new_plans_limit(): void
    {
        $firm = Firm::factory()->create();
        $this->seatAllocationService->allocateDirect($firm, SeatClass::Attorney, 10);
        FirmUser::factory()->forFirm($firm)->role(\App\Enums\FirmUserRole::Attorney)->count(3)->create();

        $newPlan = Plan::factory()->create();
        $this->planLimitService->setLimit($newPlan, PlanLimitMetric::SeatsAttorney, 1);

        $result = $this->service->evaluate($firm, $newPlan);

        $this->assertFalse($result->safe);
        $this->assertSame(\App\Enums\DowngradeCheckStatus::BlockedSeatOveruse, $result->status);
    }

    public function test_blocked_when_a_currently_enabled_module_is_not_granted_by_the_new_plan(): void
    {
        $firm = Firm::factory()->create();
        $module = $this->module('trust_iolta');
        $this->entitlementService->setForSource($firm, $module->module_code, EntitlementSource::AdminOverride, true);

        $newPlan = Plan::factory()->create();

        $result = $this->service->evaluate($firm, $newPlan);

        $this->assertFalse($result->safe);
        $this->assertSame(\App\Enums\DowngradeCheckStatus::BlockedModuleInUse, $result->status);
    }

    public function test_blocked_when_storage_usage_exceeds_the_new_plans_storage_limit(): void
    {
        $account = BillingAccount::factory()->create();
        $firm = Firm::factory()->create(['billing_account_id' => $account->id]);
        $this->usageRollupService->recordUsage(
            $account, null, UsageRollupMetric::StorageBytes, 200_000_000_000, now()->subMonth(), now()
        );

        $newPlan = Plan::factory()->create();
        $this->planLimitService->setLimit($newPlan, PlanLimitMetric::StorageGb, 50);

        $result = $this->service->evaluate($firm, $newPlan);

        $this->assertFalse($result->safe);
        $this->assertSame(\App\Enums\DowngradeCheckStatus::BlockedStorageOveruse, $result->status);
    }

    public function test_a_non_safe_result_never_implies_legal_data_lockout(): void
    {
        // DowngradeEvaluationResult carries no read/write/export decision
        // at all — that concern belongs entirely to
        // PastDueBillingPolicyService/LegalDataAccessPolicyService.
        $firm = Firm::factory()->create();
        $this->seatAllocationService->allocateDirect($firm, SeatClass::Attorney, 10);
        FirmUser::factory()->forFirm($firm)->role(\App\Enums\FirmUserRole::Attorney)->count(3)->create();
        $newPlan = Plan::factory()->create();
        $this->planLimitService->setLimit($newPlan, PlanLimitMetric::SeatsAttorney, 1);

        $result = $this->service->evaluate($firm, $newPlan);

        $this->assertFalse($result->safe);
        $this->assertFalse(property_exists($result, 'canRead'));
        $this->assertFalse(property_exists($result, 'canWrite'));
    }

    /**
     * hotfix 01: reuses a module_catalog row already seeded by the
     * Phase 6 data migration instead of creating a duplicate via
     * ModuleCatalog::factory()->create(['module_code' => ...]), which
     * now violates module_catalog's unique index.
     */
    private function module(string $code): ModuleCatalog
    {
        return ModuleCatalog::query()->where('module_code', $code)->firstOrFail();
    }
}
