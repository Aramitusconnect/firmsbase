<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Enums\DeletionRequestStatus;
use App\Enums\HighRiskChangeRequestStatus;
use App\Enums\PlatformRoleCode;
use App\Models\DeletionApproval;
use App\Models\DeletionRequest;
use App\Models\Firm;
use App\Models\HighRiskChangeRequest;
use App\Models\PlatformAdmin;
use App\Services\PlatformDeletionRequestDirectoryService;
use App\Services\PlatformRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * PlatformDeletionRequestDirectoryServiceTest — Phase 4 (FirmsVault
 * Platform Admin Control Center, "Operations, Governance, Support, and
 * Configuration"), Governance category, Deletion Requests module.
 */
final class PlatformDeletionRequestDirectoryServiceTest extends TestCase
{
    use RefreshDatabase;

    private PlatformDeletionRequestDirectoryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(PlatformDeletionRequestDirectoryService::class);
    }

    private function adminWithRole(PlatformRoleCode $role): PlatformAdmin
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, $role);

        return $admin;
    }

    private function approvalFor(DeletionRequest $request): DeletionApproval
    {
        $highRisk = HighRiskChangeRequest::factory()->create();

        return DeletionApproval::factory()->create([
            'deletion_request_id' => $request->id,
            'high_risk_change_request_id' => $highRisk->id,
            'status' => HighRiskChangeRequestStatus::Pending,
        ]);
    }

    public function test_list_merges_deletion_requests_across_every_firm(): void
    {
        $firmA = Firm::factory()->create(['name' => 'Firm A']);
        $firmB = Firm::factory()->create(['name' => 'Firm B']);

        DeletionRequest::factory()->forFirm($firmA)->create();
        DeletionRequest::factory()->forFirm($firmB)->create();

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $rows = $this->service->list($admin);

        $this->assertCount(2, $rows);
    }

    public function test_status_filter_narrows_the_list(): void
    {
        $firm = Firm::factory()->create();

        DeletionRequest::factory()->forFirm($firm)->create(['status' => DeletionRequestStatus::Requested]);
        DeletionRequest::factory()->forFirm($firm)->create(['status' => DeletionRequestStatus::ReadyForExecution]);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $rows = $this->service->list($admin, ['status' => DeletionRequestStatus::ReadyForExecution->value]);

        $this->assertCount(1, $rows);
        $this->assertSame(DeletionRequestStatus::ReadyForExecution->value, $rows->first()['status']);
    }

    public function test_a_request_with_no_approval_yet_has_a_null_approval(): void
    {
        $firm = Firm::factory()->create();
        $request = DeletionRequest::factory()->forFirm($firm)->create();

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $found = $this->service->find($admin, $firm, $request->id);

        $this->assertNull($found['approval']);
        $this->assertNull($found['approval_status']);
    }

    /**
     * deletion_approvals carries no firm_id of its own (InheritedTenant,
     * scoped transitively through deletion_request_id) — this proves
     * the approval attached to Firm A's request never leaks into Firm
     * B's listing, i.e. the batched whereIn() lookup is genuinely
     * scoped by the ids already resolved under each firm's own loop
     * iteration, not a blind cross-firm join.
     */
    public function test_approval_is_only_attached_to_its_own_firms_deletion_request(): void
    {
        $firmA = Firm::factory()->create(['name' => 'Firm A']);
        $firmB = Firm::factory()->create(['name' => 'Firm B']);

        $requestA = DeletionRequest::factory()->forFirm($firmA)->create();
        $requestB = DeletionRequest::factory()->forFirm($firmB)->create();

        $approvalA = $this->approvalFor($requestA);
        $this->approvalFor($requestB);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $rowsForFirmAOnly = $this->service->list($admin, ['firm_uuid' => $firmA->uuid]);

        $this->assertCount(1, $rowsForFirmAOnly);
        $this->assertSame($approvalA->id, $rowsForFirmAOnly->first()['approval']['id']);
    }

    public function test_find_resolves_only_under_the_correct_firm(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $request = DeletionRequest::factory()->forFirm($firmA)->create();

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $this->assertNotNull($this->service->find($admin, $firmA, $request->id));
        $this->assertNull($this->service->find($admin, $firmB, $request->id));
    }

    public function test_orders_deterministically_by_id_when_requested_at_ties(): void
    {
        $firm = Firm::factory()->create();
        $tie = now();

        $requests = collect(range(1, 3))->map(fn () => DeletionRequest::factory()->forFirm($firm)->create(['requested_at' => $tie]));

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $first = $this->service->list($admin)->pluck('id')->all();
        $second = $this->service->list($admin)->pluck('id')->all();

        $this->assertSame($first, $second);
        $this->assertSame($requests->sortByDesc('id')->pluck('id')->values()->all(), $first);
    }

    public function test_a_role_without_governance_access_is_denied(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SalesRep);

        $this->expectException(RuntimeException::class);

        $this->service->list($admin);
    }
}
