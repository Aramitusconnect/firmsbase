<?php

namespace Tests\Feature\Entitlements;

use App\Enums\EntitlementSource;
use App\Models\Firm;
use App\Models\FirmEntitlement;
use App\Models\ModuleCatalog;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FirmEntitlementTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_can_be_created_via_factory(): void
    {
        $entitlement = FirmEntitlement::factory()->create();

        $this->assertDatabaseHas('firm_entitlements', ['id' => $entitlement->id]);
    }

    public function test_source_and_enabled_cast_correctly(): void
    {
        $entitlement = FirmEntitlement::factory()
            ->source(EntitlementSource::FirmOverride)
            ->disabled()
            ->create();

        $fresh = $entitlement->fresh();

        $this->assertSame(EntitlementSource::FirmOverride, $fresh->source);
        $this->assertFalse($fresh->enabled);
    }

    public function test_only_one_active_record_per_firm_module_source(): void
    {
        $firm = Firm::factory()->create();
        $module = ModuleCatalog::factory()->create();

        FirmEntitlement::factory()
            ->forFirm($firm)->forModule($module)
            ->source(EntitlementSource::AdminOverride)
            ->create();

        $this->expectException(QueryException::class);

        FirmEntitlement::factory()
            ->forFirm($firm)->forModule($module)
            ->source(EntitlementSource::AdminOverride)
            ->create();
    }

    public function test_different_sources_for_same_firm_and_module_are_allowed(): void
    {
        $firm = Firm::factory()->create();
        $module = ModuleCatalog::factory()->create();

        FirmEntitlement::factory()->forFirm($firm)->forModule($module)->source(EntitlementSource::AdminOverride)->create();
        FirmEntitlement::factory()->forFirm($firm)->forModule($module)->source(EntitlementSource::FirmOverride)->create();

        $this->assertSame(
            2,
            FirmEntitlement::where('firm_id', $firm->id)->where('module_code', $module->module_code)->count()
        );
    }

    public function test_active_window_boundaries(): void
    {
        $future = FirmEntitlement::factory()->create(['starts_at' => now()->addDay()]);
        $expired = FirmEntitlement::factory()->create(['ends_at' => now()->subDay()]);
        $current = FirmEntitlement::factory()->create(['starts_at' => now()->subDay(), 'ends_at' => now()->addDay()]);
        $noWindow = FirmEntitlement::factory()->create(['starts_at' => null, 'ends_at' => null]);

        $this->assertFalse($future->isWithinActiveWindow());
        $this->assertFalse($expired->isWithinActiveWindow());
        $this->assertTrue($current->isWithinActiveWindow());
        $this->assertTrue($noWindow->isWithinActiveWindow());
    }
}
