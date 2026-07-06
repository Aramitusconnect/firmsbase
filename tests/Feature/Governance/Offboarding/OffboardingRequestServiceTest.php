<?php

namespace Tests\Feature\Governance\Offboarding;

use App\Enums\LegalHoldScope;
use App\Enums\OffboardingRequestStatus;
use App\Enums\RetentionPolicyStatus;
use App\Enums\RetentionRecordType;
use App\Models\RetentionPolicy;
use App\Services\LegalHoldService;
use App\Services\OffboardingExportService;
use App\Services\OffboardingRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Governance\Concerns\SetsUpGovernanceFirm;
use Tests\TestCase;

class OffboardingRequestServiceTest extends TestCase
{
    use RefreshDatabase, SetsUpGovernanceFirm;

    public function test_request_starts_in_requested_status(): void
    {
        $firm = $this->makeGovernanceFirm();
        $admin = $this->makePlatformAdmin();

        $request = app(OffboardingRequestService::class)->request($firm, $admin, 'Firm cancelled.');

        $this->assertSame(OffboardingRequestStatus::Requested, $request->status);
    }

    public function test_advance_reaches_ready_for_deletion_once_export_retention_and_legal_hold_all_clear(): void
    {
        $firm = $this->makeGovernanceFirm();
        $admin = $this->makePlatformAdmin();
        $requestService = app(OffboardingRequestService::class);
        $exportService = app(OffboardingExportService::class);

        RetentionPolicy::factory()->create([
            'firm_id' => null,
            'record_type' => RetentionRecordType::Firm,
            'retention_period_days' => 1,
            'status' => RetentionPolicyStatus::Active,
        ]);

        $request = $requestService->request($firm, $admin, 'Firm cancelled.');
        $export = $exportService->generate($request, requestedByPlatformAdmin: $admin);
        $exportService->verify($export, $admin);

        // Force firm.created_at far enough in the past for retention to clear.
        $firm->forceFill(['created_at' => now()->subYears(10)])->save();

        $advanced = $requestService->advance($request);

        $this->assertSame(OffboardingRequestStatus::ReadyForDeletion, $advanced->status);
    }

    public function test_advance_reports_legal_hold_blocked_when_an_active_firm_hold_exists(): void
    {
        $firm = $this->makeGovernanceFirm();
        $admin = $this->makePlatformAdmin();
        $requestService = app(OffboardingRequestService::class);
        $exportService = app(OffboardingExportService::class);

        $request = $requestService->request($firm, $admin, 'Firm cancelled.');
        $export = $exportService->generate($request, requestedByPlatformAdmin: $admin);
        $exportService->verify($export, $admin);

        app(LegalHoldService::class)->place($firm, LegalHoldScope::Firm, 'Litigation pending.', $admin);

        $advanced = $requestService->advance($request);

        $this->assertSame(OffboardingRequestStatus::LegalHoldBlocked, $advanced->status);
    }

    public function test_advance_reports_export_pending_when_no_verified_export_exists(): void
    {
        $firm = $this->makeGovernanceFirm();
        $admin = $this->makePlatformAdmin();
        $requestService = app(OffboardingRequestService::class);

        $request = $requestService->request($firm, $admin, 'Firm cancelled.');

        $advanced = $requestService->advance($request);

        $this->assertSame(OffboardingRequestStatus::ExportPending, $advanced->status);
    }
}
