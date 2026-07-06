<?php

namespace Database\Factories;

use App\Enums\DeploymentMode;
use App\Models\Firm;
use App\Models\FirmLicense;
use App\Models\LicenseFile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LicenseFile>
 *
 * Defaults to the FIRM-LEVEL owner path (firm_id + firm_license_id set,
 * organization_id/org_license_id left null) to satisfy the
 * license_files_exactly_one_owner_path CHECK constraint by default. Use
 * ->organizationLevel() for the other path. signed_payload/signature
 * below are placeholders, NOT a real signature — tests exercising a
 * genuine sign/verify round trip must go through
 * LicenseFileSigningService, matching every other placeholder-ciphertext
 * factory convention in this codebase (WebhookSecretFactory,
 * FirmAiProviderKeyFactory).
 */
class LicenseFileFactory extends Factory
{
    protected $model = LicenseFile::class;

    public function definition(): array
    {
        $firm = Firm::factory()->create();
        $firmLicense = FirmLicense::factory()->forFirm($firm)->create();

        return [
            'firm_id' => $firm->id,
            'organization_id' => null,
            'firm_license_id' => $firmLicense->id,
            'org_license_id' => null,
            'licensed_to' => $firm->name,
            'license_key' => $firmLicense->license_key,
            'signed_payload' => 'placeholder-payload-not-real',
            'signature' => 'placeholder-signature-not-real',
            'signature_algorithm' => 'ed25519',
            'deployment_mode' => DeploymentMode::Dedicated,
            'expires_at' => now()->addYear(),
            'grace_period_days' => 14,
            'issued_at' => now(),
            'issued_by' => User::factory(),
            'revoked_at' => null,
        ];
    }

    public function forFirm(Firm $firm, FirmLicense $firmLicense): static
    {
        return $this->state(fn () => [
            'firm_id' => $firm->id,
            'firm_license_id' => $firmLicense->id,
            'organization_id' => null,
            'org_license_id' => null,
            'licensed_to' => $firm->name,
            'license_key' => $firmLicense->license_key,
        ]);
    }

    public function organizationLevel(\App\Models\Organization $organization, \App\Models\OrgLicense $orgLicense): static
    {
        return $this->state(fn () => [
            'firm_id' => null,
            'firm_license_id' => null,
            'organization_id' => $organization->id,
            'org_license_id' => $orgLicense->id,
            'licensed_to' => $organization->name,
            'license_key' => $orgLicense->license_key,
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn () => ['revoked_at' => now()]);
    }
}
