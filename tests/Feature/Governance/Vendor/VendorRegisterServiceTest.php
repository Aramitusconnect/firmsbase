<?php

namespace Tests\Feature\Governance\Vendor;

use App\Enums\VendorStatus;
use App\Models\Vendor;
use App\Services\SubprocessorDisclosureService;
use App\Services\VendorRegisterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Governance\Concerns\SetsUpGovernanceFirm;
use Tests\TestCase;

class VendorRegisterServiceTest extends TestCase
{
    use RefreshDatabase, SetsUpGovernanceFirm;

    public function test_vendor_register_required_fields_are_enforced(): void
    {
        $vendor = Vendor::factory()->create();

        $this->assertNotEmpty($vendor->vendor_name);
        $this->assertNotEmpty($vendor->service_purpose);
        $this->assertNotNull($vendor->data_category);
        $this->assertNotNull($vendor->risk_level);
        $this->assertNotNull($vendor->dpa_status);
        $this->assertNotNull($vendor->soc_report_status);
        $this->assertNotNull($vendor->ai_zero_retention_status);
        $this->assertNotEmpty($vendor->incident_contact_name);
        $this->assertNotEmpty($vendor->incident_contact_email);
    }

    public function test_mark_under_review_and_terminate_transitions(): void
    {
        $admin = $this->makePlatformAdmin();
        $vendor = app(VendorRegisterService::class)->register([
            'vendor_name' => 'Test Vendor',
            'service_purpose' => 'Testing.',
            'data_category' => \App\Enums\DataCategory::Pii,
            'risk_level' => \App\Enums\VendorRiskLevel::Low,
            'dpa_status' => \App\Enums\VendorDpaStatus::Signed,
            'soc_report_status' => \App\Enums\VendorSocReportStatus::Received,
            'incident_contact_name' => 'Security Team',
            'incident_contact_email' => 'security@test.example',
        ], $admin);

        $this->assertSame(VendorStatus::Active, $vendor->status);

        $underReview = app(VendorRegisterService::class)->markUnderReview($vendor);
        $this->assertSame(VendorStatus::UnderReview, $underReview->status);

        $terminated = app(VendorRegisterService::class)->terminate($underReview);
        $this->assertSame(VendorStatus::Terminated, $terminated->status);
    }

    public function test_subprocessor_requires_a_valid_vendor_register_row(): void
    {
        $vendor = Vendor::factory()->create();

        $subprocessor = app(SubprocessorDisclosureService::class)->disclose($vendor, [
            'disclosed_name' => $vendor->vendor_name,
            'service_purpose' => $vendor->service_purpose,
        ]);

        $this->assertSame($vendor->id, $subprocessor->vendor_register_id);
        $this->assertDatabaseHas('subprocessors', ['id' => $subprocessor->id, 'vendor_register_id' => $vendor->id]);
    }

    public function test_subprocessor_creation_fails_for_a_nonexistent_vendor(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        \App\Models\Subprocessor::factory()->create(['vendor_register_id' => 999999]);
    }

    public function test_data_processing_record_can_link_vendor_subprocessor_and_retention_policy(): void
    {
        $vendor = Vendor::factory()->create();
        $subprocessor = app(SubprocessorDisclosureService::class)->disclose($vendor, [
            'disclosed_name' => $vendor->vendor_name,
            'service_purpose' => $vendor->service_purpose,
        ]);
        $policy = \App\Models\RetentionPolicy::factory()->create();
        $admin = $this->makePlatformAdmin();

        $record = app(\App\Services\DataProcessingRecordService::class)->record([
            'record_type' => \App\Enums\DataProcessingRecordType::DocumentStorage,
            'purpose' => 'Storing documents.',
            'vendor_register_id' => $vendor->id,
            'subprocessor_id' => $subprocessor->id,
        ], $admin);

        $linked = app(\App\Services\DataProcessingRecordService::class)->linkRetentionPolicy($record, $policy);

        $this->assertSame($vendor->id, $linked->vendor_register_id);
        $this->assertSame($subprocessor->id, $linked->subprocessor_id);
        $this->assertSame($policy->id, $linked->retention_policy_id);
    }
}
