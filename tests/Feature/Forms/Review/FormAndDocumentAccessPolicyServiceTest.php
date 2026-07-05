<?php

namespace Tests\Feature\Forms\Review;

use App\Enums\EntitlementSource;
use App\Enums\FirmUserRole;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Services\EntitlementService;
use App\Services\FormAndDocumentAccessPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FormAndDocumentAccessPolicyService reuses the existing Phase 6
 * seeded module_catalog codes 'forms' and 'document_generation' — no
 * new entitlement system, no new module_catalog row.
 */
class FormAndDocumentAccessPolicyServiceTest extends TestCase
{
    use RefreshDatabase;

    private FormAndDocumentAccessPolicyService $service;
    private EntitlementService $entitlements;

    protected function setUp(): void
    {
        parent::setUp();
        $this->entitlements = app(EntitlementService::class);
        $this->service = new FormAndDocumentAccessPolicyService($this->entitlements);
    }

    public function test_forms_module_is_disabled_by_default(): void
    {
        $firm = Firm::factory()->create();

        $this->assertFalse($this->service->canUseForms($firm->id));
        $this->assertFalse($this->service->canUseDocumentGeneration($firm->id));
    }

    public function test_enabling_the_forms_entitlement_flips_can_use_forms(): void
    {
        $firm = Firm::factory()->create();
        $this->entitlements->setForSource($firm, 'forms', EntitlementSource::AdminOverride, true);

        $this->assertTrue($this->service->canUseForms($firm->id));
        $this->assertFalse($this->service->canUseDocumentGeneration($firm->id));
    }

    public function test_can_generate_allows_generation_roles_only(): void
    {
        $firm = Firm::factory()->create();

        foreach ([FirmUserRole::FirmOwner, FirmUserRole::Attorney, FirmUserRole::Paralegal, FirmUserRole::LegalAssistant] as $role) {
            $user = FirmUser::factory()->role($role)->create(['firm_id' => $firm->id]);
            $this->assertTrue($this->service->canGenerate($user), "{$role->value} should be able to generate.");
        }

        foreach ([FirmUserRole::Receptionist, FirmUserRole::BillingStaff] as $role) {
            $user = FirmUser::factory()->role($role)->create(['firm_id' => $firm->id]);
            $this->assertFalse($this->service->canGenerate($user), "{$role->value} should not be able to generate.");
        }
    }

    public function test_can_approve_allows_only_firm_owner_and_attorney(): void
    {
        $firm = Firm::factory()->create();

        foreach ([FirmUserRole::FirmOwner, FirmUserRole::Attorney] as $role) {
            $user = FirmUser::factory()->role($role)->create(['firm_id' => $firm->id]);
            $this->assertTrue($this->service->canApprove($user), "{$role->value} should be able to approve.");
        }

        foreach ([FirmUserRole::Paralegal, FirmUserRole::LegalAssistant, FirmUserRole::Receptionist, FirmUserRole::BillingStaff] as $role) {
            $user = FirmUser::factory()->role($role)->create(['firm_id' => $firm->id]);
            $this->assertFalse($this->service->canApprove($user), "{$role->value} should not be able to approve.");
        }
    }
}
