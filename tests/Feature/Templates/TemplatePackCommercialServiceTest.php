<?php

namespace Tests\Feature\Templates;

use App\Enums\EntitlementSource;
use App\Models\Firm;
use App\Models\ModuleCatalog;
use App\Models\TemplatePackVersion;
use App\Services\EntitlementService;
use App\Services\TemplatePackCommercialService;
use App\Services\TemplatePackInstallationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TemplatePackCommercialServiceTest extends TestCase
{
    use RefreshDatabase;

    private TemplatePackCommercialService $service;
    private EntitlementService $entitlementService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->entitlementService = new EntitlementService();
        $this->service = new TemplatePackCommercialService($this->entitlementService, new TemplatePackInstallationService());
    }

    public function test_install_succeeds_when_the_firm_is_entitled(): void
    {
        $firm = Firm::factory()->create();
        $this->module('practice_area_templates');
        $this->entitlementService->setForSource($firm, 'practice_area_templates', EntitlementSource::AdminOverride, true);
        $version = TemplatePackVersion::factory()->create();

        $installed = $this->service->installIfEntitled($firm, $version);

        $this->assertSame($version->id, $installed->template_pack_version_id);
    }

    public function test_install_is_blocked_when_the_firm_is_not_entitled(): void
    {
        $firm = Firm::factory()->create();
        $version = TemplatePackVersion::factory()->create();

        $this->expectException(\RuntimeException::class);

        $this->service->installIfEntitled($firm, $version);
    }

    /**
     * hotfix 01: reuses a module_catalog row already seeded by the
     * Phase 6 data migration instead of creating a duplicate via
     * ModuleCatalog::factory()->create(['module_code' => ...]), which
     * now violates module_catalog's unique index.
     */
    private function module(string $code): ModuleCatalog
    {
        return ModuleCatalog::query()->where('module_code', $code)->firstOrFail();
    }
}
