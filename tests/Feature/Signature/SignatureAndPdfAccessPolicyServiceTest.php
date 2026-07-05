<?php

namespace Tests\Feature\Signature;

use App\Enums\EntitlementSource;
use App\Enums\FirmUserRole;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Services\EntitlementService;
use App\Services\SignatureAndPdfAccessPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SignatureAndPdfAccessPolicyServiceTest extends TestCase
{
    use RefreshDatabase;

    private SignatureAndPdfAccessPolicyService $service;
    private EntitlementService $entitlements;

    protected function setUp(): void
    {
        parent::setUp();
        $this->entitlements = app(EntitlementService::class);
        $this->service = new SignatureAndPdfAccessPolicyService($this->entitlements);
    }

    public function test_e_signature_is_disabled_by_default(): void
    {
        $firm = Firm::factory()->create();

        $this->assertFalse($this->service->canUseSignatures($firm->id));
    }

    public function test_enabling_the_existing_e_signature_entitlement_flips_can_use_signatures(): void
    {
        $firm = Firm::factory()->create();
        $this->entitlements->setForSource($firm, 'e_signature', EntitlementSource::AdminOverride, true);

        $this->assertTrue($this->service->canUseSignatures($firm->id));
    }

    public function test_can_manage_requests_allows_generation_roles_only(): void
    {
        $firm = Firm::factory()->create();

        foreach ([FirmUserRole::FirmOwner, FirmUserRole::Attorney, FirmUserRole::Paralegal, FirmUserRole::LegalAssistant] as $role) {
            $user = FirmUser::factory()->role($role)->create(['firm_id' => $firm->id]);
            $this->assertTrue($this->service->canManageRequests($user), "{$role->value} should be able to manage requests.");
        }

        $receptionist = FirmUser::factory()->role(FirmUserRole::Receptionist)->create(['firm_id' => $firm->id]);
        $this->assertFalse($this->service->canManageRequests($receptionist));
    }

    public function test_can_review_as_attorney_allows_only_firm_owner_and_attorney(): void
    {
        $firm = Firm::factory()->create();

        foreach ([FirmUserRole::FirmOwner, FirmUserRole::Attorney] as $role) {
            $user = FirmUser::factory()->role($role)->create(['firm_id' => $firm->id]);
            $this->assertTrue($this->service->canReviewAsAttorney($user));
        }

        $paralegal = FirmUser::factory()->role(FirmUserRole::Paralegal)->create(['firm_id' => $firm->id]);
        $this->assertFalse($this->service->canReviewAsAttorney($paralegal));
    }

    public function test_annotations_disabled_unless_settings_json_explicitly_enables_it(): void
    {
        $firm = Firm::factory()->create();
        $this->entitlements->setForSource($firm, 'e_signature', EntitlementSource::AdminOverride, true, []);

        $this->assertFalse($this->service->annotationsEnabledForFirm($firm->id));

        $this->entitlements->setForSource($firm, 'e_signature', EntitlementSource::AdminOverride, true, ['annotations_enabled' => true]);

        $this->assertTrue($this->service->annotationsEnabledForFirm($firm->id));
    }

    public function test_annotations_are_not_enabled_by_entitlement_alone_without_the_setting(): void
    {
        $firm = Firm::factory()->create();
        $this->entitlements->setForSource($firm, 'e_signature', EntitlementSource::AdminOverride, true, ['some_other_setting' => true]);

        $this->assertFalse($this->service->annotationsEnabledForFirm($firm->id));
    }
}
