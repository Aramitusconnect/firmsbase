<?php

namespace Tests\Feature\Templates;

use App\Enums\TemplateUpgradePreviewStatus;
use App\Models\Firm;
use App\Models\InstalledTemplatePack;
use App\Models\TemplatePackVersion;
use App\Services\TemplateUpgradePreviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TemplateUpgradePreviewServiceTest extends TestCase
{
    use RefreshDatabase;

    private TemplateUpgradePreviewService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TemplateUpgradePreviewService();
    }

    public function test_preview_generates_a_diff_summary_without_changing_the_installed_version(): void
    {
        $firm = Firm::factory()->create();
        $installed = InstalledTemplatePack::factory()->forFirm($firm)->create();
        $originalVersionId = $installed->template_pack_version_id;
        $toVersion = TemplatePackVersion::factory()
            ->forPack($installed->templatePack)
            ->version('2.0.0')
            ->create();

        $preview = $this->service->preview($installed, $toVersion);

        $this->assertSame(TemplateUpgradePreviewStatus::Generated, $preview->status);
        $this->assertSame($toVersion->id, $preview->to_version_id);
        $this->assertSame($originalVersionId, $preview->from_version_id);
        $this->assertSame($originalVersionId, $installed->fresh()->template_pack_version_id, 'A preview must never mutate the installed version.');
        $this->assertNotEmpty($preview->diff_summary_json);
    }

    public function test_mark_reviewed_and_discard(): void
    {
        $firm = Firm::factory()->create();
        $installed = InstalledTemplatePack::factory()->forFirm($firm)->create();
        $toVersion = TemplatePackVersion::factory()->forPack($installed->templatePack)->version('2.0.0')->create();
        $preview = $this->service->preview($installed, $toVersion);

        $this->assertSame(TemplateUpgradePreviewStatus::Reviewed, $this->service->markReviewed($preview)->status);
        $this->assertSame(TemplateUpgradePreviewStatus::Discarded, $this->service->discard($preview->fresh())->status);
    }
}
