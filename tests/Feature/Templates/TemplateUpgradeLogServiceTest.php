<?php

namespace Tests\Feature\Templates;

use App\Enums\TemplateUpgradeLogStatus;
use App\Models\Firm;
use App\Models\InstalledTemplatePack;
use App\Models\TemplatePackVersion;
use App\Services\TemplatePackInstallationService;
use App\Services\TemplateUpgradeLogService;
use App\Services\TemplateUpgradePreviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TemplateUpgradeLogServiceTest extends TestCase
{
    use RefreshDatabase;

    private TemplateUpgradeLogService $service;
    private TemplateUpgradePreviewService $previewService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TemplateUpgradeLogService(new TemplatePackInstallationService());
        $this->previewService = new TemplateUpgradePreviewService();
    }

    public function test_apply_installs_the_new_version_and_marks_the_preview_applied(): void
    {
        $firm = Firm::factory()->create();
        $installed = InstalledTemplatePack::factory()->forFirm($firm)->create();
        $fromVersionId = $installed->template_pack_version_id;
        $toVersion = TemplatePackVersion::factory()->forPack($installed->templatePack)->version('2.0.0')->create();
        $preview = $this->previewService->preview($installed, $toVersion);

        $log = $this->service->apply($preview);

        $this->assertSame(TemplateUpgradeLogStatus::Applied, $log->status);
        $this->assertSame($fromVersionId, $log->from_version_id);
        $this->assertSame($toVersion->id, $log->to_version_id);

        // installed_template_packs is FORCE RLS as of this checkpoint —
        // apply()'s transaction ultimately calls install(), whose own
        // runWithFirmContext() wrap clears the context in its finally
        // block before apply() returns, so this ->fresh() read must be
        // explicitly (re-)scoped to the firm rather than relying on any
        // ambient/leaked context.
        $persistedInstalled = $this->runWithFirmContext($firm, fn () => $installed->fresh());
        $this->assertSame($toVersion->id, $persistedInstalled->template_pack_version_id);

        // template_upgrade_previews is not yet FORCE RLS, so this read
        // needs no explicit context.
        $this->assertSame(\App\Enums\TemplateUpgradePreviewStatus::Applied, $preview->fresh()->status);
    }

    public function test_rollback_reverts_the_installed_version_and_inserts_a_new_row_never_mutating_the_original(): void
    {
        $firm = Firm::factory()->create();
        $installed = InstalledTemplatePack::factory()->forFirm($firm)->create();
        $fromVersionId = $installed->template_pack_version_id;
        $toVersion = TemplatePackVersion::factory()->forPack($installed->templatePack)->version('2.0.0')->create();
        $preview = $this->previewService->preview($installed, $toVersion);
        $appliedLog = $this->service->apply($preview);

        $rollbackLog = $this->service->rollback($appliedLog);

        $this->assertSame(TemplateUpgradeLogStatus::RolledBack, $rollbackLog->status);
        $this->assertSame($appliedLog->id, $rollbackLog->rollback_of_id);

        // installed_template_packs is FORCE RLS as of this checkpoint —
        // rollback()'s transaction ultimately calls install(), whose own
        // runWithFirmContext() wrap clears context on exit, so this
        // ->fresh() read must be explicitly (re-)scoped to the firm.
        $persistedInstalled = $this->runWithFirmContext($firm, fn () => $installed->fresh());
        $this->assertSame($fromVersionId, $persistedInstalled->template_pack_version_id);

        // template_upgrade_logs is FORCE RLS as of this checkpoint —
        // rollback()'s own second, separate runWithFirmContext() wrap
        // (covering only its direct TemplateUpgradeLog::create() write)
        // clears the context in its finally block before rollback()
        // returns, so this ->fresh() read of the original Applied row
        // must be explicitly (re-)scoped to the firm rather than relying
        // on any ambient/leaked context.
        $persistedAppliedLog = $this->runWithFirmContext($firm, fn () => $appliedLog->fresh());

        // The original Applied row is never mutated or deleted.
        $this->assertSame(TemplateUpgradeLogStatus::Applied, $persistedAppliedLog->status);
        $this->assertNull($persistedAppliedLog->rollback_of_id);
    }

    public function test_rollback_throws_when_there_is_no_from_version(): void
    {
        $firm = Firm::factory()->create();
        $log = \App\Models\TemplateUpgradeLog::factory()->forFirm($firm)->create(['from_version_id' => null]);

        $this->expectException(\RuntimeException::class);

        $this->service->rollback($log);
    }
}
