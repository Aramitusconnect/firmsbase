<?php

namespace Tests\Feature\Security\RlsEnforcement;

use App\Models\Client;
use App\Models\Firm;
use App\Services\TenantContextService;
use App\Support\TenantAwareJobContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * QueueConsoleTenantContextTest — Section 39A.
 *
 * Queue-job pattern: proves TenantAwareJobContext (a trait a job class
 * can `use`) requires an EXPLICIT firm_id/Firm — never guessed from a
 * bare model id inside the job body — and correctly isolates tenant
 * data through the same TenantContextService mechanism. Deliberately
 * NOT retrofitted onto the 4 existing job classes in this pass (see
 * the trait's own docblock) — this proves the pattern in isolation so
 * future queue work has a tested precedent to follow.
 *
 * Console/global-maintenance pattern: no app/Console/Commands directory
 * exists in this repository (confirmed by direct inspection) — there is
 * no existing command to retrofit or rewrite. This proves the expected
 * pattern a future global-maintenance command MUST follow instead of
 * silently bypassing RLS: iterate firms explicitly and run each firm's
 * work inside that firm's own tenant context, never a single unscoped
 * pass over a tenant-owned table.
 */
class QueueConsoleTenantContextTest extends TestCase
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
        // reading across firms unscoped. Checkpoint 11 added
        // RefreshIntegrationPlatformOverviewSummariesCommand, a plain,
        // non-tenant scheduled command that only enumerates the
        // non-FORCE-RLS `firms` table and dispatches one
        // RefreshIntegrationPlatformOverviewSummaryJob per activated
        // firm; the job itself scopes its per-firm read via
        // TenantContextService::runWithFirmContext() before upserting
        // sanitized aggregate counts into the no-RLS
        // integration_platform_overview_summaries table — no RLS bypass.
        // FirmsVault Admin Control Center added
        // PlatformAdminEmergencyMfaResetCommand — reviewed and safe:
        // it touches only the non-tenant `platform_admins` table (no
        // firm_id, not RLS-scoped) plus one security_events write via
        // the already-reviewed PlatformAdminAuditEventRecorder::
        // recordConsoleEvent() path; no raw SQL, no BYPASSRLS, no
        // superuser role, no set_config manipulation of any RLS-relevant
        // session variable.
        // Phase 2 (FirmsVault Platform Admin Control Center,
        // "Integration Operations Center") added
        // RefreshIntegrationPlatformProviderHealthSummariesCommand —
        // reviewed and safe: the SAME shape as
        // RefreshIntegrationPlatformOverviewSummariesCommand immediately
        // above. It only enumerates the non-FORCE-RLS `integration_providers`
        // table (a small, static, seeded-only global reference catalog —
        // see that table's own create migration) and dispatches one
        // RefreshIntegrationPlatformProviderHealthSummaryJob per
        // provider; the job itself scopes every per-firm read via
        // TenantContextService::runWithFirmContext() (iterating each
        // activated firm explicitly, one firm's tenant context at a
        // time) before upserting sanitized aggregate counts into the
        // no-RLS integration_platform_provider_health_summaries table —
        // no RLS bypass, no raw SQL, no BYPASSRLS, no superuser role, no
        // production data seeded or mutated (read-and-aggregate only).
        // Phase 4 (FirmsVault Platform Admin Control Center,
        // "Operations") added RunHealthChecksCommand and
        // RecordSchedulerHeartbeatCommand — both reviewed and safe.
        // RunHealthChecksCommand dispatches the pre-existing, already-
        // tested RunHealthChecksJob with $firmId = null (the
        // platform-wide check run only — no tenant table read, no
        // context needed); the one firm-specific check type
        // (TenantIsolationAnomalies) is written out-of-band by
        // TenantIsolationAnomalyService::recordAnomaly() itself, not by
        // this command. RecordSchedulerHeartbeatCommand performs a
        // single synchronous Cache write via
        // SchedulerHealthService::recordHeartbeat() — no database
        // query, no tenant table, no RLS-relevant session variable
        // touched at all.
        // FirmsVault Live Integrations Checkpoint 2 added
        // RenewProviderWebhookSubscriptionsCommand — reviewed and safe:
        // it enumerates the non-RLS `firms` table directly (never the
        // FORCE-RLS integration_provider_webhook_subscriptions table
        // unscoped) and wraps every per-firm read of that table inside
        // TenantContextService::runWithFirmContext(), the identical
        // per-firm-loop pattern SyncRetryPollCommand/
        // SweepIntegrationRetentionCommand already establish above — no
        // RLS bypass, no raw SQL, no BYPASSRLS, no superuser role, no
        // set_config manipulation of any RLS-relevant session variable.
        // Any OTHER command appearing here has not been reviewed for
        // the silent-bypass risk this test exists to catch.
        // Platform Firm Provisioning workflow added ProvisionFirmCommand
        // (firms:provision) — reviewed and safe: see
        // QueueConsoleContextRolloutTest's own identical review note.
        // No independent database-creation logic; every write happens
        // inside FirmProvisioningService::provision(), which correctly
        // scopes every FORCE-RLS-protected write via
        // TenantContextService::runWithFirmContext() — no raw SQL, no
        // BYPASSRLS, no superuser role, no set_config manipulation.
        $allowlist = [
            'SchemaTenantFirewallCommand.php',
            'RlsSecurityReportCommand.php',
            'DispatchOutboxEventsCommand.php',
            'SweepIntegrationRetentionCommand.php',
            'SyncRetryPollCommand.php',
            'RefreshIntegrationPlatformOverviewSummariesCommand.php',
            'PlatformAdminEmergencyMfaResetCommand.php',
            'RefreshIntegrationPlatformProviderHealthSummariesCommand.php',
            'RunHealthChecksCommand.php',
            'RecordSchedulerHeartbeatCommand.php',
            'RenewProviderWebhookSubscriptionsCommand.php',
            'ProvisionFirmCommand.php',
        ];

        $files = array_map('basename', glob($commandsDir.'/*.php') ?: []);
        $unexpected = array_values(array_diff($files, $allowlist));

        $this->assertEmpty(
            $unexpected,
            'Unreviewed console command(s) found: '.implode(', ', $unexpected).'. Any new command must be reviewed for RLS-bypass risk and added to this allowlist explicitly.'
        );
    }

    public function test_tenant_aware_job_context_trait_requires_an_explicit_firm_not_a_guessed_model_id(): void
    {
        $job = new class
        {
            use TenantAwareJobContext;
        };

        $reflection = new \ReflectionMethod($job, 'runInFirmContext');
        $parameters = $reflection->getParameters();

        $this->assertCount(2, $parameters);
        $this->assertSame('firm', $parameters[0]->getName());
        $this->assertSame('callback', $parameters[1]->getName());
    }

    public function test_tenant_aware_job_context_correctly_isolates_tenant_data_per_firm(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $clientA = Client::factory()->forFirm($firmA)->create();
        $clientB = Client::factory()->forFirm($firmB)->create();

        DB::statement('ALTER TABLE clients FORCE ROW LEVEL SECURITY');

        $job = new class
        {
            use TenantAwareJobContext;
        };

        $visibleUnderA = $job->runInFirmContext($firmA, fn () => Client::withoutGlobalScopes()->pluck('id')->all());
        $visibleUnderB = $job->runInFirmContext($firmB, fn () => Client::withoutGlobalScopes()->pluck('id')->all());

        $this->assertSame([$clientA->id], $visibleUnderA);
        $this->assertSame([$clientB->id], $visibleUnderB);
    }

    public function test_global_maintenance_pattern_iterates_firms_explicitly_rather_than_bypassing_rls(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        Client::factory()->forFirm($firmA)->create();
        Client::factory()->forFirm($firmB)->create();

        // ClientFactory deliberately leaves the database tenant context
        // set to the last-created row's firm after create() returns
        // (see its own docblock) — clear that baseline so the "no
        // context is active outside the loop" assertion below proves
        // what it actually claims to, rather than passing by accident.
        (new TenantContextService)->clearDatabaseTenantContext();

        DB::statement('ALTER TABLE clients FORCE ROW LEVEL SECURITY');

        $job = new class
        {
            use TenantAwareJobContext;
        };

        // The expected pattern for a future global-maintenance command:
        // iterate every firm explicitly and scope each pass to that
        // firm's own context — never one unscoped query across all
        // firms at once.
        $countsPerFirm = [];

        foreach (Firm::withoutGlobalScopes()->get() as $firm) {
            $countsPerFirm[$firm->id] = $job->runInFirmContext(
                $firm,
                fn () => Client::withoutGlobalScopes()->count(),
            );
        }

        $this->assertSame(1, $countsPerFirm[$firmA->id]);
        $this->assertSame(1, $countsPerFirm[$firmB->id]);

        // Outside any per-firm iteration, no context is active — an
        // unscoped read must never silently return every firm's rows.
        $this->assertSame(0, Client::withoutGlobalScopes()->count());
    }
}
