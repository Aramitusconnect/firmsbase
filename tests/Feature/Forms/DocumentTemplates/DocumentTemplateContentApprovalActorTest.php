<?php

namespace Tests\Feature\Forms\DocumentTemplates;

use App\Enums\DocumentTemplateCategory;
use App\Enums\DocumentTemplateContentStatus;
use App\Enums\FirmUserRole;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\PlatformAdmin;
use App\Services\DocumentTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Required test: the dual-actor invariant on document_templates content
 * approval. Global template content can ONLY be approved by a
 * PlatformAdmin; firm-specific template content can ONLY be approved by
 * a FirmOwner/Attorney of the SAME firm that owns the template. Exactly
 * one actor type is ever valid per template — never both, never neither.
 */
class DocumentTemplateContentApprovalActorTest extends TestCase
{
    use RefreshDatabase;

    private DocumentTemplateService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DocumentTemplateService();
    }

    public function test_global_template_content_approved_by_platform_admin_succeeds(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $template = $this->service->createGlobal('n400_cover', 'N-400 Cover Letter', DocumentTemplateCategory::CoverLetter, $admin);
        $version = $this->service->createVersion($template, 'v1', [], 'Body');

        $approved = $this->service->approveContent($version, $admin);

        $this->assertSame(DocumentTemplateContentStatus::ReviewedApproved, $approved->content_status);
    }

    public function test_global_template_content_cannot_be_approved_by_a_firm_user(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $template = $this->service->createGlobal('i485_cover', 'I-485 Cover Letter', DocumentTemplateCategory::CoverLetter, $admin);
        $version = $this->service->createVersion($template, 'v1', [], 'Body');

        $firm = Firm::factory()->create();
        $firmOwner = FirmUser::factory()->role(FirmUserRole::FirmOwner)->create(['firm_id' => $firm->id]);

        $this->expectException(\RuntimeException::class);
        $this->service->approveContent($version, $firmOwner);
    }

    public function test_firm_specific_template_content_approved_by_same_firm_owner_succeeds(): void
    {
        $firm = Firm::factory()->create();
        $owner = FirmUser::factory()->role(FirmUserRole::FirmOwner)->create(['firm_id' => $firm->id]);
        $template = $this->service->createFirmSpecific($firm, 'custom_engagement', 'Custom Engagement Letter', DocumentTemplateCategory::EngagementLetter, $owner);
        $version = $this->service->createVersion($template, 'v1', [], 'Body');

        $approved = $this->service->approveContent($version, $owner);

        $this->assertSame(DocumentTemplateContentStatus::ReviewedApproved, $approved->content_status);
    }

    public function test_firm_specific_template_content_cannot_be_approved_by_a_different_firms_user(): void
    {
        $firm = Firm::factory()->create();
        $owner = FirmUser::factory()->role(FirmUserRole::FirmOwner)->create(['firm_id' => $firm->id]);
        $template = $this->service->createFirmSpecific($firm, 'custom_status_update', 'Custom Status Update', DocumentTemplateCategory::StatusUpdateLetter, $owner);
        $version = $this->service->createVersion($template, 'v1', [], 'Body');

        $otherFirm = Firm::factory()->create();
        $otherFirmAttorney = FirmUser::factory()->role(FirmUserRole::Attorney)->create(['firm_id' => $otherFirm->id]);

        $this->expectException(\RuntimeException::class);
        $this->service->approveContent($version, $otherFirmAttorney);
    }

    public function test_firm_specific_template_content_cannot_be_approved_by_a_platform_admin(): void
    {
        $firm = Firm::factory()->create();
        $owner = FirmUser::factory()->role(FirmUserRole::FirmOwner)->create(['firm_id' => $firm->id]);
        $template = $this->service->createFirmSpecific($firm, 'custom_misc', 'Custom Misc', DocumentTemplateCategory::Miscellaneous, $owner);
        $version = $this->service->createVersion($template, 'v1', [], 'Body');

        $admin = PlatformAdmin::factory()->create();

        $this->expectException(\RuntimeException::class);
        $this->service->approveContent($version, $admin);
    }

    public function test_firm_specific_template_content_cannot_be_approved_by_a_non_owner_non_attorney_role(): void
    {
        $firm = Firm::factory()->create();
        $owner = FirmUser::factory()->role(FirmUserRole::FirmOwner)->create(['firm_id' => $firm->id]);
        $template = $this->service->createFirmSpecific($firm, 'custom_misc_2', 'Custom Misc 2', DocumentTemplateCategory::Miscellaneous, $owner);
        $version = $this->service->createVersion($template, 'v1', [], 'Body');

        $paralegal = FirmUser::factory()->role(FirmUserRole::Paralegal)->create(['firm_id' => $firm->id]);

        $this->expectException(\RuntimeException::class);
        $this->service->approveContent($version, $paralegal);
    }
}
