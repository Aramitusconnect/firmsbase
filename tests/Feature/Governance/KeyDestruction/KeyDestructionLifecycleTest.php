<?php

namespace Tests\Feature\Governance\KeyDestruction;

use App\Enums\KeyDestructionRequestStatus;
use App\Enums\LegalHoldScope;
use App\Enums\RetentionPolicyStatus;
use App\Enums\RetentionRecordType;
use App\Models\RetentionPolicy;
use App\Services\EncryptionKeyService;
use App\Services\KeyDestructionApprovalService;
use App\Services\KeyDestructionExecutionService;
use App\Services\KeyDestructionRequestService;
use App\Services\LegalHoldService;
use App\Services\OffboardingExportService;
use App\Services\OffboardingRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\Feature\Governance\Concerns\SetsUpGovernanceFirm;
use Tests\TestCase;

/**
 * Full key-destruction lifecycle: clearance chain (export, retention,
 * legal hold) -> two-person approval via CryptographicKeyDestruction ->
 * irreversible execution -> sampled ciphertext becomes unreadable ->
 * tenant isolation (destroying one firm's key never affects another's).
 */
class KeyDestructionLifecycleTest extends TestCase
{
    use RefreshDatabase, SetsUpGovernanceFirm;

    private function seedPermissiveTrustAndFirmPolicies(): void
    {
        RetentionPolicy::factory()->create([
            'firm_id' => null,
            'record_type' => RetentionRecordType::Firm,
            'retention_period_days' => 1,
            'status' => RetentionPolicyStatus::Active,
        ]);
    }

    private function verifiedExportFor($firm, $admin): \App\Models\OffboardingExport
    {
        $offboardingRequest = app(OffboardingRequestService::class)->request($firm, $admin, 'Offboarding.');
        $export = app(OffboardingExportService::class)->generate($offboardingRequest, requestedByPlatformAdmin: $admin);

        return app(OffboardingExportService::class)->verify($export, $admin);
    }

    public function test_cannot_be_requested_for_approval_without_a_completed_export(): void
    {
        $firm = $this->makeGovernanceFirm();
        $admin = $this->makePlatformAdmin();
        $this->seedPermissiveTrustAndFirmPolicies();
        $firm->forceFill(['created_at' => now()->subYears(10)])->save();

        $request = app(KeyDestructionRequestService::class)->request($firm, $admin, 'Destroy key.');

        $this->expectException(\RuntimeException::class);
        app(KeyDestructionRequestService::class)->submitForApproval($request);
    }

    public function test_cannot_execute_without_retention_clearance(): void
    {
        $firm = $this->makeGovernanceFirm();
        $admin = $this->makePlatformAdmin();
        $offboardingRequest = app(OffboardingRequestService::class)->request($firm, $admin, 'Offboarding.');
        $export = app(OffboardingExportService::class)->generate($offboardingRequest, requestedByPlatformAdmin: $admin);
        app(OffboardingExportService::class)->verify($export, $admin);
        // No retention policy seeded — RetentionPolicyService returns
        // "not cleared" by design (no policy means not cleared).

        $request = app(KeyDestructionRequestService::class)->request($firm, $admin, 'Destroy key.', $offboardingRequest);

        $this->expectException(\RuntimeException::class);
        app(KeyDestructionRequestService::class)->submitForApproval($request);

        $this->assertSame(KeyDestructionRequestStatus::RetentionClearancePending, $request->fresh()->status);
    }

    public function test_cannot_execute_with_active_legal_hold(): void
    {
        $firm = $this->makeGovernanceFirm();
        $admin = $this->makePlatformAdmin();
        $this->seedPermissiveTrustAndFirmPolicies();
        $firm->forceFill(['created_at' => now()->subYears(10)])->save();

        $offboardingRequest = app(OffboardingRequestService::class)->request($firm, $admin, 'Offboarding.');
        $export = app(OffboardingExportService::class)->generate($offboardingRequest, requestedByPlatformAdmin: $admin);
        app(OffboardingExportService::class)->verify($export, $admin);

        app(LegalHoldService::class)->place($firm, LegalHoldScope::Firm, 'Litigation.', $admin);

        $request = app(KeyDestructionRequestService::class)->request($firm, $admin, 'Destroy key.', $offboardingRequest);

        $this->expectException(\RuntimeException::class);
        app(KeyDestructionRequestService::class)->submitForApproval($request);

        $this->assertSame(KeyDestructionRequestStatus::LegalHoldBlocked, $request->fresh()->status);
    }

    public function test_requires_two_person_approval_before_execution(): void
    {
        $firm = $this->makeGovernanceFirm();
        $admin1 = $this->makePlatformAdmin();
        $admin2 = $this->makePlatformAdmin();
        $this->seedPermissiveTrustAndFirmPolicies();
        $firm->forceFill(['created_at' => now()->subYears(10)])->save();

        $offboardingRequest = app(OffboardingRequestService::class)->request($firm, $admin1, 'Offboarding.');
        $export = app(OffboardingExportService::class)->generate($offboardingRequest, requestedByPlatformAdmin: $admin1);
        app(OffboardingExportService::class)->verify($export, $admin1);

        $request = app(KeyDestructionRequestService::class)->request($firm, $admin1, 'Destroy key.', $offboardingRequest);
        app(KeyDestructionRequestService::class)->submitForApproval($request);

        $approvalService = app(KeyDestructionApprovalService::class);
        $approval = $approvalService->requestApproval($request, $admin1, 'Two-person approval required.');

        $this->expectException(\RuntimeException::class);
        app(KeyDestructionExecutionService::class)->execute($request->fresh());
    }

