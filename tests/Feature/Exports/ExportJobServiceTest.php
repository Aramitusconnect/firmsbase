<?php

namespace Tests\Feature\Exports;

use App\Enums\ExportJobStatus;
use App\Enums\ExportType;
use App\Enums\LicenseStatus;
use App\Models\Firm;
use App\Models\FirmLicense;
use App\Models\FirmUser;
use App\Services\ExportGovernancePolicyService;
use App\Services\ExportJobService;
use App\Services\LegalDataAccessPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExportJobServiceTest extends TestCase
{
    use RefreshDatabase;

    private ExportJobService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ExportJobService(new ExportGovernancePolicyService(new LegalDataAccessPolicyService()));
    }

    public function test_request_creates_a_firm_scoped_job(): void
    {
        $firm = Firm::factory()->create();
        FirmLicense::factory()->create(['firm_id' => $firm->id, 'license_status' => LicenseStatus::Active->value]);
        $actor = FirmUser::factory()->forFirm($firm)->create();

        $job = $this->service->request($firm, ExportType::Clients, $actor);

        $this->assertSame($firm->id, $job->firm_id);
        $this->assertSame(ExportJobStatus::Requested, $job->status);
    }

    public function test_unauthorized_export_is_blocked_by_governance(): void
    {
        $firm = Firm::factory()->create();
        FirmLicense::factory()->create(['firm_id' => $firm->id, 'license_status' => LicenseStatus::Active->value]);
        $actor = FirmUser::factory()->forFirm($firm)->create();

        $job = $this->service->request($firm, ExportType::Clients, $actor, hasActiveLegalHold: true);

        $this->assertSame(ExportJobStatus::Blocked, $job->status);
        $this->assertNotNull($job->failed_reason);
    }

    public function test_mark_in_progress_and_completed(): void
    {
        $firm = Firm::factory()->create();
        FirmLicense::factory()->create(['firm_id' => $firm->id, 'license_status' => LicenseStatus::Active->value]);
        $actor = FirmUser::factory()->forFirm($firm)->create();
        $job = $this->service->request($firm, ExportType::Documents, $actor);

        $inProgress = $this->service->markInProgress($job);
        $this->assertSame(ExportJobStatus::InProgress, $inProgress->status);

        $completed = $this->service->markCompleted($inProgress);
        $this->assertSame(ExportJobStatus::Completed, $completed->status);
        $this->assertNotNull($completed->completed_at);
    }
}
