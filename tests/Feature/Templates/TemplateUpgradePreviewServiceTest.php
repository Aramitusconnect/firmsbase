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

        // installed_template_packs is FORCE RLS as of Checkpoint 6 —
        // preview()'s own runWithFirmContext() wrap clears the context in
        // its finally block before returning, so this ->fresh() read must
        // be explicitly (re-)scoped to the firm rather than relying on any
        // ambient/leaked context.
        $this->assertSame(
            $originalVersionId,
            $this->runWithFirmContext($firm, fn () => $installed->fresh())->template_pack_version_id,
            'A preview must never mutate the installed version.'
        );
        $this->assertNotEmpty($preview->diff_summary_json);
    }

    public function test_mark_reviewed_and_discard(): void
    {
        $firm = Firm::factory()->create();
        $installed = InstalledTemplatePack::factory()->forFirm($firm)->create();
        $toVersion = TemplatePackVersion::factory()->forPack($installed->templatePack)->version('2.0.0')->create();
        $preview = $this->service->preview($installed, $toVersion);

        $this->assertSame(TemplateUpgradePreviewStatus::Reviewed, $this->service->markReviewed($preview)->status);

        // template_upgrade_previews is FORCE RLS as of this checkpoint —
        // markReviewed()'s own runWithFirmContext() wrap clears the
        // context in its finally block before returning, so this
        // ->fresh() read must be explicitly (re-)scoped to the firm
        // rather than relying on any ambient/leaked context.
        $reReadPreview = $this->runWithFirmContext($firm, fn () => $preview->fresh());
        $this->assertSame(TemplateUpgradePreviewStatus::Discarded, $this->service->discard($reReadPreview)->status);
    }
}
