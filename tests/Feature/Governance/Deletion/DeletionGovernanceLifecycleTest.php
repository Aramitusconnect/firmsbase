<?php

namespace Tests\Feature\Governance\Deletion;

use App\Enums\DeletionRequestStatus;
use App\Enums\HighRiskChangeType;
use App\Enums\LegalHoldScope;
use App\Enums\RetentionPolicyStatus;
use App\Enums\RetentionRecordType;
use App\Models\Matter;
use App\Models\RetentionPolicy;
use App\Services\DeletionApprovalService;
use App\Services\DeletionGovernanceService;
use App\Services\DeletionRequestService;
use App\Services\LegalHoldService;
use App\Services\OffboardingExportService;
use App\Services\OffboardingRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Governance\Concerns\SetsUpGovernanceFirm;
use Tests\TestCase;

/**
 * Approved decision #1: Phase 17 stops at ReadyForExecution — no
 * physical row delete ever happens here.
 */
class DeletionGovernanceLifecycleTest extends TestCase
{
    use RefreshDatabase, SetsUpGovernanceFirm;

    private function verifiedExportFor($firm, $admin): \App\Models\OffboardingExport
    {
        $offboardingRequest = app(OffboardingRequestService::class)->request($firm, $admin, 'Offboarding for deletion.');
        $export = app(OffboardingExportService::class)->generate($offboardingRequest, requestedByPlatformAdmin: $admin);

        return app(OffboardingExportService::class)->verify($export, $admin);
    }

    public function test_deletion_request_requires_reason_and_target_snapshot(): void
    {
        $firm = $this->makeGovernanceFirm();
        $admin = $this->makePlatformAdmin();
        $matter = Matter::factory()->create(['firm_id' => $firm->id]);

        $request = app(DeletionRequestService::class)->request(
            $firm,
            Matter::class,
            $matter->id,
            'Retention expired; platform admin approved governed deletion.',
            $admin,
            ['matter_id' => $matter->id, 'matter_status' => $matter->status->value ?? null],
        );

        $this->assertNotEmpty($request->reason);
        $this->assertNotEmpty($request->subject_snapshot_json);
        $this->assertSame(DeletionRequestStatus::Requested, $request->status);
    }

    public function test_cannot_submit_for_approval_without_a_verified_export(): void
    {
        $firm = $this->makeGovernanceFirm();
        $admin = $this->makePlatformAdmin();
        $matter = Matter::factory()->create(['firm_id' => $firm->id]);

        $request = app(DeletionRequestService::class)->request($firm, Matter::class, $matter->id, 'Reason.', $admin);

        $this->expectException(\RuntimeException::class);
        app(DeletionGovernanceService::class)->submitForApproval($request, RetentionRecordType::Matter);
    }

    public function test_cannot_submit_for_approval_with_active_legal_hold(): void
    {
        $firm = $this->makeGovernanceFirm();
        $admin = $this->makePlatformAdmin();
        $matter = Matter::factory()->create(['firm_id' => $firm->id]);
        RetentionPolicy::factory()->create([
            'firm_id' => null,
            'record_type' => RetentionRecordType::Matter,
            'retention_period_days' => 1,
            'status' => RetentionPolicyStatus::Active,
        ]);

        $export = $this->verifiedExportFor($firm, $admin);
        app(LegalHoldService::class)->place($firm, LegalHoldScope::Matter, 'Litigation.', $admin, matter: $matter);

        $request = app(DeletionRequestService::class)->request($firm, Matter::class, $matter->id, 'Reason.', $admin, offboardingExport: $export);
        $request->forceFill(['created_at' => now()->subYears(5)])->save();

        $this->expectException(\RuntimeException::class);
        app(DeletionGovernanceService::class)->submitForApproval($request->fresh(), RetentionRecordType::Matter);
    }

    public function test_approved_deletion_becomes_ready_for_execution_and_never_deletes_rows(): void
    {
        $firm = $this->makeGovernanceFirm();
        $admin1 = $this->makePlatformAdmin();
        $admin2 = $this->makePlatformAdmin();
        $matter = Matter::factory()->create(['firm_id' => $firm->id]);
        RetentionPolicy::factory()->create([
            'firm_id' => null,
            'record_type' => RetentionRecordType::Matter,
            'retention_period_days' => 1,
            'status' => RetentionPolicyStatus::Active,
        ]);

        $export = $this->verifiedExportFor($firm, $admin1);

        $request = app(DeletionRequestService::class)->request($firm, Matter::class, $matter->id, 'Retention cleared.', $admin1, offboardingExport: $export);
        // created_at is intentionally not mass-assignable (governance
        // evidence), so backdating it for this test must bypass mass
        // assignment via forceFill(), not update().
        $request->forceFill(['created_at' => now()->subYears(5)])->save();

        app(DeletionGovernanceService::class)->submitForApproval($request->fresh(), RetentionRecordType::Matter);

        $approvalService = app(DeletionApprovalService::class);
        $approval = $approvalService->requestApproval($request->fresh(), $admin1, 'Governed hard delete.');
        $approvalService->firstApprove($approval, $admin1);
        $approvalService->secondApprove($approval->fresh(), $admin2);

        $finalRequest = $request->fresh();
        $this->assertSame(DeletionRequestStatus::ReadyForExecution, $finalRequest->status);

        // Phase 17 never physically deletes the target row.
        $this->assertDatabaseHas('matters', ['id' => $matter->id]);
    }

    public function test_deletion_approval_uses_production_data_deletion_change_type(): void
    {
        $firm = $this->makeGovernanceFirm();
        $admin = $this->makePlatformAdmin();
        $matter = Matter::factory()->create(['firm_id' => $firm->id]);

        $request = app(DeletionRequestService::class)->request($firm, Matter::class, $matter->id, 'Reason.', $admin);
        $approval = app(DeletionApprovalService::class)->requestApproval($request, $admin, 'Reason.');

        $this->assertSame(
            HighRiskChangeType::ProductionDataDeletion,
            $approval->highRiskChangeRequest->change_type,
        );
    }

    public function test_deletion_requests_can_never_be_deleted(): void
    {
        $firm = $this->makeGovernanceFirm();
        $admin = $this->makePlatformAdmin();
        $matter = Matter::factory()->create(['firm_id' => $firm->id]);

        $request = app(DeletionRequestService::class)->request($firm, Matter::class, $matter->id, 'Reason.', $admin);

        $this->expectException(\LogicException::class);
        $request->delete();
    }
}
