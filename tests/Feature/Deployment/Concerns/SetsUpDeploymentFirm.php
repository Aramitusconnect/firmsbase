<?php

namespace Tests\Feature\Deployment\Concerns;

use App\Enums\CustomerType;
use App\Enums\DeploymentMode;
use App\Enums\LicenseStatus;
use App\Models\Firm;
use App\Models\FirmLicense;
use App\Models\PlatformAdmin;
use App\Services\EncryptionKeyService;
use Illuminate\Support\Str;

/**
 * Shared setup for Phase 16 deployment-governance tests: a firm in a
 * given deployment mode/customer type, with an active
 * TenantEncryptionKey (needed by LicenseFileSigningService/
 * LicenseFileValidationService's eventual reuse of the tenant
 * encryption chain for other sensitive fields, and for parity with
 * every other Phase's entitled-firm fixture) and a matching
 * FirmLicense row (extends the EXISTING Phase 6 firm_licenses table —
 * project rule 1).
 */
trait SetsUpDeploymentFirm
{
    protected function makeDeploymentFirm(
        DeploymentMode $mode = DeploymentMode::Dedicated,
        CustomerType $customerType = CustomerType::LawFirm,
    ): Firm {
        $firm = Firm::factory()->create([
            'deployment_mode' => $mode,
            'customer_type' => $customerType,
        ]);

        app(EncryptionKeyService::class)->provision($firm);

        $firm->firmSettings()->create([
            'payment_mode' => \App\Enums\PaymentMode::OperatingPaymentsOnly,
            'ai_mode' => \App\Enums\AiMode::Disabled,
        ]);

        return $firm->fresh(['firmSettings']);
    }

    protected function makeFirmLicenseFor(Firm $firm): FirmLicense
    {
        return FirmLicense::factory()->forFirm($firm)->create([
            'license_key' => strtoupper('LIC-'.Str::random(8)),
            'license_status' => LicenseStatus::Active,
        ]);
    }

    protected function makePlatformAdmin(): PlatformAdmin
    {
        return PlatformAdmin::factory()->create();
    }
}
