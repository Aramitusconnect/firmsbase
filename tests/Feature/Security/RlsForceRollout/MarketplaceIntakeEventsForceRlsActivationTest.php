<?php

declare(strict_types=1);

namespace Tests\Feature\Security\RlsForceRollout;

use App\Enums\MarketplaceIntakeEventType;
use App\Marketplace\Models\MarketplaceIntake;
use App\Marketplace\Models\MarketplaceIntakeEvent;
use App\Models\Firm;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * MarketplaceIntakeEventsForceRlsActivationTest — Mission 3
 * (MyAttorney Conversion + AI Intake), checkpoint 1. Proves
 * marketplace_intake_events' permanent FORCE ROW LEVEL SECURITY
 * (2026_11_12_100004) behaves correctly, mirroring
 * PaymentRequestEventsForceRlsActivationTest exactly. Sibling to
 * MarketplaceIntakesForceRlsActivationTest — each forced table gets
 * its own dedicated activation test file (SchemaTenantFirewallTest
 * check 5).
 */
class MarketplaceIntakeEventsForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    public function test_marketplace_intake_events_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'marketplace_intake_events'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
        $this->assertTrue((bool) $row->relforcerowsecurity);
    }

    public function test_missing_tenant_context_cannot_read_marketplace_intake_events(): void
    {
        $firm = Firm::factory()->create();
        $intake = $this->runWithFirmContext($firm, fn () => MarketplaceIntake::factory()->forFirm($firm)->create());
        $this->runWithFirmContext($firm, fn () => MarketplaceIntakeEvent::factory()->create([
            'firm_id' => $firm->id,
            'marketplace_intake_id' => $intake->id,
            'event_type' => MarketplaceIntakeEventType::Started,
        ]));

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->assertSame(0, MarketplaceIntakeEvent::query()->count());
    }

    public function test_missing_tenant_context_cannot_insert_marketplace_intake_events(): void
    {
        $firm = Firm::factory()->create();
        $intake = $this->runWithFirmContext($firm, fn () => MarketplaceIntake::factory()->forFirm($firm)->create());

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('marketplace_intake_events')->insert([
            'firm_id' => $firm->id,
            'marketplace_intake_id' => $intake->id,
            'event_type' => 'started',
            'created_at' => now(),
        ]);
    }

    public function test_firm_a_context_cannot_read_firm_b_marketplace_intake_events(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $intakeB = $this->runWithFirmContext($firmB, fn () => MarketplaceIntake::factory()->forFirm($firmB)->create());
        $eventB = $this->runWithFirmContext($firmB, fn () => MarketplaceIntakeEvent::factory()->create([
            'firm_id' => $firmB->id,
            'marketplace_intake_id' => $intakeB->id,
            'event_type' => MarketplaceIntakeEventType::Started,
        ]));

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => MarketplaceIntakeEvent::query()->pluck('id')->all(),
        );

        $this->assertNotContains($eventB->id, $visibleIds);
    }

    public function test_migration_down_fully_disables_row_level_security_on_marketplace_intake_events(): void
    {
        $migration = require base_path('database/migrations/2026_11_12_100004_prepare_row_level_security_and_force_rls_on_marketplace_intake_events_table.php');

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'marketplace_intake_events'");

            $this->assertFalse((bool) $row->relrowsecurity);
            $this->assertFalse((bool) $row->relforcerowsecurity);
        } finally {
            $migration->up();
        }
    }
}
