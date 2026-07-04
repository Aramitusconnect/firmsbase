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
        $this->assertSame($v2->id, $second->fresh()->template_pack_version_id);
        $this->assertSame(
            1,
            InstalledTemplatePack::where('firm_id', $firm->id)->where('template_pack_id', $pack->id)->count()
        );
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

        $this->assertSame($v1->id, $matter->fresh()->pinned_template_pack_version_id);
    }

    public function test_mark_upgrade_available_does_not_change_installed_version(): void
    {
        $firm = Firm::factory()->create();
        $version = TemplatePackVersion::factory()->create();
        $installed = $this->service->install($firm, $version);

        $flagged = $this->service->markUpgradeAvailable($installed);

        $this->assertSame(InstalledTemplatePackStatus::UpgradeAvailable, $flagged->status);
        $this->assertSame($version->id, $flagged->template_pack_version_id);
    }

    public function test_disable_sets_status_and_disabled_at(): void
    {
        $firm = Firm::factory()->create();
        $version = TemplatePackVersion::factory()->create();
        $installed = $this->service->install($firm, $version);

        $disabled = $this->service->disable($installed);

        $this->assertSame(InstalledTemplatePackStatus::Disabled, $disabled->status);
        $this->assertNotNull($disabled->disabled_at);
    }
}
