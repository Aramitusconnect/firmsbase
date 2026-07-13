<?php

namespace Tests\Feature\PracticeTemplates;

use App\Enums\InstalledTemplatePackStatus;
use App\Models\Firm;
use App\Models\InstalledTemplatePack;
use App\Models\TemplatePack;
use App\Models\TemplatePackVersion;
use App\Services\TemplatePackInstallationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TemplatePackInstallationServiceTest extends TestCase
{
    use RefreshDatabase;

    private TemplatePackInstallationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TemplatePackInstallationService();
    }

    public function test_install_creates_a_new_record(): void
    {
        $firm = Firm::factory()->create();
        $version = TemplatePackVersion::factory()->version('1.0.0')->create();

        $installed = $this->service->install($firm, $version);

        $this->assertSame($firm->id, $installed->firm_id);
        $this->assertSame($version->id, $installed->template_pack_version_id);
        $this->assertSame(InstalledTemplatePackStatus::Active, $installed->status);
    }

    public function test_install_upgrades_in_place_rather_than_duplicating(): void
    {
        $firm = Firm::factory()->create();
        $pack = TemplatePack::factory()->create();
        $v1 = TemplatePackVersion::factory()->forPack($pack)->version('1.0.0')->create();
        $v2 = TemplatePackVersion::factory()->forPack($pack)->version('2.0.0')->create();

        $first = $this->service->install($firm, $v1);
        $second = $this->service->install($firm, $v2);

        $this->assertSame($first->id, $second->id);

        // installed_template_packs is FORCE RLS as of this checkpoint —
        // install()'s own runWithFirmContext() wrap clears the context
        // in its finally block before returning, so this is a genuinely
        // fresh read and must be explicitly (re-)scoped to the firm.
        $persisted = $this->runWithFirmContext($firm, function () use ($second, $firm, $pack) {
            return [
                'second' => $second->fresh(),
                'count' => InstalledTemplatePack::withoutGlobalScopes()
                    ->where('firm_id', $firm->id)
                    ->where('template_pack_id', $pack->id)
                    ->count(),
            ];
        });

        $this->assertSame($v2->id, $persisted['second']->template_pack_version_id);
        $this->assertSame(1, $persisted['count']);
    }

    public function test_installing_a_new_version_does_not_change_matters_already_pinned_to_the_old_version(): void
    {
        $firm = Firm::factory()->create();
        $pack = TemplatePack::factory()->create();
        $v1 = TemplatePackVersion::factory()->forPack($pack)->version('1.0.0')->create();
        $v2 = TemplatePackVersion::factory()->forPack($pack)->version('2.0.0')->create();

        $this->service->install($firm, $v1);
        $matter = \App\Models\Matter::factory()->forFirm($firm)->create(['pinned_template_pack_version_id' => $v1->id]);

        $this->service->install($firm, $v2);

        // matters has been FORCE RLS since an earlier checkpoint in this
        // arc; install()'s runWithFirmContext() wrap clears context on
        // exit, so this ->fresh() read must be explicitly scoped to the
        // firm rather than relying on any ambient/leaked context.
        $persistedMatter = $this->runWithFirmContext($firm, fn () => $matter->fresh());

        $this->assertSame($v1->id, $persistedMatter->pinned_template_pack_version_id);
    }

    /**
     * Regression test for the silent-no-op bug this checkpoint's
     * implementer found and fixed: before markUpgradeAvailable() wrapped
     * its whole body in runWithFirmContext(), tap($installed)->update()
     * would silently affect zero rows under FORCE RLS with no context —
     * Eloquent's update() always returns true regardless of actual
     * affected-row count, so the in-memory $flagged object looked
     * correct while the real row was untouched. Asserting only against
     * the in-memory object (as this test previously did) is a false
     * green: it cannot distinguish "persisted" from "silently no-op'd".
     * The re-read below, under an explicit tenant context (since
     * markUpgradeAvailable()'s own wrap has already cleared context by
     * the time it returns), is what actually proves persistence.
     */
    public function test_mark_upgrade_available_does_not_change_installed_version(): void
    {
        $firm = Firm::factory()->create();
        $version = TemplatePackVersion::factory()->create();
        $installed = $this->service->install($firm, $version);

        $flagged = $this->service->markUpgradeAvailable($installed);

        $this->assertSame(InstalledTemplatePackStatus::UpgradeAvailable, $flagged->status);
        $this->assertSame($version->id, $flagged->template_pack_version_id);

        $persisted = $this->runWithFirmContext(
            $firm,
            fn () => InstalledTemplatePack::withoutGlobalScopes()->find($installed->id),
        );

        $this->assertNotNull($persisted, 'The row must genuinely exist and be readable under the firm\'s own context.');
        $this->assertSame(
            InstalledTemplatePackStatus::UpgradeAvailable,
            $persisted->status,
            'markUpgradeAvailable() must actually persist the status change to the database, not merely mutate the in-memory model.'
        );
        $this->assertSame($version->id, $persisted->template_pack_version_id);
    }

    /**
     * Regression test for the same silent-no-op bug as above, applied to
     * disable(): asserting only against the in-memory $disabled object
     * cannot distinguish a genuinely persisted UPDATE from one that RLS
     * silently rejected. Re-reads the row fresh from the database under
     * an explicit tenant context.
     */
    public function test_disable_sets_status_and_disabled_at(): void
    {
        $firm = Firm::factory()->create();
        $version = TemplatePackVersion::factory()->create();
        $installed = $this->service->install($firm, $version);

        $disabled = $this->service->disable($installed);

        $this->assertSame(InstalledTemplatePackStatus::Disabled, $disabled->status);
        $this->assertNotNull($disabled->disabled_at);

        $persisted = $this->runWithFirmContext(
            $firm,
            fn () => InstalledTemplatePack::withoutGlobalScopes()->find($installed->id),
        );

        $this->assertNotNull($persisted, 'The row must genuinely exist and be readable under the firm\'s own context.');
        $this->assertSame(
            InstalledTemplatePackStatus::Disabled,
            $persisted->status,
            'disable() must actually persist the status change to the database, not merely mutate the in-memory model.'
        );
        $this->assertNotNull($persisted->disabled_at, 'disable() must actually persist disabled_at to the database.');
    }
}
