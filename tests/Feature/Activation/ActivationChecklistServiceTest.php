<?php

namespace Tests\Feature\Activation;

use App\Enums\ActivationChecklistStatus;
use App\Enums\FirmActivationStatus;
use App\Enums\LicenseStatus;
use App\Models\ActivationChecklistItem;
use App\Models\BillingAccount;
use App\Models\Firm;
use App\Models\FirmLicense;
use App\Models\FirmSettings;
use App\Models\TenantEncryptionKey;
use App\Services\ActivationChecklistService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivationChecklistServiceTest extends TestCase
{
    use RefreshDatabase;

    private ActivationChecklistService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ActivationChecklistService();
    }

    /**
     * @param  array<int, string>  $skip
     */
    private function eligibleFirm(array $skip = []): Firm
    {
        $firm = Firm::factory()->create([
            'billing_account_id' => in_array('billing_account', $skip, true)
                ? null
                : BillingAccount::factory()->create()->id,
        ]);

        if (! in_array('firm_settings', $skip, true)) {
            FirmSettings::factory()->create(['firm_id' => $firm->id]);
        }

        if (! in_array('license', $skip, true)) {
            FirmLicense::factory()->create([
                'firm_id' => $firm->id,
                'license_status' => LicenseStatus::Active,
            ]);
        }

        if (! in_array('checklist', $skip, true)) {
            $checklist = $this->service->createChecklist($firm);

            if (in_array('checklist_item_incomplete', $skip, true)) {
                ActivationChecklistItem::factory()->forChecklist($checklist)->create();
            }
        }

        if (! in_array('encryption_key', $skip, true)) {
            TenantEncryptionKey::factory()->forFirm($firm)->create();
        }

        return $firm->fresh();
    }

    public function test_is_eligible_true_when_all_gates_satisfied(): void
    {
        $firm = $this->eligibleFirm();

        $this->assertTrue($this->service->isEligible($firm));
        $this->assertSame([], $this->service->unmetRequirements($firm));
    }

    public function test_unmet_requirements_flags_missing_billing_account(): void
    {
        $firm = $this->eligibleFirm(skip: ['billing_account']);

        $this->assertContains('billing_account_missing', $this->service->unmetRequirements($firm));
    }

    public function test_unmet_requirements_flags_missing_firm_settings(): void
    {
        $firm = $this->eligibleFirm(skip: ['firm_settings']);

        $this->assertContains('firm_settings_missing', $this->service->unmetRequirements($firm));
    }

    public function test_unmet_requirements_flags_missing_usable_license(): void
    {
        $firm = $this->eligibleFirm(skip: ['license']);

        $this->assertContains('usable_license_missing', $this->service->unmetRequirements($firm));
    }

    public function test_cancelled_license_does_not_count_as_usable(): void
    {
        $firm = $this->eligibleFirm(skip: ['license']);
        FirmLicense::factory()->create([
            'firm_id' => $firm->id,
            'license_status' => LicenseStatus::Cancelled,
        ]);

        $this->assertContains('usable_license_missing', $this->service->unmetRequirements($firm->fresh()));
    }

    public function test_unmet_requirements_flags_missing_checklist(): void
    {
        $firm = $this->eligibleFirm(skip: ['checklist']);

        $this->assertContains('activation_checklist_missing', $this->service->unmetRequirements($firm));
    }

    public function test_unmet_requirements_flags_incomplete_checklist(): void
    {
        $firm = $this->eligibleFirm(skip: ['checklist_item_incomplete']);

        $this->assertContains('activation_checklist_incomplete', $this->service->unmetRequirements($firm));
    }

    public function test_unmet_requirements_flags_missing_encryption_key(): void
    {
        $firm = $this->eligibleFirm(skip: ['encryption_key']);

        $this->assertContains('tenant_encryption_key_missing', $this->service->unmetRequirements($firm));
    }

    public function test_activate_throws_when_gates_unmet(): void
    {
        $firm = $this->eligibleFirm(skip: ['billing_account']);

        $this->expectException(\RuntimeException::class);

        $this->service->activate($firm);
    }

    public function test_activate_transitions_firm_and_completes_checklist(): void
    {
        $firm = $this->eligibleFirm();

        $activated = $this->service->activate($firm);

        $this->assertSame(FirmActivationStatus::Activated, $activated->activation_status);

        // Section 39A-3L, Checkpoint 2: activate()'s own runWithFirmContext()
        // wrap has already cleared tenant context by the time it returns
        // (see ActivationChecklistService's class docblock). Re-reading the
        // now-force-protected activation_checklists row below is a fresh
        // query against the database, so it genuinely needs its own explicit
        // context — it is not the same in-memory relation the service loaded
        // internally.
        $checklist = $this->runWithFirmContext($firm, fn () => $activated->activationChecklist->fresh());

        $this->assertSame(ActivationChecklistStatus::Completed, $checklist->status);
        $this->assertNotNull($checklist->completed_at);
    }

    public function test_create_checklist_throws_if_one_already_exists(): void
    {
        $firm = $this->eligibleFirm();

        $this->expectException(\RuntimeException::class);

        $this->service->createChecklist($firm);
    }
}
