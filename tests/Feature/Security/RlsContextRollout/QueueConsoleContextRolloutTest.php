<?php

namespace Tests\Feature\Security\RlsContextRollout;

use App\Models\Client;
use App\Models\Firm;
use App\Models\Matter;
use App\Services\TenantContextService;
use App\Support\TenantAwareJobContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * QueueConsoleContextRolloutTest — Section 39A-2. Extends Section
 * 39A's QueueConsoleTenantContextTest proof to confirm the established
 * TenantAwareJobContext pattern generalizes correctly across MULTIPLE
 * priority tenant-owned surfaces at once (not just Client) — the exact
 * shape a future queued job or console command touching several
 * tenant tables must follow.
 *
 * No app/Console/Commands directory exists in this repository
 * (confirmed by direct inspection, unchanged since Section 39A) —
 * there is still no real command to retrofit. This proves the required
 * pattern instead: iterate firms explicitly and run each firm's work
 * inside that firm's own tenant context, never a single unscoped pass.
 */
class QueueConsoleContextRolloutTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_custom_console_commands_exist_that_could_silently_bypass_rls(): void
    {
        $commandsDir = base_path('app/Console/Commands');

        if (! is_dir($commandsDir)) {
            $this->assertTrue(true, 'No app/Console/Commands directory exists.');

            return;
        }

        // Section 39A-4B added two reviewed, read-only governance/
        // reporting commands (schema tenant-firewall + RLS enforcement
        // report). Neither iterates tenant-owned data without explicit
        // firm context — both operate purely on schema/catalog
        // metadata. Checkpoint 8 added three further reviewed commands
        // (outbox dispatch, retention sweep, and retry poll) that each
        // iterate tenant-owned data — all three do so via the
        // TenantAwareJobContext::runInFirmContext() pattern this test
        // documents, scoping every pass to an explicit firm rather than
        // reading across firms unscoped. Any OTHER command appearing
        // here has not been reviewed for the silent-bypass risk this
        // test exists to catch.
        $allowlist = [
            'SchemaTenantFirewallCommand.php',
            'RlsSecurityReportCommand.php',
            'DispatchOutboxEventsCommand.php',
            'SweepIntegrationRetentionCommand.php',
            'SyncRetryPollCommand.php',
        ];

        $files = array_map('basename', glob($commandsDir.'/*.php') ?: []);
        $unexpected = array_values(array_diff($files, $allowlist));

        $this->assertEmpty(
            $unexpected,
            'Unreviewed console command(s) found: '.implode(', ', $unexpected).'. Any new command must be reviewed for RLS-bypass risk and added to this allowlist explicitly.'
        );
    }

    public function test_job_pattern_requires_explicit_firm_context_across_multiple_tenant_tables(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        // MatterFactory::forFirm() ties its own nested Client to the
        // same firm (see MatterFactory's docblock), so each firm below
        // legitimately ends up with 2 clients (the explicit one + the
        // one nested inside its matter) and 1 matter.
        $clientA = Client::factory()->forFirm($firmA)->create();
        $matterA = Matter::factory()->forFirm($firmA)->create();
        $clientB = Client::factory()->forFirm($firmB)->create();
        Matter::factory()->forFirm($firmB)->create();

        // ClientFactory/MatterFactory deliberately leave the database
        // tenant context set to the last-created row's firm after
        // create() returns (see ClientFactory's own docblock) — clear
        // that baseline so "no context bleeds forward" below proves
        // what it actually claims to, rather than passing by accident.
        (new TenantContextService)->clearDatabaseTenantContext();

        DB::statement('ALTER TABLE clients FORCE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE matters FORCE ROW LEVEL SECURITY');

        $job = new class
        {
            use TenantAwareJobContext;
        };

        $resultA = $job->runInFirmContext($firmA, fn () => [
            'clients' => Client::withoutGlobalScopes()->pluck('id')->all(),
            'matters' => Matter::withoutGlobalScopes()->pluck('id')->all(),
        ]);

        $this->assertContains($clientA->id, $resultA['clients']);
        $this->assertNotContains($clientB->id, $resultA['clients']);
        $this->assertCount(2, $resultA['clients']);
        $this->assertSame([$matterA->id], $resultA['matters']);

        // No context bleeds forward between the model calls, and none
        // is left active once runInFirmContext() returns.
        $this->assertSame(0, Client::withoutGlobalScopes()->count());
        $this->assertSame(0, Matter::withoutGlobalScopes()->count());
        $this->assertNotSame($clientA->id, $clientB->id);
    }

    public function test_global_maintenance_pattern_iterates_firms_explicitly_across_multiple_tenant_tables(): void
    {
        $firms = Firm::factory()->count(3)->create();

        foreach ($firms as $firm) {
            // MatterFactory::forFirm() ties its own nested Client to
            // the same firm, so each firm below legitimately ends up
            // with 2 clients (the explicit one + the matter's nested
            // one) and 1 matter.
            Client::factory()->forFirm($firm)->create();
            Matter::factory()->forFirm($firm)->create();
        }

        // ClientFactory/MatterFactory deliberately leave the database
        // tenant context set to the last-created row's firm after
        // create() returns (see ClientFactory's own docblock) — clear
        // that baseline so "no context is active outside the loop"
        // below proves what it actually claims to, rather than passing
        // by accident.
        (new TenantContextService)->clearDatabaseTenantContext();

        DB::statement('ALTER TABLE clients FORCE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE matters FORCE ROW LEVEL SECURITY');

        $job = new class
        {
            use TenantAwareJobContext;
        };

        $perFirmCounts = [];

        foreach (Firm::withoutGlobalScopes()->get() as $firm) {
            $perFirmCounts[$firm->id] = $job->runInFirmContext($firm, fn () => [
                'clients' => Client::withoutGlobalScopes()->count(),
                'matters' => Matter::withoutGlobalScopes()->count(),
            ]);
        }

        foreach ($firms as $firm) {
            $this->assertSame(2, $perFirmCounts[$firm->id]['clients']);
            $this->assertSame(1, $perFirmCounts[$firm->id]['matters']);
        }

        // Unscoped, no-context reads must never silently return every
        // firm's rows at once — this is the exact failure mode a
        // global-maintenance command must avoid.
        $this->assertSame(0, Client::withoutGlobalScopes()->count());
        $this->assertSame(0, Matter::withoutGlobalScopes()->count());
    }
}
