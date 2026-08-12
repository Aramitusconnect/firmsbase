<?php

declare(strict_types=1);

namespace Tests\Feature\Security\RlsForceRollout;

use App\Enums\MarketplaceIntakeStatus;
use App\Marketplace\Models\MarketplaceIntake;
use App\Models\Firm;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * MarketplaceIntakesForceRlsActivationTest — Mission 3 (MyAttorney
 * Conversion + AI Intake), checkpoint 1. Proves marketplace_intakes'
 * permanent FORCE ROW LEVEL SECURITY (2026_11_12_100003) behaves
 * correctly, mirroring PaymentRequestsForceRlsActivationTest exactly.
 *
 * The self-lookup carve-out (marketplace_intakes_self_lookup,
 * 2026_11_12_100005) is proven separately by
 * tests/Feature/Marketplace/Intake/MarketplaceIntakeRlsTest.php — this
 * file only proves the ordinary tenant_isolation policy. The sibling
 * marketplace_intake_events table has its own dedicated file,
 * MarketplaceIntakeEventsForceRlsActivationTest.php — the schema
 * firewall (SchemaTenantFirewallTest check 5) requires one activation
 * test file per forced table, matching the table's own studly name.
 */
class MarketplaceIntakesForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    public function test_marketplace_intakes_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'marketplace_intakes'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
        $this->assertTrue((bool) $row->relforcerowsecurity);
    }

    public function test_missing_tenant_context_cannot_read_marketplace_intakes(): void
    {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext($firm, fn () => MarketplaceIntake::factory()->forFirm($firm)->create());

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->assertSame(0, MarketplaceIntake::query()->count());
    }

    public function test_missing_tenant_context_cannot_insert_marketplace_intakes(): void
    {
        $firm = Firm::factory()->create();

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('marketplace_intakes')->insert([
            'uuid' => (string) Str::uuid7(),
            'firm_id' => $firm->id,
            'status' => 'started',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_firm_a_context_cannot_read_firm_b_marketplace_intakes(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->runWithFirmContext($firmA, fn () => MarketplaceIntake::factory()->forFirm($firmA)->create());
        $intakeB = $this->runWithFirmContext($firmB, fn () => MarketplaceIntake::factory()->forFirm($firmB)->create());

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => MarketplaceIntake::query()->pluck('id')->all(),
        );

        $this->assertNotContains($intakeB->id, $visibleIds);
    }

    public function test_legitimate_firm_context_writes_to_marketplace_intakes_keep_working(): void
    {
        $firm = Firm::factory()->create();
        $intake = $this->runWithFirmContext($firm, fn () => MarketplaceIntake::factory()->forFirm($firm)->create());

        $this->runWithFirmContext($firm, fn () => $intake->update(['status' => MarketplaceIntakeStatus::Submitted, 'submitted_at' => now()]));

        $reRead = $this->runWithFirmContext($firm, fn () => $intake->fresh()->status);
        $this->assertSame(MarketplaceIntakeStatus::Submitted, $reRead);
    }

    public function test_migration_down_fully_disables_row_level_security_on_marketplace_intakes(): void
    {
        $migration = require base_path('database/migrations/2026_11_12_100003_prepare_row_level_security_and_force_rls_on_marketplace_intakes_table.php');

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'marketplace_intakes'");

            $this->assertFalse((bool) $row->relrowsecurity);
            $this->assertFalse((bool) $row->relforcerowsecurity);
        } finally {
            $migration->up();
        }
    }
}
