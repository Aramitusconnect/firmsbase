<?php

namespace Tests\Feature\Forms\DocumentTemplates;

use App\Enums\DocumentTemplateCategory;
use App\Enums\DocumentTemplateVersionStatus;
use App\Enums\FirmUserRole;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\PlatformAdmin;
use App\Services\DocumentTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentTemplateServiceTest extends TestCase
{
    use RefreshDatabase;

    private DocumentTemplateService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DocumentTemplateService();
    }

    public function test_create_global_has_no_firm_id_and_platform_admin_actor(): void
    {
        $admin = PlatformAdmin::factory()->create();

        $template = $this->service->createGlobal('engagement_letter_default', 'Default Engagement Letter', DocumentTemplateCategory::EngagementLetter, $admin);

        $this->assertNull($template->firm_id);
        $this->assertSame($admin->id, $template->created_by_platform_admin_id);
        $this->assertTrue($template->isGlobalDefault());
    }

    public function test_create_firm_specific_rejects_an_actor_from_a_different_firm(): void
    {
        $firm = Firm::factory()->create();
        $otherFirm = Firm::factory()->create();
        $actor = FirmUser::factory()->role(FirmUserRole::FirmOwner)->create(['firm_id' => $otherFirm->id]);

        $this->expectException(\RuntimeException::class);
        $this->service->createFirmSpecific($firm, 'cover_letter_custom', 'Custom Cover Letter', DocumentTemplateCategory::CoverLetter, $actor);
    }

    public function test_create_version_defaults_to_draft_and_sample_only(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $template = $this->service->createGlobal('status_update_default', 'Default Status Update', DocumentTemplateCategory::StatusUpdateLetter, $admin);

        $version = $this->service->createVersion($template, 'v1', [], 'Dear client, ...');

        $this->assertSame(DocumentTemplateVersionStatus::Draft, $version->status);
        $this->assertSame(\App\Enums\DocumentTemplateContentStatus::SampleOnly, $version->content_status);
    }

    public function test_retire_only_updates_the_version(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $template = $this->service->createGlobal('misc_default', 'Default Misc', DocumentTemplateCategory::Miscellaneous, $admin);
        $version = $this->service->activate($this->service->createVersion($template, 'v1', [], 'Body'));

        $retired = $this->service->retire($version);

        $this->assertSame(DocumentTemplateVersionStatus::Retired, $retired->status);
    }
}
