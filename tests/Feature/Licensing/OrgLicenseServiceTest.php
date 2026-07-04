<?php

namespace Tests\Feature\Licensing;

use App\Enums\LicenseStatus;
use App\Models\LicenseEvent;
use App\Models\Organization;
use App\Models\OrgLicense;
use App\Models\Plan;
use App\Services\OrgLicenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrgLicenseServiceTest extends TestCase
{
    use RefreshDatabase;

    private OrgLicenseService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new OrgLicenseService();
    }

    public function test_issue_creates_a_trial_license_and_an_issued_event(): void
    {
        $organization = Organization::factory()->create();
        $plan = Plan::factory()->create();

        $license = $this->service->issue($organization, $plan);

        $this->assertSame(LicenseStatus::Trial, $license->license_status);
        $this->assertSame($organization->id, $license->organization_id);
        $this->assertSame($plan->id, $license->plan_id);

        $event = LicenseEvent::query()
            ->where('licensable_type', OrgLicense::class)
            ->where('licensable_id', $license->id)
            ->first();

        $this->assertNotNull($event);
        $this->assertSame('issued', $event->event_type);
    }

    public function test_change_status_logs_from_and_to_status(): void
    {
        $organization = Organization::factory()->create();
        $plan = Plan::factory()->create();
        $license = $this->service->issue($organization, $plan);

        $updated = $this->service->changeStatus($license, LicenseStatus::Active, 'trial converted');

        $this->assertSame(LicenseStatus::Active, $updated->license_status);

        $event = LicenseEvent::query()
            ->where('licensable_type', OrgLicense::class)
            ->where('licensable_id', $license->id)
            ->where('event_type', 'status_changed')
            ->first();

        $this->assertNotNull($event);
        $this->assertSame('trial', $event->from_status);
        $this->assertSame('active', $event->to_status);
        $this->assertSame('trial converted', $event->reason);
    }
}