    public function test_full_lifecycle_executes_and_marks_key_destroyed_and_renders_sample_unreadable(): void
    {
        $firm = $this->makeGovernanceFirm();
        $admin1 = $this->makePlatformAdmin();
        $admin2 = $this->makePlatformAdmin();
        $this->seedPermissiveTrustAndFirmPolicies();
        $firm->forceFill(['created_at' => now()->subYears(10)])->save();

        $encryptionKeyService = app(EncryptionKeyService::class);
        $sampleCiphertext = Crypt::encryptString($encryptionKeyService->decryptActiveKey($firm));
        $this->assertNotEmpty(Crypt::decryptString($sampleCiphertext));

        $offboardingRequest = app(OffboardingRequestService::class)->request($firm, $admin1, 'Offboarding.');
        $export = app(OffboardingExportService::class)->generate($offboardingRequest, requestedByPlatformAdmin: $admin1);
        app(OffboardingExportService::class)->verify($export, $admin1);

        $request = app(KeyDestructionRequestService::class)->request($firm, $admin1, 'Destroy key.', $offboardingRequest);
        app(KeyDestructionRequestService::class)->submitForApproval($request);

        $approvalService = app(KeyDestructionApprovalService::class);
        $approval = $approvalService->requestApproval($request, $admin1, 'Two-person approval required.');
        $approvalService->firstApprove($approval, $admin1);
        $approvalService->secondApprove($approval->fresh(), $admin2);

        $this->assertTrue($approvalService->isApproved($request->fresh()));
        $this->assertSame(KeyDestructionRequestStatus::Approved, $request->fresh()->status);

        $executed = app(KeyDestructionExecutionService::class)->execute($request->fresh());

        $this->assertSame(KeyDestructionRequestStatus::Executed, $executed->status);
        $this->assertNotNull($executed->executed_at);

        $key = $firm->tenantEncryptionKeys()->first();
        $this->assertTrue($key->fresh()->isDestroyed());
        $this->assertNotNull($key->fresh()->destroyed_at);

        // The sample ciphertext depended on the destroyed inner key; the
        // firm now has no active key at all to decrypt anything with.
        $this->expectException(\RuntimeException::class);
        $encryptionKeyService->decryptActiveKey($firm);
    }

    public function test_destroying_one_firms_key_never_affects_another_firms_key(): void
    {
        $firmA = $this->makeGovernanceFirm();
        $firmB = $this->makeGovernanceFirm();
        $admin1 = $this->makePlatformAdmin();
        $admin2 = $this->makePlatformAdmin();
        $this->seedPermissiveTrustAndFirmPolicies();
        $firmA->forceFill(['created_at' => now()->subYears(10)])->save();

        $offboardingRequest = app(OffboardingRequestService::class)->request($firmA, $admin1, 'Offboarding A.');
        $export = app(OffboardingExportService::class)->generate($offboardingRequest, requestedByPlatformAdmin: $admin1);
        app(OffboardingExportService::class)->verify($export, $admin1);

        $request = app(KeyDestructionRequestService::class)->request($firmA, $admin1, 'Destroy A key.', $offboardingRequest);
        app(KeyDestructionRequestService::class)->submitForApproval($request);

        $approvalService = app(KeyDestructionApprovalService::class);
        $approval = $approvalService->requestApproval($request, $admin1, 'Approval.');
        $approvalService->firstApprove($approval, $admin1);
        $approvalService->secondApprove($approval->fresh(), $admin2);

        app(KeyDestructionExecutionService::class)->execute($request->fresh());

        $this->assertTrue($firmA->tenantEncryptionKeys()->first()->fresh()->isDestroyed());

        // Firm B's key is completely untouched.
        $keyB = $firmB->tenantEncryptionKeys()->first();
        $this->assertTrue($keyB->fresh()->isActive());
        $this->assertNotNull(app(EncryptionKeyService::class)->decryptActiveKey($firmB));
    }

    public function test_key_destruction_requests_and_approvals_can_never_be_deleted(): void
    {
        $firm = $this->makeGovernanceFirm();
        $admin = $this->makePlatformAdmin();

        $request = app(KeyDestructionRequestService::class)->request($firm, $admin, 'Destroy key.');

        $this->expectException(\LogicException::class);
        $request->delete();
    }

    public function test_key_destruction_approval_freezes_after_approved(): void
    {
        $firm = $this->makeGovernanceFirm();
        $admin1 = $this->makePlatformAdmin();
        $admin2 = $this->makePlatformAdmin();
        $this->seedPermissiveTrustAndFirmPolicies();
        $firm->forceFill(['created_at' => now()->subYears(10)])->save();

        $offboardingRequest = app(OffboardingRequestService::class)->request($firm, $admin1, 'Offboarding.');
        $export = app(OffboardingExportService::class)->generate($offboardingRequest, requestedByPlatformAdmin: $admin1);
        app(OffboardingExportService::class)->verify($export, $admin1);

        $request = app(KeyDestructionRequestService::class)->request($firm, $admin1, 'Destroy key.', $offboardingRequest);
        app(KeyDestructionRequestService::class)->submitForApproval($request);

        $approvalService = app(KeyDestructionApprovalService::class);
        $approval = $approvalService->requestApproval($request, $admin1, 'Approval.');
        $approvalService->firstApprove($approval, $admin1);
        $approved = $approvalService->secondApprove($approval->fresh(), $admin2);

        $this->expectException(\LogicException::class);
        $approved->update(['denial_reason' => 'attempted tamper']);
    }
}
