<?php

declare(strict_types=1);

namespace Tests\Feature\Security\RlsForceRollout;

use App\Enums\AiProvider;
use App\Enums\AiUsageActionType;
use App\Marketplace\Models\MarketplaceAiUsageEvent;
use App\Models\Firm;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * MarketplaceAiUsageEventsForceRlsActivationTest — Mission 3
 * (MyAttorney Conversion + AI Intake), checkpoint 6
 * (database/migrations/2026_11_15_100002_prepare_row_level_security_and_force_rls_on_marketplace_ai_usage_events_table.php).
 * Proves marketplace_ai_usage_events' RLS policy shape, byte-for-byte
 * copied from security_events' own nullable-firm_id dual-policy design
 * (see that table's own ForceRlsActivationTest for the fuller
 * narrative this file deliberately does not repeat): a firm-scoped
 * session may read/write only its own firm's rows; the platform-wide
 * (firm_id IS NULL) rows are visible/writable ONLY when no tenant
 * context is active at all.
 */
class MarketplaceAiUsageEventsForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    private function tenantContext(): TenantContextService
    {
        return new TenantContextService;
    }

    private function insertRow(?int $firmId, string $sessionHash, array $overrides = []): int
    {
        return DB::table('marketplace_ai_usage_events')->insertGetId(array_merge([
            'firm_id' => $firmId,
            'marketplace_intake_id' => null,
            'session_hash' => $sessionHash,
            'ip_address' => '203.0.113.1',
            'provider' => 'openai',
            'model' => 'fake-model-1',
            'action_type' => 'intake_classification',
            'tokens_in' => 5,
            'tokens_out' => 5,
            'created_at' => now(),
        ], $overrides));
    }

    public function test_marketplace_ai_usage_events_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'marketplace_ai_usage_events'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_marketplace_ai_usage_events_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'marketplace_ai_usage_events'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relforcerowsecurity);
    }

    public function test_exactly_two_policies_exist(): void
    {
        $count = DB::selectOne("select count(*) as c from pg_policy where polrelid = 'marketplace_ai_usage_events'::regclass")->c;

        $this->assertSame(2, (int) $count);
    }

    public function test_the_read_and_write_policies_have_the_expected_shape(): void
    {
        $readPolicy = DB::selectOne(
            "select polcmd, pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy where polrelid = 'marketplace_ai_usage_events'::regclass and polname = 'marketplace_ai_usage_events_tenant_isolation'",
        );

        $this->assertNotNull($readPolicy);
        $this->assertSame('r', $readPolicy->polcmd);
        $this->assertStringContainsString('firm_id IS NULL', $readPolicy->using_expr);
        $this->assertNull($readPolicy->with_check_expr);

        $writePolicy = DB::selectOne(
            "select polcmd, pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy where polrelid = 'marketplace_ai_usage_events'::regclass and polname = 'marketplace_ai_usage_events_platform_write'",
        );

        $this->assertNotNull($writePolicy);
        $this->assertSame('a', $writePolicy->polcmd);
        $this->assertNotNull($writePolicy->with_check_expr);
        $this->assertStringContainsString('firm_id IS NULL', $writePolicy->with_check_expr);
    }

    public function test_missing_tenant_context_cannot_read_a_firm_specific_row(): void
    {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext($firm, fn () => $this->insertRow($firm->id, 'ctx-read'));

        $this->tenantContext()->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $this->assertSame(0, MarketplaceAiUsageEvent::query()->where('firm_id', $firm->id)->count());
    }

    public function test_missing_tenant_context_cannot_insert_a_firm_specific_row(): void
    {
        $firm = Firm::factory()->create();

        $this->tenantContext()->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->insertRow($firm->id, 'ctx-insert');
    }

    public function test_firm_a_context_can_read_its_own_row(): void
    {
        $firmA = Firm::factory()->create();
        $rowId = $this->runWithFirmContext($firmA, fn () => $this->insertRow($firmA->id, 'firm-a-own'));

        $visible = $this->runWithFirmContext($firmA, fn () => MarketplaceAiUsageEvent::query()->pluck('id')->all());

        $this->assertSame([$rowId], $visible);
    }

    public function test_firm_a_context_cannot_read_firm_bs_row(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $rowB = $this->runWithFirmContext($firmB, fn () => $this->insertRow($firmB->id, 'firm-b-only'));

        $visible = $this->runWithFirmContext($firmA, fn () => MarketplaceAiUsageEvent::query()->pluck('id')->all());

        $this->assertNotContains($rowB, $visible);
    }

    public function test_firm_a_context_cannot_insert_a_row_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, fn () => $this->insertRow($firmB->id, 'forged'));
    }

    public function test_a_firm_scoped_context_cannot_read_a_platform_wide_null_row(): void
    {
        $platformWideId = $this->tenantContext()->runWithoutFirmContext(fn () => $this->insertRow(null, 'platform-wide'));
        $firm = Firm::factory()->create();

        $visible = $this->runWithFirmContext($firm, fn () => MarketplaceAiUsageEvent::query()->find($platformWideId));

        $this->assertNull($visible);
    }

    public function test_a_context_free_session_can_read_a_platform_wide_row_but_not_a_firms_row(): void
    {
        $platformWideId = $this->tenantContext()->runWithoutFirmContext(fn () => $this->insertRow(null, 'platform-wide-visible'));
        $firm = Firm::factory()->create();
        $firmRowId = $this->runWithFirmContext($firm, fn () => $this->insertRow($firm->id, 'firm-row-invisible'));

        $this->tenantContext()->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $this->assertNotNull(MarketplaceAiUsageEvent::query()->find($platformWideId));
        $this->assertNull(MarketplaceAiUsageEvent::query()->find($firmRowId));
    }

    public function test_a_firm_scoped_session_cannot_insert_a_forged_platform_wide_row(): void
    {
        $firm = Firm::factory()->create();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firm, fn () => $this->insertRow(null, 'forged-platform-wide'));
    }

    public function test_a_genuinely_context_free_session_can_insert_a_platform_wide_row(): void
    {
        $this->tenantContext()->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $insertedId = $this->insertRow(null, 'legitimate-platform-wide');

        $this->assertIsInt($insertedId);
    }

    public function test_updating_an_existing_row_throws_a_logic_exception_at_the_app_layer(): void
    {
        $firm = Firm::factory()->create();
        $event = $this->runWithFirmContext($firm, fn () => MarketplaceAiUsageEvent::create([
            'firm_id' => $firm->id,
            'session_hash' => 'append-only-check',
            'provider' => AiProvider::OpenAi,
            'model' => 'fake-model-1',
            'action_type' => AiUsageActionType::IntakeClassification,
            'tokens_in' => 1,
            'tokens_out' => 1,
        ]));

        $this->expectException(\LogicException::class);

        $this->runWithFirmContext($firm, fn () => $event->update(['tokens_in' => 999]));
    }

    public function test_deleting_an_existing_row_throws_a_logic_exception_at_the_app_layer(): void
    {
        $firm = Firm::factory()->create();
        $event = $this->runWithFirmContext($firm, fn () => MarketplaceAiUsageEvent::create([
            'firm_id' => $firm->id,
            'session_hash' => 'append-only-delete-check',
            'provider' => AiProvider::OpenAi,
            'model' => 'fake-model-1',
            'action_type' => AiUsageActionType::IntakeClassification,
            'tokens_in' => 1,
            'tokens_out' => 1,
        ]));

        $this->expectException(\LogicException::class);

        $this->runWithFirmContext($firm, fn () => $event->delete());
    }
}
