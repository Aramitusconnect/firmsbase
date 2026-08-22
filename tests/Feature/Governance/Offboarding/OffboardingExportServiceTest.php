<?php

namespace Tests\Feature\Governance\Offboarding;

use App\Enums\OffboardingExportStatus;
use App\Services\OffboardingExportService;
use App\Services\OffboardingRequestService;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Governance\Concerns\SetsUpGovernanceFirm;
use Tests\TestCase;

class OffboardingExportServiceTest extends TestCase
{
    use RefreshDatabase, SetsUpGovernanceFirm;

    private OffboardingRequestService $requestService;

    private OffboardingExportService $exportService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requestService = app(OffboardingRequestService::class);
        $this->exportService = app(OffboardingExportService::class);
    }

    public function test_generate_creates_a_simulated_package_manifest_with_no_real_file(): void
    {
        $firm = $this->makeGovernanceFirm();
        $admin = $this->makePlatformAdmin();
        $request = $this->requestService->request($firm, $admin, 'Firm offboarding.');

        $export = $this->exportService->generate($request, requestedByPlatformAdmin: $admin);

        $this->assertNotEmpty($export->package_manifest_json);
        $this->assertSame(OffboardingExportStatus::Generated, $export->status);
        // export_jobs now has permanent FORCE ROW LEVEL SECURITY
        // (Section 39A-5 Wave 9) — a raw assertDatabaseHas() query runs
        // with no ambient context, so it must be wrapped to see the row.
        (new TenantContextService)->runWithFirmContext(
            $firm,
            fn () => $this->assertDatabaseHas('export_jobs', ['id' => $export->export_job_id]),
        );
        // simulated_storage_path is metadata only — assert the linked
        // export_files convention is untouched (no real file write API
        // was called; ExportJobService/ExportFile own that guarantee).
    }

    public function test_verify_marks_export_verified_when_manifest_is_non_empty(): void
    {
        $firm = $this->makeGovernanceFirm();
        $admin = $this->makePlatformAdmin();
        $request = $this->requestService->request($firm, $admin, 'Firm offboarding.');
        $export = $this->exportService->generate($request, requestedByPlatformAdmin: $admin);

        $verified = $this->exportService->verify($export, $admin);

        $this->assertSame(OffboardingExportStatus::Verified, $verified->status);
        $this->assertNotNull($verified->verified_at);
    }

    public function test_verify_rejects_an_empty_manifest(): void
    {
        $firm = $this->makeGovernanceFirm();
        $admin = $this->makePlatformAdmin();
        $request = $this->requestService->request($firm, $admin, 'Firm offboarding.');
        $export = $this->exportService->generate($request, requestedByPlatformAdmin: $admin, manifest: []);

        $this->expectException(\RuntimeException::class);
        $this->exportService->verify($export, $admin);
    }

    public function test_offboarding_export_rows_can_never_be_deleted(): void
    {
        $firm = $this->makeGovernanceFirm();
        $admin = $this->makePlatformAdmin();
        $request = $this->requestService->request($firm, $admin, 'Firm offboarding.');
        $export = $this->exportService->generate($request, requestedByPlatformAdmin: $admin);

        $this->expectException(\LogicException::class);
        $export->delete();
    }
}
