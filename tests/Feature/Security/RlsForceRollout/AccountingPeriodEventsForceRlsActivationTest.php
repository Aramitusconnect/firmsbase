<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Enums\AccountingPeriodStatus;
use App\Models\AccountingPeriod;
use App\Models\AccountingPeriodEvent;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AccountingPeriodEventsForceRlsActivationTest — Accounting Integrity
 * Hardening Pass, item 7. Mirrors InvoiceWriteOffsForceRlsActivationTest
 * exactly: append-only immutable audit trail, FORCE RLS from the same
 * migration that created the table.
 */
class AccountingPeriodEventsForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    private function makePeriod(Firm $firm, FirmUser $actor): AccountingPeriod
    {
        return $this->runWithFirmContext($firm, fn () => AccountingPeriod::create([
            'firm_id' => $firm->id,
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'status' => AccountingPeriodStatus::Closed,
            'closed_by_firm_user_id' => $actor->id,
            'closed_at' => now(),
        ]));
    }

    public function test_accounting_period_events_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'accounting_period_events'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
        $this->assertTrue((bool) $row->relforcerowsecurity);
    }

    public function test_missing_tenant_context_cannot_read_accounting_period_events(): void
    {
        $firm = Firm::factory()->create();
        $actor = FirmUser::factory()->create(['firm_id' => $firm->id]);
        $period = $this->makePeriod($firm, $actor);
        AccountingPeriodEvent::factory()->forFirm($firm)->create([
            'accounting_period_id' => $period->id,
            'actor_firm_user_id' => $actor->id,
        ]);

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->assertSame(0, AccountingPeriodEvent::query()->count());
    }

    public function test_missing_tenant_context_cannot_insert_accounting_period_events(): void
    {
        $firm = Firm::factory()->create();
        $actor = FirmUser::factory()->create(['firm_id' => $firm->id]);
        $period = $this->makePeriod($firm, $actor);

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('accounting_period_events')->insert([
            'firm_id' => $firm->id,
            'accounting_period_id' => $period->id,
            'event_type' => 'closed',
            'actor_firm_user_id' => $actor->id,
            'created_at' => now(),
        ]);
    }

    public function test_firm_a_context_cannot_read_firm_b_accounting_period_events(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $actorA = FirmUser::factory()->create(['firm_id' => $firmA->id]);
        $actorB = FirmUser::factory()->create(['firm_id' => $firmB->id]);
        $periodA = $this->makePeriod($firmA, $actorA);
        $periodB = $this->makePeriod($firmB, $actorB);

        AccountingPeriodEvent::factory()->forFirm($firmA)->create([
            'accounting_period_id' => $periodA->id,
            'actor_firm_user_id' => $actorA->id,
        ]);
        $eventB = AccountingPeriodEvent::factory()->forFirm($firmB)->create([
            'accounting_period_id' => $periodB->id,
            'actor_firm_user_id' => $actorB->id,
        ]);

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => AccountingPeriodEvent::query()->pluck('id')->all(),
        );

        $this->assertNotContains($eventB->id, $visibleIds);
    }

    public function test_an_existing_period_event_can_never_be_updated_even_under_full_firm_context(): void
    {
        $firm = Firm::factory()->create();
        $actor = FirmUser::factory()->create(['firm_id' => $firm->id]);
        $period = $this->makePeriod($firm, $actor);
        $event = AccountingPeriodEvent::factory()->forFirm($firm)->create([
            'accounting_period_id' => $period->id,
            'actor_firm_user_id' => $actor->id,
        ]);

        $this->runWithFirmContext($firm, function () use ($event) {
            $this->expectException(\LogicException::class);
            $event->update(['reason' => 'tampered']);
        });
    }

    public function test_an_existing_period_event_can_never_be_deleted_even_under_full_firm_context(): void
    {
        $firm = Firm::factory()->create();
        $actor = FirmUser::factory()->create(['firm_id' => $firm->id]);
        $period = $this->makePeriod($firm, $actor);
        $event = AccountingPeriodEvent::factory()->forFirm($firm)->create([
            'accounting_period_id' => $period->id,
            'actor_firm_user_id' => $actor->id,
        ]);

        $this->runWithFirmContext($firm, function () use ($event) {
            $this->expectException(\LogicException::class);
            $event->delete();
        });
    }

    public function test_migration_down_fully_disables_row_level_security(): void
    {
        $migration = require base_path('database/migrations/2026_10_31_100003_prepare_row_level_security_and_force_rls_on_accounting_period_events_table.php');

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'accounting_period_events'");

            $this->assertFalse((bool) $row->relrowsecurity);
            $this->assertFalse((bool) $row->relforcerowsecurity);
        } finally {
            $migration->up();
        }
    }
}
