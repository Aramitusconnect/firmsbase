<?php

namespace Tests\Feature\Deployment\License;

use App\Enums\DeploymentMode;
use App\Enums\LicenseStatus;
use App\Enums\LicenseValidationEventType;
use App\Enums\LicenseValidationResult;
use App\Models\LicenseFile;
use App\Models\Organization;
use App\Models\OrgLicense;
use App\Models\User;
use App\Services\LicenseFileSigningService;
use App\Services\LicenseFileValidationService;
use App\ValueObjects\SignedLicensePayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Deployment\Concerns\SetsUpDeploymentFirm;
use Tests\TestCase;

/**
 * Approved license design: PHP-native (sodium/Ed25519) signing and
 * offline validation, no outbound connectivity of any kind. Every
 * validate() call writes exactly one license_validation_events row.
 */
class LicenseFileSigningAndValidationServiceTest extends TestCase
{
    use RefreshDatabase, SetsUpDeploymentFirm;

    private LicenseFileSigningService $signing;
    private LicenseFileValidationService $validation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signing = app(LicenseFileSigningService::class);
        $this->validation = app(LicenseFileValidationService::class);
    }

    private function payloadFor(string $licenseKey, \DateTimeInterface $expiresAt, int $graceDays = 14): SignedLicensePayload
    {
        return new SignedLicensePayload(
            licensedTo: 'Test Firm LLC',
            licenseKey: $licenseKey,
            expiresAt: $expiresAt,
            deploymentMode: DeploymentMode::Dedicated,
            allowedModules: ['forms', 'document_generation'],
            allowedUsers: 25,
            allowedSeatClasses: ['attorney', 'staff'],
            allowedPracticeAreas: ['immigration'],
            supportLevel: 'standard',
            renewalRules: 'auto-renew annually',
            gracePeriodDays: $graceDays,
        );
    }

    public function test_a_correctly_signed_license_file_validates_successfully_offline(): void
    {
        $firm = $this->makeDeploymentFirm();
        $firmLicense = $this->makeFirmLicenseFor($firm);
        $keys = $this->signing->generateKeypair();

        $signed = $this->signing->sign($this->payloadFor($firmLicense->license_key, now()->addYear()), $keys['secretKey']);

        $licenseFile = LicenseFile::factory()->forFirm($firm, $firmLicense)->create([
            'signed_payload' => $signed['signedPayloadJson'],
            'signature' => $signed['signature'],
            'signature_algorithm' => $signed['algorithm'],
            'expires_at' => now()->addYear(),
            'grace_period_days' => 14,
        ]);

        $outcome = $this->validation->validate($licenseFile, $keys['publicKey'], $firmLicense->license_key);

        $this->assertTrue($outcome->isValid());
        $this->assertSame(LicenseValidationEventType::Validated, $outcome->eventType);
        $this->assertDatabaseHas('license_validation_events', ['license_file_id' => $licenseFile->id, 'result' => LicenseValidationResult::Valid->value]);
    }

    public function test_an_invalid_signature_fails_validation(): void
    {
        $firm = $this->makeDeploymentFirm();
        $firmLicense = $this->makeFirmLicenseFor($firm);
        $keys = $this->signing->generateKeypair();
        $wrongKeys = $this->signing->generateKeypair();

        $signed = $this->signing->sign($this->payloadFor($firmLicense->license_key, now()->addYear()), $keys['secretKey']);

        $licenseFile = LicenseFile::factory()->forFirm($firm, $firmLicense)->create([
            'signed_payload' => $signed['signedPayloadJson'],
            'signature' => $signed['signature'],
            'signature_algorithm' => $signed['algorithm'],
        ]);

        // Validate against the WRONG public key.
        $outcome = $this->validation->validate($licenseFile, $wrongKeys['publicKey'], $firmLicense->license_key);

        $this->assertFalse($outcome->isValid());
        $this->assertSame(LicenseValidationEventType::SignatureInvalid, $outcome->eventType);
        $this->assertDatabaseHas('license_validation_events', ['license_file_id' => $licenseFile->id, 'event_type' => LicenseValidationEventType::SignatureInvalid->value]);
    }

    public function test_an_expired_license_within_grace_period_enters_grace(): void
    {
        $firm = $this->makeDeploymentFirm();
        $firmLicense = $this->makeFirmLicenseFor($firm);
        $keys = $this->signing->generateKeypair();

        $expiresAt = now()->subDays(3);
        $signed = $this->signing->sign($this->payloadFor($firmLicense->license_key, $expiresAt, 14), $keys['secretKey']);

        $licenseFile = LicenseFile::factory()->forFirm($firm, $firmLicense)->create([
            'signed_payload' => $signed['signedPayloadJson'],
            'signature' => $signed['signature'],
            'expires_at' => $expiresAt,
            'grace_period_days' => 14,
        ]);

        $outcome = $this->validation->validate($licenseFile, $keys['publicKey'], $firmLicense->license_key);

        $this->assertTrue($outcome->isGrace());
        $this->assertSame(LicenseValidationEventType::EnteredGrace, $outcome->eventType);
        $this->assertSame(LicenseStatus::GracePeriod, $firmLicense->fresh()->license_status);
    }

    public function test_grace_expired_becomes_invalid_and_restricted(): void
    {
        $firm = $this->makeDeploymentFirm();
        $firmLicense = $this->makeFirmLicenseFor($firm);
        $keys = $this->signing->generateKeypair();

        $expiresAt = now()->subDays(30);
        $signed = $this->signing->sign($this->payloadFor($firmLicense->license_key, $expiresAt, 14), $keys['secretKey']);

        $licenseFile = LicenseFile::factory()->forFirm($firm, $firmLicense)->create([
            'signed_payload' => $signed['signedPayloadJson'],
            'signature' => $signed['signature'],
            'expires_at' => $expiresAt,
            'grace_period_days' => 14,
        ]);

        $outcome = $this->validation->validate($licenseFile, $keys['publicKey'], $firmLicense->license_key);

        $this->assertFalse($outcome->isValid());
        $this->assertSame(LicenseValidationEventType::GraceExpired, $outcome->eventType);
        $this->assertSame(LicenseStatus::Restricted, $firmLicense->fresh()->license_status);
        // Never brick the instance / never delete legal data — this
        // service never deletes anything and the firm row is untouched.
        $this->assertDatabaseHas('firms', ['id' => $firm->id]);
    }

    public function test_a_revoked_license_fails_validation_regardless_of_dates(): void
    {
        $firm = $this->makeDeploymentFirm();
        $firmLicense = $this->makeFirmLicenseFor($firm);
        $keys = $this->signing->generateKeypair();

        $signed = $this->signing->sign($this->payloadFor($firmLicense->license_key, now()->addYear()), $keys['secretKey']);

        $licenseFile = LicenseFile::factory()->forFirm($firm, $firmLicense)->revoked()->create([
            'signed_payload' => $signed['signedPayloadJson'],
            'signature' => $signed['signature'],
        ]);

        $outcome = $this->validation->validate($licenseFile, $keys['publicKey'], $firmLicense->license_key);

        $this->assertFalse($outcome->isValid());
        $this->assertSame(LicenseValidationEventType::RevokedCheck, $outcome->eventType);
    }

    public function test_every_validation_call_writes_exactly_one_license_validation_events_row(): void
    {
        $firm = $this->makeDeploymentFirm();
        $firmLicense = $this->makeFirmLicenseFor($firm);
        $keys = $this->signing->generateKeypair();

        $signed = $this->signing->sign($this->payloadFor($firmLicense->license_key, now()->addYear()), $keys['secretKey']);

        $licenseFile = LicenseFile::factory()->forFirm($firm, $firmLicense)->create([
            'signed_payload' => $signed['signedPayloadJson'],
            'signature' => $signed['signature'],
        ]);

        $this->validation->validate($licenseFile, $keys['publicKey'], $firmLicense->license_key);
        $this->validation->validate($licenseFile, $keys['publicKey'], $firmLicense->license_key);

        $this->assertDatabaseCount('license_validation_events', 2);
    }

    public function test_firm_level_and_organization_level_license_files_are_both_supported(): void
    {
        $firm = $this->makeDeploymentFirm();
        $firmLicense = $this->makeFirmLicenseFor($firm);
        $firmLevel = LicenseFile::factory()->forFirm($firm, $firmLicense)->create();

        $this->assertTrue($firmLevel->fresh()->isFirmLevel());
        $this->assertFalse($firmLevel->fresh()->isOrganizationLevel());

        $organization = Organization::factory()->create();
        $orgLicense = OrgLicense::factory()->forOrganization($organization)->create();
        $orgLevel = LicenseFile::factory()->organizationLevel($organization, $orgLicense)->create([
            'issued_by' => User::factory(),
        ]);

        $this->assertTrue($orgLevel->fresh()->isOrganizationLevel());
        $this->assertFalse($orgLevel->fresh()->isFirmLevel());
    }

    public function test_license_files_exactly_one_owner_path_constraint_rejects_both_paths_set(): void
    {
        $firm = $this->makeDeploymentFirm();
        $firmLicense = $this->makeFirmLicenseFor($firm);
        $organization = Organization::factory()->create();
        $orgLicense = OrgLicense::factory()->forOrganization($organization)->create();

        $this->expectException(\Illuminate\Database\QueryException::class);

        LicenseFile::factory()->forFirm($firm, $firmLicense)->create([
            'organization_id' => $organization->id,
            'org_license_id' => $orgLicense->id,
        ]);
    }

    public function test_no_signing_or_validation_call_makes_a_real_network_request(): void
    {
        foreach (['LicenseFileSigningService.php', 'LicenseFileValidationService.php'] as $filename) {
            $source = file_get_contents(app_path("Services/{$filename}"));

            foreach (['Http::', 'curl_init', 'fsockopen', 'GuzzleHttp', 'dns_get_record', 'gethostbyname'] as $needle) {
                $this->assertStringNotContainsString($needle, $source, "{$filename} must not reference: {$needle}");
            }
        }
    }
}
