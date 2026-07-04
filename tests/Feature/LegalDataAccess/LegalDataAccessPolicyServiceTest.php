<?php

namespace Tests\Feature\LegalDataAccess;

use App\Enums\LicenseStatus;
use App\Models\Firm;
use App\Models\FirmLicense;
use App\Services\LegalDataAccessPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LegalDataAccessPolicyServiceTest extends TestCase
{
    use RefreshDatabase;

    private LegalDataAccessPolicyService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new LegalDataAccessPolicyService();
    }

    /**
     * @return array<string, array<int, LicenseStatus|bool>>
     */
    public static function fullAccessProvider(): array
    {
        return [
            'trial' => [LicenseStatus::Trial],
            'active' => [LicenseStatus::Active],
            'grace_period' => [LicenseStatus::GracePeriod],
            'manual' => [LicenseStatus::Manual],
            'lifetime' => [LicenseStatus::Lifetime],
        ];
    }

    #[DataProvider('fullAccessProvider')]
    public function test_full_access_statuses_permit_read_and_write(LicenseStatus $status): void
    {
        $firm = Firm::factory()->create();
        FirmLicense::factory()->create(['firm_id' => $firm->id, 'license_status' => $status]);

        $this->assertTrue($this->service->canRead($firm));
        $this->assertTrue($this->service->canWrite($firm));
        $this->assertTrue($this->service->canExport($firm));
    }

    /**
     * The required proof: past-due/restricted/read-only firms must
     * NOT be abruptly locked out of legal data — read access must
     * remain available, only writes are blocked.
     */
    public static function readOnlyProvider(): array
    {
        return [
            'past_due' => [LicenseStatus::PastDue],
            'restricted' => [LicenseStatus::Restricted],
            'read_only' => [LicenseStatus::ReadOnly],
        ];
    }

    #[DataProvider('readOnlyProvider')]
    public function test_read_only_statuses_permit_read_but_block_write(LicenseStatus $status): void
    {
        $firm = Firm::factory()->create();
        FirmLicense::factory()->create(['firm_id' => $firm->id, 'license_status' => $status]);

        $this->assertTrue($this->service->canRead($firm), "{$status->value} must not be abruptly locked out of read access");
        $this->assertFalse($this->service->canWrite($firm));
        $this->assertTrue($this->service->canExport($firm));
    }

    /**
     * Suspended/export-only/cancelled/expired firms lose interactive
     * read access but a GOVERNED export always remains available —
     * "Suspension must not destroy or hide legal data" (PDF).
     */
    public static function exportOnlyProvider(): array
    {
        return [
            'suspended' => [LicenseStatus::Suspended],
            'export_only' => [LicenseStatus::ExportOnly],
            'cancelled' => [LicenseStatus::Cancelled],
            'expired' => [LicenseStatus::Expired],
        ];
    }

    #[DataProvider('exportOnlyProvider')]
    public function test_export_only_statuses_still_permit_a_governed_export(LicenseStatus $status): void
    {
        $firm = Firm::factory()->create();
        FirmLicense::factory()->create(['firm_id' => $firm->id, 'license_status' => $status]);

        $this->assertFalse($this->service->canWrite($firm));
        $this->assertTrue($this->service->canExport($firm), "{$status->value} data must never be destroyed or made completely inaccessible");
    }

    public function test_a_firm_with_no_license_at_all_defaults_to_unrestricted_this_is_not_a_denial_case(): void
    {
        $firm = Firm::factory()->create();

        $this->assertTrue($this->service->canRead($firm));
        $this->assertTrue($this->service->canWrite($firm));
    }

    public function test_the_most_restrictive_license_wins_when_a_firm_somehow_has_multiple(): void
    {
        $firm = Firm::factory()->create();
        FirmLicense::factory()->create(['firm_id' => $firm->id, 'license_status' => LicenseStatus::Active]);
        FirmLicense::factory()->create(['firm_id' => $firm->id, 'license_status' => LicenseStatus::Suspended]);

        $this->assertSame(LicenseStatus::Suspended, $this->service->currentStatus($firm));
        $this->assertFalse($this->service->canWrite($firm));
    }

    public function test_reuses_phase_1_license_status_no_new_status_field_exists(): void
    {
        $firm = Firm::factory()->create();
        FirmLicense::factory()->create(['firm_id' => $firm->id, 'license_status' => LicenseStatus::Active]);

        $status = $this->service->currentStatus($firm);

        $this->assertInstanceOf(LicenseStatus::class, $status);
    }
}
