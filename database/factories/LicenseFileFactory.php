<?php

namespace Database\Factories;

use App\Enums\DeploymentMode;
use App\Models\Firm;
use App\Models\FirmLicense;
use App\Models\LicenseFile;
use App\Models\Organization;
use App\Models\OrgLicense;
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

    /**
     * Audit fix (eager-factory-side-effects audit): this used to call
     * Firm::factory()->create() and
     * FirmLicense::factory()->forFirm($firm)->create() as plain PHP
     * statements at the top of definition() — real, committed rows
     * every single time, even when forFirm()/organizationLevel() below
     * immediately override every key derived from them with
     * caller-supplied values. Fixed by memoizing the pair behind lazy
     * closures so nothing is created unless at least one of the derived
     * keys survives, unoverridden, to the final row.
     */
    private ?Firm $lazyFirm = null;

    private ?FirmLicense $lazyFirmLicense = null;

    public function definition(): array
    {
        $this->lazyFirm = null;
        $this->lazyFirmLicense = null;

        return [
            'firm_id' => function () {
                $this->resolveLazyFirmAndLicense();

                return $this->lazyFirm->id;
            },
            'organization_id' => null,
            'firm_license_id' => function () {
                $this->resolveLazyFirmAndLicense();

                return $this->lazyFirmLicense->id;
            },
            'org_license_id' => null,
            'licensed_to' => function () {
                $this->resolveLazyFirmAndLicense();

                return $this->lazyFirm->name;
            },
            'license_key' => function () {
                $this->resolveLazyFirmAndLicense();

                return $this->lazyFirmLicense->license_key;
            },
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

    private function resolveLazyFirmAndLicense(): void
    {
        $this->lazyFirm ??= Firm::factory()->create();
        $this->lazyFirmLicense ??= FirmLicense::factory()->forFirm($this->lazyFirm)->create();
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

    public function organizationLevel(Organization $organization, OrgLicense $orgLicense): static
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
