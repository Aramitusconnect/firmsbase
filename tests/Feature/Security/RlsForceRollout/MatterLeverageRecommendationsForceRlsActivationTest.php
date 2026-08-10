<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Enums\MatterLeverageRecommendationStatus;
use App\Models\Firm;
use App\Models\Matter;
use App\Models\MatterLeverageRecommendation;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * MatterLeverageRecommendationsForceRlsActivationTest — Leverage
 * Ratio Optimizer pass. Proves matter_leverage_recommendations'
 * permanent FORCE ROW LEVEL SECURITY (2026_11_06_100005) behaves
 * correctly.
 */
class MatterLeverageRecommendationsForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    private function makeRecommendation(Firm $firm): MatterLeverageRecommendation
    {
        return $this->runWithFirmContext($firm, function () use ($firm) {
            $matter = Matter::factory()->forFirm($firm)->create();

            return MatterLeverageRecommendation::factory()->forMatter($matter)->create();
        });
    }

    public function test_matter_leverage_recommendations_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'matter_leverage_recommendations'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
        $this->assertTrue((bool) $row->relforcerowsecurity);
    }

    public function test_missing_tenant_context_cannot_read_matter_leverage_recommendations(): void
    {
        $firm = Firm::factory()->create();
        $this->makeRecommendation($firm);

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->assertSame(0, MatterLeverageRecommendation::query()->count());
    }

    public function test_firm_a_context_cannot_read_firm_b_matter_leverage_recommendations(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->makeRecommendation($firmA);
        $recommendationB = $this->makeRecommendation($firmB);

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => MatterLeverageRecommendation::query()->pluck('id')->all(),
        );

        $this->assertNotContains($recommendationB->id, $visibleIds);
    }

    public function test_legitimate_firm_context_writes_keep_working(): void
    {
        $firm = Firm::factory()->create();
        $recommendation = $this->makeRecommendation($firm);

        $this->runWithFirmContext($firm, fn () => $recommendation->update(['status' => MatterLeverageRecommendationStatus::Resolved]));

        $reRead = $this->runWithFirmContext($firm, fn () => $recommendation->fresh()->status);
        $this->assertSame(MatterLeverageRecommendationStatus::Resolved, $reRead);
    }

    public function test_migration_down_fully_disables_row_level_security(): void
    {
        $migration = require base_path('database/migrations/2026_11_06_100005_prepare_row_level_security_and_force_rls_on_matter_leverage_recommendations_table.php');

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'matter_leverage_recommendations'");

            $this->assertFalse((bool) $row->relrowsecurity);
            $this->assertFalse((bool) $row->relforcerowsecurity);
        } finally {
            $migration->up();
        }
    }
}
