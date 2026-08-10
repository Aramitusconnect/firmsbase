<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Models\DomainEvent;
use App\Models\Firm;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * DomainEventsForceRlsActivationTest — Event-Driven Automation Engine
 * pass. Proves domain_events' permanent FORCE ROW LEVEL SECURITY
 * (2026_11_04_100002) behaves correctly: fail-closed with no context,
 * correct cross-firm isolation, and that a legitimate claim-cycle write
 * (the only kind DomainEvent's own append-only guard permits) keeps
 * working.
 */
class DomainEventsForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    private function makeEvent(Firm $firm): DomainEvent
    {
        return $this->runWithFirmContext($firm, fn () => DomainEvent::factory()->forFirm($firm)->create());
    }

    public function test_domain_events_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'domain_events'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
        $this->assertTrue((bool) $row->relforcerowsecurity);
    }

    public function test_missing_tenant_context_cannot_read_domain_events(): void
    {
        $firm = Firm::factory()->create();
        $this->makeEvent($firm);

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->assertSame(0, DomainEvent::query()->count());
    }

    public function test_firm_a_context_cannot_read_firm_b_domain_events(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->makeEvent($firmA);
        $eventB = $this->makeEvent($firmB);

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => DomainEvent::query()->pluck('id')->all(),
        );

        $this->assertNotContains($eventB->id, $visibleIds);
    }

    public function test_legitimate_firm_context_writes_keep_working(): void
    {
        $firm = Firm::factory()->create();
        $event = $this->makeEvent($firm);

        $this->runWithFirmContext($firm, fn () => $event->update(['processing_status' => 'processed']));

        $reRead = $this->runWithFirmContext($firm, fn () => $event->fresh()->processing_status->value);
        $this->assertSame('processed', $reRead);
    }

    public function test_migration_down_fully_disables_row_level_security(): void
    {
        $migration = require base_path('database/migrations/2026_11_04_100002_prepare_row_level_security_and_force_rls_on_domain_events_table.php');

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'domain_events'");

            $this->assertFalse((bool) $row->relrowsecurity);
            $this->assertFalse((bool) $row->relforcerowsecurity);
        } finally {
            $migration->up();
        }
    }
}
