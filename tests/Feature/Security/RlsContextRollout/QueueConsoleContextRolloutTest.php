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
        // Platform Firm Provisioning workflow added ProvisionFirmCommand
        // (firms:provision) — reviewed and safe: it implements no
        // independent database-creation logic of its own at all: every
        // write happens inside FirmProvisioningService::provision(),
        // which does the identical work ProvisionFirmAction's own
        // closure does — Firm itself is created before any tenant
        // context exists (it is not RLS-protected, being the tenancy
        // boundary itself), and every subsequent FORCE-RLS-protected
        // write (FirmUser, FirmSettings, FirmLicense, entitlements, the
        // encryption key, the activation checklist, the audit event)
        // runs inside TenantContextService::runWithFirmContext() —
        // no raw SQL, no BYPASSRLS, no superuser role, no set_config
        // manipulation of any RLS-relevant session variable anywhere in
        // the command itself.
        // FIRMSVAULT — STAGING ADMIN STABILIZATION added
        // BootstrapStagingSandboxPlanCommand (plans:bootstrap-staging-sandbox)
        // — reviewed and safe: no independent database-creation logic;
        // every write happens inside PlanService::create(), which only
        // writes the non-RLS `plans` table (no firm_id, no BelongsToTenant
        // — see Plan's own docblock). No FORCE-RLS table is touched by
        // this command's own create() path at all.
        // feature/ses-event-consumer added ConsumeSesEventsCommand
        // (ses:consume-events) — reviewed and safe: it never sets
        // app.current_firm_id or touches a FORCE-RLS table directly
        // itself. It long-polls a plain SQS queue via Aws\Sqs\SqsClient
        // (no database access at all), delegates all parsing/business
        // logic to SesEventConsumerService, which resolves the firm via
        // the non-RLS notification_provider_correlations routing table
        // (never from the recipient email alone), then performs every
        // FORCE-RLS-protected read/write (SuppressionService::
        // recordBounce()/recordComplaint()) inside
        // TenantContextService::runWithFirmContext() for that resolved
        // firm — no raw SQL, no BYPASSRLS, no superuser role, no
        // set_config manipulation of any RLS-relevant session variable
        // outside that one reviewed wrap.
        // Firm Workspace master mission (seat-provisioning fix) added
        // ReportMissingPurchasedSeatsCommand (firms:report-missing-purchased-seats)
        // — reviewed and safe: default/report mode's per-firm loop
        // wraps every FirmLicense/FirmUser read inside
        // TenantContextService::runWithFirmContext() for that one firm
        // (the identical pattern PlatformFirmUserDirectoryService::
        // listAll() already establishes), never an unscoped cross-firm
        // query. --apply mode writes purchased_seats for exactly ONE
        // explicitly-named firm (--firm=<id>) inside its own
        // runWithFirmContext() wrap — no raw SQL, no BYPASSRLS, no
        // superuser role, no set_config manipulation of any
        // RLS-relevant session variable anywhere in this command.
        // Any OTHER command appearing here has not been reviewed for
        // the silent-bypass risk this test exists to catch.
        // FirmsVault staging follow-up ("Application Completion —
        // Catalogs + Firm-Owned Reference Data") added
        // InitializeDefaultFirmReferenceDataCommand
        // (firms:initialize-default-reference-data) — reviewed and
        // safe: default/report mode's per-firm loop wraps every
        // ExpenseCategory/LeadSource count query inside
        // TenantContextService::runWithFirmContext() for that one firm
        // (same pattern ReportMissingPurchasedSeatsCommand's own report()
        // already establishes), never an unscoped cross-firm query.
        // --apply mode writes default rows for exactly ONE explicitly-
        // named firm (--firm=<id>), entirely via
        // FirmDefaultReferenceDataService::seedAllDefaults(), which
        // itself wraps every write in its own runWithFirmContext() call
        // — no raw SQL, no BYPASSRLS, no superuser role, no set_config
        // manipulation of any RLS-relevant session variable anywhere in
        // this command.
        // AccountingIntegrityCheckCommand (accounting:integrity-check,
        // Accounting Integrity Hardening Pass, item 10) — reviewed and
        // safe: strictly read-only (never writes anything, to any
        // table), and every check runs entirely inside
        // AccountingIntegrityService::checkFirm(), which wraps its
        // ENTIRE body in TenantContextService::runWithFirmContext($firm,
        // ...) — the same one-firm-at-a-time context establishment
        // every other safe command in this allowlist uses. checkAllFirms()
        // does nothing but loop over Firm::query()->cursor() calling
        // checkFirm() once per firm; there is no unscoped cross-firm
        // query anywhere in this command or the service it calls.
        // Event-Driven Automation Engine (item 9) added four commands —
        // all reviewed and safe. DispatchAutomationEventsCommand
        // (automation:events:dispatch) and DispatchAutomationActionsCommand
        // (automation:actions:dispatch) are the SAME shape as
        // DispatchOutboxEventsCommand above: they enumerate only the
        // non-RLS `firms` table and dispatch one AutomationEventDispatchJob/
        // AutomationActionDispatchJob per activated firm; each job wraps
        // its own claim/match/execute work in
        // TenantAwareJobContext::runInFirmContext() (see those jobs' own
        // tests). SweepInvoiceOverdueEventsCommand
        // (automation:sweep:invoice-overdue) and SweepDeadlineEventsCommand
        // (automation:sweep:deadlines) iterate activated firms explicitly
        // and wrap every DomainEvent existence-check/write in
        // TenantContextService::runWithFirmContext($firm, ...) — no raw
        // SQL, no BYPASSRLS, no superuser role, no set_config
        // manipulation of any RLS-relevant session variable in any of
        // the four.
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
            'BootstrapStagingSandboxPlanCommand.php',
            'ConsumeSesEventsCommand.php',
            'ReportMissingPurchasedSeatsCommand.php',
            'InitializeDefaultFirmReferenceDataCommand.php',
            'AccountingIntegrityCheckCommand.php',
            'DispatchAutomationEventsCommand.php',
            'DispatchAutomationActionsCommand.php',
            'SweepInvoiceOverdueEventsCommand.php',
            'SweepDeadlineEventsCommand.php',
            // Predictive Matter Budget Alerts pass added
            // SweepMatterBudgetAlertsCommand (automation:sweep:matter-budgets)
            // — reviewed and safe: the SAME shape as
            // SweepInvoiceOverdueEventsCommand/SweepDeadlineEventsCommand
            // above. It enumerates only the non-RLS `firms` table, then
            // wraps each firm's ENTIRE per-matter work (recompute +
            // alert evaluation) in a single
            // TenantContextService::runWithFirmContext($firm, ...) call
            // — no raw SQL, no BYPASSRLS, no superuser role, no
            // set_config manipulation of any RLS-relevant session
            // variable.
            'SweepMatterBudgetAlertsCommand.php',
            // Leverage Ratio Optimizer pass added
            // SweepLeverageRecommendationsCommand
            // (automation:sweep:leverage-recommendations) — reviewed
            // and safe: the SAME shape as SweepMatterBudgetAlertsCommand
            // directly above, reusing its own matter_budgets-scoped
            // matter enumeration. It enumerates only the non-RLS
            // `firms` table, then wraps each firm's ENTIRE per-matter
            // recommendation evaluation AND stale-marking sweep in a
            // single TenantContextService::runWithFirmContext($firm,
            // ...) call — no raw SQL, no BYPASSRLS, no superuser role,
            // no set_config manipulation of any RLS-relevant session
            // variable.
            'SweepLeverageRecommendationsCommand.php',
            // Zero-Click Core Workflow Automation pass added
            // SweepDocumentRequestRemindersCommand — reviewed and safe:
            // the SAME shape as every sweep command above. It
            // enumerates only the non-RLS `firms` table, then wraps
            // each firm's ENTIRE per-item work in a single
            // TenantContextService::runWithFirmContext($firm, ...)
            // call — no raw SQL, no BYPASSRLS, no superuser role, no
            // set_config manipulation of any RLS-relevant session
            // variable. Calls only existing, unmodified canonical
            // services (DocumentChaseSchedulerService/
            // DocumentChaseService). A sibling
            // SweepPaymentPlanInstallmentsCommand was deliberately NOT
            // added — Payment Plan installment scheduling remains
            // INTENTIONALLY DEFERRED (Phase 14b, decision F) and
            // PaymentPlanInstallmentDueDeferredTest structurally
            // forbids any Console Command from referencing
            // PaymentPlanInstallmentService.
            'SweepDocumentRequestRemindersCommand.php',
            // Mission 2 (MyAttorney Marketplace Core), checkpoint 13
            // added PruneMarketplaceAnalyticsEventsCommand
            // (marketplace:analytics:prune) — reviewed and safe: it
            // deletes only from directory_marketplace_analytics_events,
            // a platform-Global, no-RLS, no-firm_id table (see that
            // table's own migration docblock) — no tenant table is ever
            // touched, no per-firm iteration is needed, no raw SQL, no
            // BYPASSRLS, no superuser role, no set_config manipulation
            // of any RLS-relevant session variable.
            'PruneMarketplaceAnalyticsEventsCommand.php',
            // Mission 3 (MyAttorney Conversion + AI Intake), checkpoint
            // 14 added SweepMarketplaceIntakeRetentionCommand
            // (marketplace:intakes:retention:sweep) — reviewed and
            // safe: the SAME per-firm sweep shape as
            // SweepDocumentRequestRemindersCommand above. It enumerates
            // only the non-RLS `firms` table (activation_status =
            // Activated), then wraps each firm's ENTIRE candidate
            // evaluation AND purge in a single
            // TenantContextService::runWithFirmContext($firm, ...)
            // call — no raw SQL, no BYPASSRLS, no superuser role, no
            // set_config manipulation of any RLS-relevant session
            // variable. Calls only the existing, unmodified
            // MarketplaceIntakeService::purgeExpiredPii().
            'SweepMarketplaceIntakeRetentionCommand.php',
            // Non-Payment Completion Program (reconciliation branch)
            // added three reviewed, safe commands:
            // SweepTaskOverdueStatusCommand (automation:sweep:task-overdue)
            // — the SAME per-firm sweep shape as
            // SweepDeadlineEventsCommand/SweepDocumentRequestRemindersCommand
            // above: enumerates only the non-RLS `firms` table
            // (activation_status = Activated), then wraps each firm's
            // ENTIRE Task query + refreshOverdueStatus() sweep in a
            // single TenantContextService::runWithFirmContext($firm,
            // ...) call — no raw SQL, no BYPASSRLS, no superuser role,
            // no set_config manipulation of any RLS-relevant session
            // variable.
            // EnsureNotificationTemplatesCommand
            // (firmsvault:ensure-notification-templates) — a thin
            // wrapper calling only NotificationTemplateSeeder::run(),
            // which itself calls
            // NotificationTemplateService::createGlobalDefault() — both
            // already reviewed and hardened by the notification_templates
            // FORCE RLS migration (2026_08_25_930031), which explicitly
            // documents wrapping this exact write path in
            // TenantContextService::runWithoutFirmContext() so it
            // satisfies that table's own asymmetric write policy (a
            // firm_id=NULL row may only be written with NO tenant
            // context active). This command introduces no new write
            // logic of its own.
            // ExpireStaleMarketplaceClaimsCommand
            // (marketplace:claims:expire-stale) — calls only
            // MarketplaceClaimService::expireStaleClaims(). Its initial
            // DirectoryClaim::query() enumeration is unscoped, but
            // directory_claims has no FORCE RLS migration at all (see
            // database/migrations/2026_11_10_100013_create_directory_claims_table.php
            // — a platform-wide marketplace claim table, not a
            // per-firm-owned FORCE-RLS resource) — there is no RLS to
            // bypass on the read. The one real mutation
            // (`->update(['state' => ClaimState::Expired])`) is wrapped
            // in TenantContextService::runWithFirmContext($firm, ...)
            // for the claim's own resolved firm, inside a transaction
            // with a row lock — no raw SQL, no BYPASSRLS, no superuser
            // role, no set_config manipulation of any RLS-relevant
            // session variable.
            'SweepTaskOverdueStatusCommand.php',
            'EnsureNotificationTemplatesCommand.php',
            'ExpireStaleMarketplaceClaimsCommand.php',
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
