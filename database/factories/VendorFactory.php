<?php

namespace Database\Factories;

use App\Enums\DataCategory;
use App\Enums\VendorAiZeroRetentionStatus;
use App\Enums\VendorDpaStatus;
use App\Enums\VendorRiskLevel;
use App\Enums\VendorSocReportStatus;
use App\Enums\VendorStatus;
use App\Models\PlatformAdmin;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vendor>
 */
class VendorFactory extends Factory
{
    protected $model = Vendor::class;

    public function definition(): array
    {
        return [
            'vendor_name' => 'Example Cloud Storage Inc.',
            'service_purpose' => 'Document and file storage infrastructure.',
            'data_category' => DataCategory::LegalDocuments,
            'risk_level' => VendorRiskLevel::Medium,
            'dpa_status' => VendorDpaStatus::Signed,
            'soc_report_status' => VendorSocReportStatus::Received,
            'ai_zero_retention_status' => VendorAiZeroRetentionStatus::NotApplicable,
            'incident_contact_name' => 'Vendor Security Team',
            'incident_contact_email' => 'security@example-vendor.test',
            'status' => VendorStatus::Active,
            'added_by_platform_admin_id' => PlatformAdmin::factory(),
            'added_at' => now(),
        ];
    }
}
