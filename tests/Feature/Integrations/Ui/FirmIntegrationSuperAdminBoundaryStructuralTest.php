<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Ui;

use Illuminate\Foundation\Testing\RefreshDatabase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * FirmIntegrationSuperAdminBoundaryStructuralTest — Checkpoint 10
 * (frozen-design-post-security-review.md §11 "SuperAdmin boundary" hard
 * constraint; §9's credential-DTO-only-binding structural rule). Two
 * independent, purely structural (find/reflection-based, never a
 * runtime request) checks:
 *
 * 1. No file under `app/Filament/Resources`, `app/Filament/Pages`, or
 *    `app/Filament/Widgets` (the `admin`-panel namespace) may exist or
 *    reference any Integration-domain class — the hard SuperAdmin
 *    boundary this checkpoint's diff-review.md confirmed via
 *    `find ... -> "No such file or directory"`.
 * 2. No file under `app/Filament/Firm/**` may contain the literal
 *    string `IntegrationCredential::class` outside
 *    `getMaskedMetadata()`-adjacent DTO-construction code — mirrors the
 *    proven `str_contains()`-scan convention from
 *    `IntegrationWebhookRoutingIndexNoRlsAndNoSecretColumnTest`.
 *
 * POST-CHECKPOINT-11 UPDATE (reviews/checkpoint-11/frozen-design-post-
 * security-review.md §1, §12): Checkpoint 11 legitimately, deliberately
 * introduces a SECOND, entirely separate Integration-domain surface
 * under the `admin` panel — `app/Filament/Pages/Platform*.php` (3
 * classes: PlatformIntegrationOverviewPage, PlatformFirmIntegrationsPage,
 * PlatformFirmIntegrationDetailPage) and
 * `app/Filament/Actions/Platform/*.php` (7 action classes) — this is
 * expected, reviewed, DIFF_APPROVED new production surface (Checkpoint
 * 11's own frozen production-file allowlist §12), not a violation of
 * item 1's original "hard SuperAdmin boundary" rule. The REAL invariant
 * that rule protected was never literally "zero Integration-domain
 * classes under the admin panel, forever" — it was "no unauthorized
 * SuperAdmin-shaped Integration class exists outside a reviewed,
 * allowlisted surface, and Checkpoint 10's own Firm-panel tree
 * (app/Filament/Firm/**) never gains a SuperAdmin-shaped sibling smuggled
 * in alongside it." Both structural checks below are updated to encode
 * exactly that distinction: they allowlist ONLY Checkpoint 11's own 10
 * frozen files by exact basename, and continue to reject ANY other
 * Integration-domain class anywhere under `app/Filament/Resources`,
 * `app/Filament/Widgets`, or any other file under `app/Filament/Pages`/
 * `app/Filament/Actions` not on that allowlist — including, critically,
 * still rejecting one smuggled into `app/Filament/Firm/**` disguised as
 * a legitimate Firm-panel file. This does not weaken the invariant; it
 * narrows what counts as "authorized" from "nothing" to "exactly
 * Checkpoint 11's own reviewed allowlist," mirroring Checkpoint 10's own
 * 97-file cascade-update precedent for expected, reviewed breakage.
 *
 * POST-PHASE-1-ADMIN-CONTROL-CENTER UPDATE (FirmsVault Admin Control
 * Center mission, Phase 1 foundational build): this checkpoint adds a
 * THIRD, entirely separate, non-Integration-domain surface to the admin
 * panel — `app/Filament/Resources/FirmResource.php` +
 * `FirmUserResource.php` (plus their List/View Pages subdirectories) and
 * two more `app/Filament/Pages/Platform*.php` classes
 * (PlatformSecurityDashboardPage, PlatformTenantIsolationPage). None of
 * these 8 new files reference the Integration domain in any way (zero
 * `App\Integrations\` usage, zero `FirmIntegrationResource` reference —
 * this file's OWN `assertNoIntegrationDomainReferenceUnder()` sweep,
 * run against `app/Filament/Resources` and `app/Filament/Pages`
 * unconditionally, independently re-confirms this on every test run,
 * not merely by construction here); they gate cross-firm Firm/FirmUser
 * oversight and security/RLS reporting instead, an unrelated concern
 * this checkpoint's own architecture investigation scoped explicitly.
 * Cascading the allowlist below forward to include them follows this
 * file's own already-twice-established "cascade update" precedent
 * (Checkpoint 10's 97-file update, then this file's own Checkpoint 11
 * update) — it does not touch the two Integration-domain sweeps above,
 * which continue to run unconditionally against every file in these
 * directories (including these 8 new ones) and would independently
 * fail if any of them ever referenced the Integration domain.
 *
 * POST-PHASE-1-MFA-AND-PLATFORM-ADMINISTRATORS UPDATE (same mission,
 * later checkpoint): a fourth cascade, same reasoning again — the MFA
 * system (login page, audited MFA provider subclass, middleware lives
 * under app/Http/Middleware so it is out of this sweep's scope
 * entirely) and the new Platform Administrators resource + Roles/
 * Permissions page. See $mfaAndPlatformAdministratorAllowedRelativeFiles
 * below.
 *
 * POST-PHASE-1-EXECUTIVE-DASHBOARD UPDATE (same mission, final Phase 1
 * scope item): a fifth cascade — the Executive Dashboard
 * (App\Filament\Pages\Dashboard, replacing Filament's stock dashboard)
 * and its 7 Widget classes, the first files ever created under
 * app/Filament/Widgets/. One of the 7, PlatformIntegrationsHealthWidget,
 * legitimately references the Integration domain (aggregates the
 * existing, already-reviewed integration_platform_overview_summaries
 * table) — narrowly allowlisted in
 * test_app_filament_widgets_directory_contains_no_integration_domain_class()
 * itself, not merely in the broader non-Firm-file sweep below. See
 * $executiveDashboardAllowedRelativeFiles below.
 *
 * POST-PHASE-2-INTEGRATION-OPERATIONS-CENTER UPDATE (FirmsVault
 * Platform Admin Control Center mission, Phase 2 — "Integration
 * Operations Center"): a sixth cascade, same reasoning again. This
 * phase legitimately, deliberately expands the admin panel's own
 * Integration-domain surface (correctly, this time — unlike Checkpoint
 * 10/11's "hard boundary," these files ARE meant to reference
 * App\Integrations\* directly, since their whole job is cross-firm
 * Integration oversight) with TWO concurrent, independently-scoped
 * passes landing in this same shared worktree:
 *   - This pass's own scope (Integration Overview upgrade, the new
 *     cross-firm ConnectionResource, Provider Health page, drill-down
 *     cross-links): Pages/PlatformProviderHealthPage.php,
 *     Widgets/PlatformIntegrationOverviewSummaryCardsWidget.php,
 *     Resources/ConnectionResource.php + its List/View Pages,
 *     Actions/Platform/DisconnectConnectionAction.php.
 *   - A parallel pass's scope (Sync Failures, Webhook Events,
 *     Dead-Letter Queue, Conflicts, Integration Usage), landed
 *     concurrently in this same worktree: Resources/SyncFailureResource.php,
 *     Resources/WebhookEventResource.php,
 *     Resources/DeadLetterQueueResource.php, Resources/ConflictResource.php
 *     (each + their List/View Pages), Pages/PlatformIntegrationUsagePage.php,
 *     Actions/Platform/RetrySyncFailureAction.php,
 *     Actions/Platform/RequeueDeadLetterQueueEventAction.php.
 * Both passes' new Integration-domain-referencing files are correctly
 * exempted in test_app_filament_pages_directory_contains_no_integration_domain_class()/
 * test_app_filament_resources_directory_does_not_exist_or_contains_no_integration_domain_class()/
 * test_app_filament_widgets_directory_contains_no_integration_domain_class()
 * (via each directory's own allowedBasenames list) and in
 * $phase2IntegrationOperationsCenterAllowedRelativeFiles below —
 * mirroring every prior cascade's exact allowlist-widening pattern, not
 * a weakening of the underlying invariant.
 *
 * POST-CHECKPOINT-8.2-BOOTSTRAP-RECONCILIATION UPDATE (FirmsVault
 * Billing Durability Redesign, Checkpoint 8.2, "webhook-bootstrap
 * retries + provider-operation reconciliation" mission): a seventh
 * cascade, same reasoning again. This mission adds the FIRST production
 * caller anywhere in the codebase of
 * `ProviderOperationAttemptService::resolveReconciliation()` — a
 * Platform Admin page (`PlatformProviderOperationReconciliationPage.php`)
 * that lists/filters durable-gate rows stuck in `reconciliation_required`
 * and three resolution actions
 * (`ConfirmProviderOperationSucceededAction.php`,
 * `AuthorizeProviderOperationRetryAction.php`,
 * `ResolveProviderOperationWithoutRetryAction.php`), plus a shared
 * audit-recording trait
 * (`Concerns/AuditsProviderOperationReconciliation.php`). The page
 * legitimately, deliberately references the Integration domain (its
 * whole job is cross-firm provider-operation oversight) and is
 * allowlisted in
 * test_app_filament_pages_directory_contains_no_integration_domain_class()
 * accordingly; the three actions and the trait carry no separate
 * Integration-domain sweep of their own (app/Filament/Actions has never
 * had one — only Resources/Pages/Widgets do) but still need an entry in
 * $checkpoint82ProviderOperationReconciliationAllowedRelativeFiles below
 * since none of them live under app/Filament/Firm.
 *
 * COORDINATION NOTE: since two agents land files in this one shared test
 * concurrently, whichever pass's commit lands second should re-verify
 * this allowlist still matches the real file set on disk before commit
 * (e.g. re-run this file's own tests) — this update reflects the file
 * set present in the shared worktree at the time this pass's own
 * verification ran, not a guess.
 *
 * POST-MISSION-1B-EXTREME-SECURITY-HARDENING UPDATE: a ninth cascade —
 * WebAuthn/passkey infrastructure and its Actions (Platform Admin
 * only), the reusable StepUpAuthentication helper (guard-agnostic,
 * used by WebAuthn's own DisableWebAuthnCredentialAction and by future
 * protected operations across all three panels — deliberately not
 * under app/Filament/Firm since it isn't Firm-specific),
 * ThrottlesLoginsPerAccount, and per-panel Platform Admin/Client
 * Portal Login/RequestPasswordReset/ResetPassword subclasses added to
 * close a shared-rate-limit-bucket finding. See
 * $mission1bExtremeSecurityHardeningAllowedRelativeFiles below.
 *
 * POST-MISSION-1C-SECURITY-VALIDATION-ACTIVATION UPDATE: a tenth
 * cascade — AuditedFirmUserAppAuthentication, the Firm-user MFA
 * audit-trail sibling to MultiFactor/AuditedAppAuthentication.php
 * above. See $mission1cSecurityValidationActivationAllowedRelativeFiles
 * below.
 *
 * POST-MISSION-2-MYATTORNEY-MARKETPLACE-CORE-CHECKPOINT-11 UPDATE: an
 * eleventh cascade — Checkpoint 11's SuperAdmin/PlatformAdmin
 * marketplace-governance surface (4 Resources + 15 Actions, gated
 * behind PlatformStaffAccessPolicyService::canManageMarketplaceGovernance()).
 * None of it references the Integration domain. See
 * $mission2MarketplaceSuperAdminControlsAllowedRelativeFiles below.
 */
final class FirmIntegrationSuperAdminBoundaryStructuralTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------
    // 1. Hard SuperAdmin boundary: zero Integration-domain classes
    //    under the admin-panel namespace directories.
    // ------------------------------------------------------------

    public function test_app_filament_resources_directory_does_not_exist_or_contains_no_integration_domain_class(): void
    {
        $dir = base_path('app/Filament/Resources');

        if (! is_dir($dir)) {
            $this->addToAssertionCount(1); // the directory not existing at all is itself compliant

            return;
        }

        // POST-PHASE-2-INTEGRATION-OPERATIONS-CENTER UPDATE: see this
        // file's own class docblock — these are Phase 2's own two
        // concurrent, reviewed cross-firm Integration-oversight
        // Resources (this pass's ConnectionResource, and a parallel
        // pass's SyncFailureResource/WebhookEventResource/
        // DeadLetterQueueResource/ConflictResource), each legitimately
        // referencing the Integration domain by design.
        // POST-CHECKPOINT-4-PLAID UPDATE (FirmsVault Live Integrations,
        // Checkpoint 4, "Plaid financial evidence add-on"): two more —
        // ProviderKillSwitchResource.php (the ONE place PlatformAdmin
        // writes for Plaid cost control — see that class's own docblock)
        // and PlaidItemOversightResource.php (cross-firm, redacted/
        // summary-only Plaid Item oversight, per this checkpoint's own
        // "PlatformAdmin must be redacted/summary-only" requirement).
        // Both confirmed gated behind Auth::guard('platform_admin') +
        // PlatformStaffAccessPolicyService, the same pattern every prior
        // cascade entry here already establishes.
        // NON-PAYMENT-COMPLETION-PROGRAM UPDATE (reconciliation branch):
        // ViewFirm.php's Firm-360 sections add a real, cross-firm-oversight
        // "Integrations" section (firm_integrations, FORCE RLS, queried
        // directly inside its own runWithFirmContext($record, ...) call
        // — see that class's own docblock) — a legitimate, deliberate
        // Integration-domain reference for exactly the same reason
        // every other allowlisted file above is legitimate: real
        // Platform Admin cross-firm oversight.
        $this->assertNoIntegrationDomainReferenceUnder($dir, allowedBasenames: [
            'ConnectionResource.php',
            'SyncFailureResource.php',
            'WebhookEventResource.php',
            'DeadLetterQueueResource.php',
            'ConflictResource.php',
            'ProviderKillSwitchResource.php',
            'PlaidItemOversightResource.php',
            'ViewFirm.php',
        ]);
    }

    public function test_app_filament_pages_directory_contains_no_integration_domain_class(): void
    {
        $dir = base_path('app/Filament/Pages');

        if (! is_dir($dir)) {
            $this->addToAssertionCount(1);

            return;
        }

        // POST-CHECKPOINT-11: exactly these 3 files are Checkpoint 11's
        // own frozen, reviewed, DIFF_APPROVED admin-panel Integration
        // oversight pages (§12) — legitimately live here, not a
        // violation. Anything else Integration-domain-shaped under this
        // directory (including a file with a DIFFERENT name that still
        // references the Integration domain) remains rejected.
        //
        // POST-PHASE-2-INTEGRATION-OPERATIONS-CENTER UPDATE: two more —
        // this pass's own PlatformProviderHealthPage.php and a parallel
        // pass's PlatformIntegrationUsagePage.php — see this file's own
        // class docblock.
        // POST-CHECKPOINT-4-PLAID UPDATE: two more — PlaidCostOversightPage.php
        // and PlaidAnomalyOversightPage.php, both confirmed gated behind
        // Auth::guard('platform_admin') + PlatformStaffAccessPolicyService,
        // same pattern as every prior cascade entry here.
        $this->assertNoIntegrationDomainReferenceUnder($dir, allowedBasenames: [
            'PlatformIntegrationOverviewPage.php',
            'PlatformFirmIntegrationsPage.php',
            'PlatformFirmIntegrationDetailPage.php',
            'PlatformProviderHealthPage.php',
            'PlatformIntegrationUsagePage.php',
            'PlaidCostOversightPage.php',
            'PlaidAnomalyOversightPage.php',
            // POST-CHECKPOINT-8.2-BOOTSTRAP-RECONCILIATION: the first
            // production caller of resolveReconciliation() — see this
            // file's own class docblock.
            'PlatformProviderOperationReconciliationPage.php',
        ]);
    }

    public function test_app_filament_widgets_directory_contains_no_integration_domain_class(): void
    {
        $dir = base_path('app/Filament/Widgets');

        if (! is_dir($dir)) {
            $this->addToAssertionCount(1);

            return;
        }

        // POST-PHASE-1-EXECUTIVE-DASHBOARD UPDATE: app/Filament/Widgets
        // did not exist at all before this checkpoint — the Executive
        // Dashboard is the first thing to populate it. Exactly one of
        // its 7 widgets, PlatformIntegrationsHealthWidget, legitimately
        // references the Integration domain (it aggregates
        // integration_platform_overview_summaries via
        // IntegrationPlatformOversightReadService — the same no-RLS,
        // already-5-minute-refreshed summary table
        // PlatformIntegrationOverviewPage already reads, never a new
        // live query) — the same class of reviewed exception Checkpoint
        // 11's own PlatformIntegrationOverviewPage/
        // PlatformFirmIntegrationsPage/PlatformFirmIntegrationDetailPage
        // already carry above. The other 6 widgets carry no Integration
        // reference at all and remain covered by the unconditional
        // sweep.
        //
        // POST-PHASE-2-INTEGRATION-OPERATIONS-CENTER UPDATE: one more —
        // this pass's own PlatformIntegrationOverviewSummaryCardsWidget.php
        // (same class of reviewed exception: it aggregates the same
        // no-RLS integration_platform_overview_summaries table via a
        // single bounded SQL query — see that class's own docblock for
        // why it does not go through IntegrationPlatformOversightReadService
        // the way PlatformIntegrationsHealthWidget does).
        $this->assertNoIntegrationDomainReferenceUnder($dir, allowedBasenames: [
            'PlatformIntegrationsHealthWidget.php',
            'PlatformIntegrationOverviewSummaryCardsWidget.php',
        ]);
    }

    public function test_no_platform_integration_health_access_policy_service_shaped_class_exists_anywhere(): void
    {
        // POST-CHECKPOINT-11 UPDATE: this originally forward-looking
        // guard ("no such class may exist YET — Checkpoint 11 scope")
        // is now retrospective — Checkpoint 11 has landed and did NOT
        // use this literal name (it shipped
        // PlatformFirmIntegrationBoundedAccessService/
        // IntegrationPlatformOversightReadService instead, both
        // legitimate, reviewed, DIFF_APPROVED classes on Checkpoint 11's
        // own frozen allowlist — see this file's class docblock). The
        // check itself needs no logic change: a class with this EXACT
        // placeholder name was never authorized by any checkpoint and
        // still must not exist under any name-collision scenario.
        $violations = [];

        foreach ($this->phpFilesUnder(base_path('app')) as $file) {
            if (str_contains(basename($file), 'PlatformIntegrationHealthAccessPolicyService')) {
                $violations[] = $file;
            }
        }

        $this->assertEmpty($violations, 'No PlatformIntegrationHealthAccessPolicyService-shaped class may exist — no checkpoint ever authorized this literal name: '.implode(', ', $violations));
    }

    public function test_every_new_checkpoint_10_filament_class_lives_exclusively_under_app_filament_firm(): void
    {
        $filamentDir = base_path('app/Filament');
        $this->assertTrue(is_dir($filamentDir), 'app/Filament must exist.');

        // POST-CHECKPOINT-11: exactly these 10 files (3 Pages + 7
        // Actions/Platform) are Checkpoint 11's own frozen, reviewed,
        // DIFF_APPROVED admin-panel surface (§12) — a second,
        // legitimately separate namespace from Checkpoint 10's
        // app/Filament/Firm tree, explicitly authorized by frozen design
        // §1 ("entirely new to the admin panel... distinct namespace
        // from Checkpoint 10's Firm-panel classes, zero overlap"). Any
        // OTHER file outside app/Filament/Firm and outside this exact
        // allowlist remains rejected — including a file smuggled into
        // app/Filament/Firm itself that doesn't belong there, which this
        // check cannot see (it only enumerates NON-Firm files) but which
        // test_no_file_under_app_filament_firm_contains_integration_credential_class_outside_the_one_allowed_dto_construction_site()
        // and the Integration-domain sweep above cover from the other
        // direction.
        $checkpoint11AllowedRelativeFiles = [
            'Pages'.DIRECTORY_SEPARATOR.'PlatformIntegrationOverviewPage.php',
            'Pages'.DIRECTORY_SEPARATOR.'PlatformFirmIntegrationsPage.php',
            'Pages'.DIRECTORY_SEPARATOR.'PlatformFirmIntegrationDetailPage.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'RequeueOutboxEventAsSupportAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'RequeueSyncItemAsSupportAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'NudgeIntegrationQueueAsSupportAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'RequestSupportAccessAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'EnterSupportAccessSessionAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'LeaveSupportAccessSessionAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'RevokeSupportAccessSessionAction.php',
        ];

        // Phase 1 FirmsVault Admin Control Center's own new, reviewed,
        // non-Integration-domain admin-panel surface — see this file's
        // class docblock's "POST-PHASE-1-ADMIN-CONTROL-CENTER UPDATE"
        // note for why this cascade is safe (the Integration-domain
        // sweeps above run unconditionally against every one of these
        // files too).
        $phase1AdminControlCenterAllowedRelativeFiles = [
            'Resources'.DIRECTORY_SEPARATOR.'FirmResource.php',
            'Resources'.DIRECTORY_SEPARATOR.'FirmResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ListFirms.php',
            'Resources'.DIRECTORY_SEPARATOR.'FirmResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ViewFirm.php',
            // CORE SuperAdmin mission (admin/core-superadmin-security):
            // EditFirm.php — safe-metadata edit page (FirmResource
            // previously had List+View only, see that Resource's own
            // docblock) — and InviteFirmUserAction.php — the first
            // invitation capability FirmUserResource has ever had.
            'Resources'.DIRECTORY_SEPARATOR.'FirmResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'EditFirm.php',
            'Resources'.DIRECTORY_SEPARATOR.'FirmUserResource.php',
            'Resources'.DIRECTORY_SEPARATOR.'FirmUserResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ListFirmUsers.php',
            'Resources'.DIRECTORY_SEPARATOR.'FirmUserResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ViewFirmUser.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'InviteFirmUserAction.php',
            // Phase 5, section 61: bounded CSV export mirroring the
            // established League\Csv/streamDownload pattern — see that
            // action's own docblock.
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'ExportFirmInventoryCsvAction.php',
            'Pages'.DIRECTORY_SEPARATOR.'PlatformSecurityDashboardPage.php',
            'Pages'.DIRECTORY_SEPARATOR.'PlatformTenantIsolationPage.php',
        ];

        // FirmsVault Admin Control Center MFA system + Platform
        // Administrators resource + Roles/Permissions page (same
        // mission as the Phase 1 block above, later checkpoint) —
        // another entirely new, non-Integration-domain admin-panel
        // surface (MFA enrollment/enforcement, platform-administrator
        // management, role catalog). Same cascade-safety reasoning: the
        // Integration-domain sweeps above run unconditionally against
        // every one of these files too and would independently fail if
        // any of them referenced the Integration domain.
        $mfaAndPlatformAdministratorAllowedRelativeFiles = [
            'Auth'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'PlatformAdminLogin.php',
            'MultiFactor'.DIRECTORY_SEPARATOR.'AuditedAppAuthentication.php',
            'Pages'.DIRECTORY_SEPARATOR.'PlatformRolesAndPermissionsPage.php',
            // CORE SuperAdmin mission (admin/core-superadmin-security):
            // a real per-role drill-down, reached only via the catalog
            // page's own "View details" link — see that class's own
            // docblock.
            'Pages'.DIRECTORY_SEPARATOR.'PlatformRoleDetailPage.php',
            'Resources'.DIRECTORY_SEPARATOR.'PlatformAdministratorResource.php',
            'Resources'.DIRECTORY_SEPARATOR.'PlatformAdministratorResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ListPlatformAdministrators.php',
            'Resources'.DIRECTORY_SEPARATOR.'PlatformAdministratorResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ViewPlatformAdministrator.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'ResetPlatformAdminMfaAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'TogglePlatformAdminActiveStatusAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'AssignPlatformAdminRoleAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'RevokePlatformAdminRoleAction.php',
            // CORE SuperAdmin mission (admin/core-superadmin-security):
            // a standalone "Revoke Sessions" action, independent of
            // activation/MFA state — see that class's own docblock.
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'RevokePlatformAdminSessionsAction.php',
        ];

        // Phase 1 FirmsVault Admin Control Center, final scope item —
        // the Executive Dashboard: App\Filament\Pages\Dashboard
        // (overrides/replaces Filament's stock dashboard, see
        // AdminPanelProvider's own docblock) plus its 7 Widget classes
        // under the newly-created app/Filament/Widgets/ directory. Same
        // cascade-safety reasoning as every block above — the
        // Integration-domain sweeps run unconditionally against every
        // one of these files too (see
        // test_app_filament_widgets_directory_contains_no_integration_domain_class's
        // own narrower, single-file allowlist for the one legitimate
        // exception among these 7).
        $executiveDashboardAllowedRelativeFiles = [
            'Pages'.DIRECTORY_SEPARATOR.'Dashboard.php',
            'Widgets'.DIRECTORY_SEPARATOR.'PlatformEnvironmentBadgeWidget.php',
            'Widgets'.DIRECTORY_SEPARATOR.'PlatformFirmsOverviewWidget.php',
            'Widgets'.DIRECTORY_SEPARATOR.'PlatformAdministratorsOverviewWidget.php',
            'Widgets'.DIRECTORY_SEPARATOR.'PlatformIntegrationsHealthWidget.php',
            'Widgets'.DIRECTORY_SEPARATOR.'PlatformSecurityOverviewWidget.php',
            'Widgets'.DIRECTORY_SEPARATOR.'PlatformSystemHealthWidget.php',
            'Widgets'.DIRECTORY_SEPARATOR.'PlatformRecentPrivilegedActivityWidget.php',
            // CORE SuperAdmin mission, Phase 5: prominent "Requires
            // Attention" surface — derives entirely from the existing
            // snapshot, no new query (see its own class docblock).
            'Widgets'.DIRECTORY_SEPARATOR.'PlatformRequiresAttentionWidget.php',
        ];

        // Phase 2 FirmsVault Admin Control Center ("Integration
        // Operations Center") — see this file's own class docblock's
        // "POST-PHASE-2-INTEGRATION-OPERATIONS-CENTER UPDATE" note. Two
        // concurrent, independently-scoped passes landed in this same
        // shared worktree; both sets of new files are listed here.
        $phase2IntegrationOperationsCenterAllowedRelativeFiles = [
            // This pass's own scope.
            'Pages'.DIRECTORY_SEPARATOR.'PlatformProviderHealthPage.php',
            'Widgets'.DIRECTORY_SEPARATOR.'PlatformIntegrationOverviewSummaryCardsWidget.php',
            'Resources'.DIRECTORY_SEPARATOR.'ConnectionResource.php',
            'Resources'.DIRECTORY_SEPARATOR.'ConnectionResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ListConnections.php',
            'Resources'.DIRECTORY_SEPARATOR.'ConnectionResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ViewConnection.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'DisconnectConnectionAction.php',
            // A parallel pass's scope (Sync Failures/Webhook Events/
            // Dead-Letter Queue/Conflicts/Integration Usage), landed
            // concurrently in this same worktree.
            'Pages'.DIRECTORY_SEPARATOR.'PlatformIntegrationUsagePage.php',
            'Resources'.DIRECTORY_SEPARATOR.'SyncFailureResource.php',
            'Resources'.DIRECTORY_SEPARATOR.'SyncFailureResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ListSyncFailures.php',
            'Resources'.DIRECTORY_SEPARATOR.'SyncFailureResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ViewSyncFailure.php',
            'Resources'.DIRECTORY_SEPARATOR.'WebhookEventResource.php',
            'Resources'.DIRECTORY_SEPARATOR.'WebhookEventResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ListWebhookEvents.php',
            'Resources'.DIRECTORY_SEPARATOR.'WebhookEventResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ViewWebhookEvent.php',
            'Resources'.DIRECTORY_SEPARATOR.'DeadLetterQueueResource.php',
            'Resources'.DIRECTORY_SEPARATOR.'DeadLetterQueueResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ListDeadLetterQueueEvents.php',
            'Resources'.DIRECTORY_SEPARATOR.'DeadLetterQueueResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ViewDeadLetterQueueEvent.php',
            'Resources'.DIRECTORY_SEPARATOR.'ConflictResource.php',
            'Resources'.DIRECTORY_SEPARATOR.'ConflictResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ListConflicts.php',
            'Resources'.DIRECTORY_SEPARATOR.'ConflictResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ViewConflict.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'RetrySyncFailureAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'RequeueDeadLetterQueueEventAction.php',
        ];

        // Phase 3 FirmsVault Admin Control Center ("Billing and
        // Commercial Administration") — same cascade-widening pattern
        // as every prior phase. Unlike Phase 1/2, none of these files
        // reference the Integration domain at all (platform billing is
        // its own separate domain), but they still live outside
        // app/Filament/Firm and so still need an explicit allowlist
        // entry against this file's broader "which non-Firm files are
        // allowed to exist" sweep. Two concurrent, independently-scoped
        // passes landed in this same shared worktree; both sets of new
        // files are listed here.
        $phase3BillingAndCommercialAdministrationAllowedRelativeFiles = [
            // Subscriptions/Plans/Add-ons/Trials pass.
            'Resources'.DIRECTORY_SEPARATOR.'PlatformSubscriptionResource.php',
            'Resources'.DIRECTORY_SEPARATOR.'PlatformSubscriptionResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ListPlatformSubscriptions.php',
            'Resources'.DIRECTORY_SEPARATOR.'PlatformSubscriptionResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ViewPlatformSubscription.php',
            'Resources'.DIRECTORY_SEPARATOR.'PlanResource.php',
            'Resources'.DIRECTORY_SEPARATOR.'PlanResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ListPlans.php',
            'Resources'.DIRECTORY_SEPARATOR.'PlanResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ViewPlan.php',
            'Resources'.DIRECTORY_SEPARATOR.'PlanAddOnResource.php',
            'Resources'.DIRECTORY_SEPARATOR.'PlanAddOnResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ListPlanAddOns.php',
            'Resources'.DIRECTORY_SEPARATOR.'PlanAddOnResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ViewPlanAddOn.php',
            'Resources'.DIRECTORY_SEPARATOR.'TrialRequestResource.php',
            'Resources'.DIRECTORY_SEPARATOR.'TrialRequestResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ListTrialRequests.php',
            'Resources'.DIRECTORY_SEPARATOR.'TrialRequestResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ViewTrialRequest.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'CancelSubscriptionAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'ActivatePlanAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'ArchivePlanAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'CreatePlanAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'EditPlanAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'SetPlanModuleEnabledAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'RetirePlanModuleAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'AddPlanModuleAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'ProvisionTrialRequestAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'ActivateTrialRequestAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'ExpireTrialRequestAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'ConvertTrialRequestAction.php',
            // Invoices/Failed Payments/Usage Charges/Credits and
            // Refunds/Resellers pass, landed concurrently in this same
            // worktree.
            'Resources'.DIRECTORY_SEPARATOR.'PlatformInvoiceResource.php',
            'Resources'.DIRECTORY_SEPARATOR.'PlatformInvoiceResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ListPlatformInvoices.php',
            'Resources'.DIRECTORY_SEPARATOR.'PlatformInvoiceResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ViewPlatformInvoice.php',
            'Resources'.DIRECTORY_SEPARATOR.'PlatformInvoiceResource'.DIRECTORY_SEPARATOR.'RelationManagers'.DIRECTORY_SEPARATOR.'InvoiceLinesRelationManager.php',
            'Resources'.DIRECTORY_SEPARATOR.'FailedPaymentResource.php',
            'Resources'.DIRECTORY_SEPARATOR.'FailedPaymentResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ListFailedPayments.php',
            'Resources'.DIRECTORY_SEPARATOR.'FailedPaymentResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ViewFailedPayment.php',
            'Resources'.DIRECTORY_SEPARATOR.'PlatformRefundResource.php',
            'Resources'.DIRECTORY_SEPARATOR.'PlatformRefundResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ListPlatformRefunds.php',
            'Resources'.DIRECTORY_SEPARATOR.'PlatformRefundResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ViewPlatformRefund.php',
            'Pages'.DIRECTORY_SEPARATOR.'PlatformUsageChargesPage.php',
            'Pages'.DIRECTORY_SEPARATOR.'PlatformResellersPage.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'FinalizePlatformInvoiceAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'VoidPlatformInvoiceAction.php',
        ];

        // Phase 4 FirmsVault Admin Control Center ("Operations,
        // Governance, Support, and Configuration") — GOVERNANCE category
        // pass only (Audit Logs, Retention, Legal Holds, Data Exports,
        // Deletion Requests). Two sibling passes (Operations; Support +
        // Configuration) land concurrently in this same shared worktree
        // under their own separate scope — their files are added by
        // their own passes, not here. None of this pass's files
        // reference the Integration domain at all (confirmed: this
        // Governance category is its own separate domain), but they
        // still live outside app/Filament/Firm and so still need an
        // explicit allowlist entry against this file's broader "which
        // non-Firm files are allowed to exist" sweep.
        $phase4GovernanceAllowedRelativeFiles = [
            'Pages'.DIRECTORY_SEPARATOR.'PlatformRetentionGovernancePage.php',
            'Resources'.DIRECTORY_SEPARATOR.'AuditLogResource.php',
            'Resources'.DIRECTORY_SEPARATOR.'AuditLogResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ListAuditLogs.php',
            'Resources'.DIRECTORY_SEPARATOR.'AuditLogResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ViewAuditLog.php',
            'Resources'.DIRECTORY_SEPARATOR.'LegalHoldResource.php',
            'Resources'.DIRECTORY_SEPARATOR.'LegalHoldResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ListLegalHolds.php',
            'Resources'.DIRECTORY_SEPARATOR.'LegalHoldResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ViewLegalHold.php',
            'Resources'.DIRECTORY_SEPARATOR.'ExportJobResource.php',
            'Resources'.DIRECTORY_SEPARATOR.'ExportJobResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ListExportJobs.php',
            'Resources'.DIRECTORY_SEPARATOR.'ExportJobResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ViewExportJob.php',
            'Resources'.DIRECTORY_SEPARATOR.'OffboardingRequestResource.php',
            'Resources'.DIRECTORY_SEPARATOR.'OffboardingRequestResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ListOffboardingRequests.php',
            'Resources'.DIRECTORY_SEPARATOR.'OffboardingRequestResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ViewOffboardingRequest.php',
            'Resources'.DIRECTORY_SEPARATOR.'ImportBatchResource.php',
            'Resources'.DIRECTORY_SEPARATOR.'ImportBatchResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ListImportBatches.php',
            'Resources'.DIRECTORY_SEPARATOR.'ImportBatchResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ViewImportBatch.php',
            'Resources'.DIRECTORY_SEPARATOR.'MigrationProjectResource.php',
            'Resources'.DIRECTORY_SEPARATOR.'MigrationProjectResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ListMigrationProjects.php',
            'Resources'.DIRECTORY_SEPARATOR.'MigrationProjectResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ViewMigrationProject.php',
            'Resources'.DIRECTORY_SEPARATOR.'DeletionRequestResource.php',
            'Resources'.DIRECTORY_SEPARATOR.'DeletionRequestResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ListDeletionRequests.php',
            'Resources'.DIRECTORY_SEPARATOR.'DeletionRequestResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ViewDeletionRequest.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'PlaceLegalHoldAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'ReleaseLegalHoldAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'AdvanceOffboardingRequestAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'CompleteOffboardingRequestAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'CancelOffboardingRequestAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'MarkOffboardingExportVerifiedAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'SubmitDeletionRequestForApprovalAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'RequestDeletionApprovalAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'FirstApproveDeletionAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'SecondApproveDeletionAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'DenyDeletionAction.php',
        ];

        // Phase 4 FirmsVault Admin Control Center ("Operations,
        // Governance, Support, and Configuration") — SUPPORT +
        // CONFIGURATION categories pass (this pass): Support Cases,
        // Approved Support Sessions, Entitlement Overrides (relabeled
        // "Feature Flags"), AI Policy Settings (relabeled "Platform
        // Settings"), Notification Templates (relabeled "Email
        // Templates"). Two sibling passes (Operations; Governance) land
        // concurrently in this same shared worktree under their own
        // separate scope — their files are added by their own passes,
        // not here (see $phase4GovernanceAllowedRelativeFiles above for
        // one of them). None of this pass's Filament classes reference
        // the Integration domain directly (confirmed by grep for
        // `App\Integrations\`/`FirmIntegrationResource` — zero matches):
        // SupportCaseResource/SupportSessionResource route through the
        // NEW PlatformSupportAccessDirectoryService (App\Services, not
        // App\Filament), which itself calls
        // PlatformFirmIntegrationBoundedAccessService — the same
        // Checkpoint 11 chokepoint the existing single-firm support-
        // access actions already use — but that reference lives in the
        // service layer, not in either Resource file, so neither needs
        // an entry in the Integration-domain sweeps above (those sweeps
        // scan app/Filament/Resources|Pages|Widgets specifically, not
        // app/Services).
        $phase4SupportAndConfigurationAllowedRelativeFiles = [
            'Resources'.DIRECTORY_SEPARATOR.'SupportCaseResource.php',
            'Resources'.DIRECTORY_SEPARATOR.'SupportCaseResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ListSupportCases.php',
            'Resources'.DIRECTORY_SEPARATOR.'SupportCaseResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ViewSupportCase.php',
            'Resources'.DIRECTORY_SEPARATOR.'SupportSessionResource.php',
            'Resources'.DIRECTORY_SEPARATOR.'SupportSessionResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ListSupportSessions.php',
            'Resources'.DIRECTORY_SEPARATOR.'SupportSessionResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ViewSupportSession.php',
            'Resources'.DIRECTORY_SEPARATOR.'EntitlementOverrideResource.php',
            'Resources'.DIRECTORY_SEPARATOR.'EntitlementOverrideResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ListEntitlementOverrides.php',
            'Resources'.DIRECTORY_SEPARATOR.'EntitlementOverrideResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ViewEntitlementOverride.php',
            'Resources'.DIRECTORY_SEPARATOR.'AiPolicySettingResource.php',
            'Resources'.DIRECTORY_SEPARATOR.'AiPolicySettingResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ListAiPolicySettings.php',
            'Resources'.DIRECTORY_SEPARATOR.'AiPolicySettingResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ViewAiPolicySetting.php',
            'Resources'.DIRECTORY_SEPARATOR.'NotificationTemplateResource.php',
            'Resources'.DIRECTORY_SEPARATOR.'NotificationTemplateResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ListNotificationTemplates.php',
            'Resources'.DIRECTORY_SEPARATOR.'NotificationTemplateResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ViewNotificationTemplate.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'ExpireSupportCaseAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'RevokeApprovedSupportSessionAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'SetEntitlementOverrideAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'EditAiPolicySettingValueAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'CreateGlobalDefaultNotificationTemplateAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'CreateFirmOverrideNotificationTemplateAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'ArchiveNotificationTemplateAction.php',
        ];

        // Phase 4 FirmsVault Admin Control Center ("Operations,
        // Governance, Support, and Configuration") — OPERATIONS category
        // pass (this pass): Service Health, Queues & Jobs, Scheduler,
        // Deployments, Backups, Incidents/Status Page. Two sibling
        // passes (Governance; Support + Configuration) land concurrently
        // in this same shared worktree under their own separate scope —
        // see $phase4GovernanceAllowedRelativeFiles/
        // $phase4SupportAndConfigurationAllowedRelativeFiles above for
        // those. None of this pass's Filament classes reference the
        // Integration domain at all (confirmed by grep for
        // `App\Integrations\`/`FirmIntegrationResource` across every
        // file listed below — zero matches; this Operations category is
        // its own separate domain, over HealthCheck/QueueHealthService/
        // SchedulerHealthService/DeploymentConfig/FleetMigrationRun/
        // BackupRestoreTest/IncidentEvent/StatusPageEvent), but they
        // still live outside app/Filament/Firm and so still need an
        // explicit allowlist entry against this file's broader "which
        // non-Firm files are allowed to exist" sweep.
        $phase4OperationsAllowedRelativeFiles = [
            'Pages'.DIRECTORY_SEPARATOR.'PlatformServiceHealthPage.php',
            'Pages'.DIRECTORY_SEPARATOR.'PlatformQueuesAndJobsPage.php',
            'Pages'.DIRECTORY_SEPARATOR.'PlatformSchedulerPage.php',
            'Pages'.DIRECTORY_SEPARATOR.'PlatformBackupsPage.php',
            'Pages'.DIRECTORY_SEPARATOR.'PlatformStatusPageEventsPage.php',
            'Pages'.DIRECTORY_SEPARATOR.'PlatformDeploymentConfigsPage.php',
            'Pages'.DIRECTORY_SEPARATOR.'PlatformFleetMigrationRunDetailPage.php',
            'Resources'.DIRECTORY_SEPARATOR.'PlatformIncidentResource.php',
            'Resources'.DIRECTORY_SEPARATOR.'PlatformIncidentResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ListPlatformIncidents.php',
            'Resources'.DIRECTORY_SEPARATOR.'PlatformIncidentResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ViewPlatformIncident.php',
            'Resources'.DIRECTORY_SEPARATOR.'PlatformFleetMigrationRunResource.php',
            'Resources'.DIRECTORY_SEPARATOR.'PlatformFleetMigrationRunResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ListPlatformFleetMigrationRuns.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'RunHealthChecksNowAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'RetryFailedJobAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'DeleteFailedJobAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'OpenIncidentAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'UpdateIncidentSeverityAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'UpdateIncidentStatusAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'RecordIncidentRootCauseAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'FlagIncidentCustomerImpactAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'FlagIncidentNotificationNeededAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'ResolveIncidentAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'PublishStatusPageEventAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'UpdateStatusPageEventAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'ResolveStatusPageEventPubliclyAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'UnpublishStatusPageEventAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'CreateFleetMigrationRunAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'BeginFleetMigrationRunAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'RollbackFleetMigrationRunAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'CompleteFleetMigrationRunAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'ApplyFleetMigrationInstanceAction.php',
        ];

        // FirmsVault Live Integrations, Checkpoint 4 ("Plaid financial
        // evidence add-on") — another cascade, same reasoning again. Two
        // legitimately separate surfaces: (1) PlatformAdmin cross-firm
        // Plaid oversight (redacted/summary-only per this checkpoint's
        // own requirement — PlaidItemOversightResource, ProviderKillSwitchResource,
        // PlaidCostOversightPage, PlaidAnomalyOversightPage, all
        // confirmed gated behind Auth::guard('platform_admin') +
        // PlatformStaffAccessPolicyService), and (2) the brand-new
        // app/Filament/ClientPortal/** panel namespace this checkpoint
        // introduces (see ClientPortalPanelProvider) — its own Plaid
        // consent/connect flow pages, entirely separate from both the
        // `admin` and `firm` panels, so (like the `admin`-panel cascades
        // above) every file needs an explicit entry here since none of
        // it starts with `Firm/`.
        $checkpoint4PlaidAllowedRelativeFiles = [
            'Resources'.DIRECTORY_SEPARATOR.'PlaidItemOversightResource.php',
            'Resources'.DIRECTORY_SEPARATOR.'PlaidItemOversightResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ListPlaidItemOversight.php',
            'Resources'.DIRECTORY_SEPARATOR.'ProviderKillSwitchResource.php',
            'Resources'.DIRECTORY_SEPARATOR.'ProviderKillSwitchResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ListProviderKillSwitches.php',
            'Pages'.DIRECTORY_SEPARATOR.'PlaidCostOversightPage.php',
            'Pages'.DIRECTORY_SEPARATOR.'PlaidAnomalyOversightPage.php',
            'ClientPortal'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'PlaidConsentPage.php',
            'ClientPortal'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'PlaidAccountSelectionPage.php',
            'ClientPortal'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'PlaidDateRangeConfirmationPage.php',
            'ClientPortal'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'PlaidUploadFallbackPage.php',
            'ClientPortal'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'PlaidRequestReviewPage.php',
        ];

        // FirmsVault Billing Durability Redesign, Checkpoint 8.2
        // ("webhook-bootstrap retries + provider-operation
        // reconciliation") — see this file's own class docblock's
        // "POST-CHECKPOINT-8.2-BOOTSTRAP-RECONCILIATION UPDATE" note.
        $checkpoint82ProviderOperationReconciliationAllowedRelativeFiles = [
            'Pages'.DIRECTORY_SEPARATOR.'PlatformProviderOperationReconciliationPage.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'ConfirmProviderOperationSucceededAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'AuthorizeProviderOperationRetryAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'ResolveProviderOperationWithoutRetryAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'Concerns'.DIRECTORY_SEPARATOR.'AuditsProviderOperationReconciliation.php',
        ];

        // Platform Firm Provisioning workflow — a reviewed multi-step
        // wizard (ProvisionFirmAction) plus its owner-invitation recovery
        // action (ResendFirmOwnerInvitationAction), calling
        // FirmProvisioningService end-to-end. Neither references the
        // Integration domain, so no entry is needed in the
        // Integration-domain sweeps above — only here, since neither
        // lives under app/Filament/Firm.
        $platformFirmProvisioningAllowedRelativeFiles = [
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'ProvisionFirmAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'ResendFirmOwnerInvitationAction.php',
        ];

        // FirmsVault staging follow-up ("Application Completion —
        // Catalogs + Firm-Owned Reference Data") — PracticeAreaResource
        // (Platform Admin CRUD over the GLOBAL practice_areas/matter_types
        // catalog, "Practice Area → Matter Types" — see PracticeAreaResource's
        // own docblock). Does not reference the Integration domain, so
        // no entry is needed in the Integration-domain sweeps above —
        // only here, since it does not live under app/Filament/Firm.
        $practiceAreaCatalogAllowedRelativeFiles = [
            'Resources'.DIRECTORY_SEPARATOR.'PracticeAreaResource.php',
            'Resources'.DIRECTORY_SEPARATOR.'PracticeAreaResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ListPracticeAreas.php',
            'Resources'.DIRECTORY_SEPARATOR.'PracticeAreaResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ViewPracticeArea.php',
            'Resources'.DIRECTORY_SEPARATOR.'PracticeAreaResource'.DIRECTORY_SEPARATOR.'RelationManagers'.DIRECTORY_SEPARATOR.'MatterTypesRelationManager.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'CreatePracticeAreaAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'EditPracticeAreaAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'ActivatePracticeAreaAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'DeactivatePracticeAreaAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'CreateMatterTypeAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'EditMatterTypeAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'ActivateMatterTypeAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'DeactivateMatterTypeAction.php',
        ];

        // Mission 1B (Extreme Security Hardening) — WebAuthn/passkey
        // infrastructure for Platform Admin, the reusable step-up-
        // authentication helper (guard-agnostic, not Firm-panel-
        // specific — lives at app/Filament/Support, not under Firm),
        // and per-panel Login/RequestPasswordReset/ResetPassword
        // subclasses added to close a shared rate-limit-bucket finding
        // (see this mission's own commits). None of these reference
        // the Integration domain; the sweeps above independently
        // re-confirm that on every run.
        $mission1bExtremeSecurityHardeningAllowedRelativeFiles = [
            'Auth'.DIRECTORY_SEPARATOR.'Concerns'.DIRECTORY_SEPARATOR.'ThrottlesLoginsPerAccount.php',
            'Auth'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'PlatformAdminRequestPasswordReset.php',
            'Auth'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'PlatformAdminResetPassword.php',
            'MultiFactor'.DIRECTORY_SEPARATOR.'WebAuthn'.DIRECTORY_SEPARATOR.'WebAuthnAuthentication.php',
            'MultiFactor'.DIRECTORY_SEPARATOR.'WebAuthn'.DIRECTORY_SEPARATOR.'Actions'.DIRECTORY_SEPARATOR.'RegisterWebAuthnCredentialAction.php',
            'MultiFactor'.DIRECTORY_SEPARATOR.'WebAuthn'.DIRECTORY_SEPARATOR.'Actions'.DIRECTORY_SEPARATOR.'DisableWebAuthnCredentialAction.php',
            'Support'.DIRECTORY_SEPARATOR.'StepUp'.DIRECTORY_SEPARATOR.'StepUpAuthentication.php',
            'ClientPortal'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'Auth'.DIRECTORY_SEPARATOR.'Login.php',
            'ClientPortal'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'Auth'.DIRECTORY_SEPARATOR.'RequestPasswordReset.php',
            'ClientPortal'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'Auth'.DIRECTORY_SEPARATOR.'ResetPassword.php',
        ];

        // Mission 1C (Security Validation, Activation & Staging Proof)
        // — AuditedFirmUserAppAuthentication, the Firm-user MFA
        // audit-trail sibling to MultiFactor/AuditedAppAuthentication.php
        // above (Platform Admin's own). Deliberately lives here, not
        // under app/Filament/Firm, mirroring that exact same
        // established convention for this MultiFactor namespace. Does
        // not reference the Integration domain; the sweeps above
        // independently re-confirm that on every run.
        $mission1cSecurityValidationActivationAllowedRelativeFiles = [
            'MultiFactor'.DIRECTORY_SEPARATOR.'AuditedFirmUserAppAuthentication.php',
        ];

        // POST-MISSION-2-MYATTORNEY-MARKETPLACE-CORE-CHECKPOINT-11
        // UPDATE: an eleventh cascade, same reasoning again. Checkpoint
        // 11 of the MyAttorney Marketplace Core mission adds the first
        // Admin-panel SuperAdmin/PlatformAdmin oversight surface for the
        // marketplace domain (`directory_firms`/`directory_claims`/
        // `directory_correction_requests`/`directory_import_batches`) —
        // 4 Resources (each with List+View Pages) and 15 Action
        // classes. None of these reference the Integration domain at
        // all (confirmed: this is the Marketplace domain, an unrelated
        // concern) — the Integration-domain sweeps above run
        // unconditionally against every one of these files too and
        // would independently fail if any of them referenced it. They
        // still live outside app/Filament/Firm and so still need an
        // explicit allowlist entry against this file's broader "which
        // non-Firm files are allowed to exist" sweep.
        $mission2MarketplaceSuperAdminControlsAllowedRelativeFiles = [
            'Resources'.DIRECTORY_SEPARATOR.'DirectoryFirmResource.php',
            'Resources'.DIRECTORY_SEPARATOR.'DirectoryFirmResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ListDirectoryFirms.php',
            'Resources'.DIRECTORY_SEPARATOR.'DirectoryFirmResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ViewDirectoryFirm.php',
            'Resources'.DIRECTORY_SEPARATOR.'DirectoryClaimResource.php',
            'Resources'.DIRECTORY_SEPARATOR.'DirectoryClaimResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ListDirectoryClaims.php',
            'Resources'.DIRECTORY_SEPARATOR.'DirectoryClaimResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ViewDirectoryClaim.php',
            'Resources'.DIRECTORY_SEPARATOR.'DirectoryCorrectionRequestResource.php',
            'Resources'.DIRECTORY_SEPARATOR.'DirectoryCorrectionRequestResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ListDirectoryCorrectionRequests.php',
            'Resources'.DIRECTORY_SEPARATOR.'DirectoryCorrectionRequestResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ViewDirectoryCorrectionRequest.php',
            'Resources'.DIRECTORY_SEPARATOR.'DirectoryImportBatchResource.php',
            'Resources'.DIRECTORY_SEPARATOR.'DirectoryImportBatchResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ListDirectoryImportBatches.php',
            'Resources'.DIRECTORY_SEPARATOR.'DirectoryImportBatchResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ViewDirectoryImportBatch.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'PublishDirectoryFirmAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'SuspendDirectoryFirmAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'RemoveDirectoryFirmAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'ActivateMarketplaceMembershipAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'DeactivateMarketplaceMembershipAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'VerifyFirmAuthorityAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'RevokeFirmVerificationAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'ApproveDirectoryClaimAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'RejectDirectoryClaimAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'RevokeDirectoryClaimAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'ApproveCorrectionRequestAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'RejectCorrectionRequestAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'ResolveCorrectionRequestAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'ConfirmImportSourceRightsAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'ApplyImportBatchAction.php',
        ];

        // Mission 2 checkpoint 13 (privacy-conscious analytics
        // foundation) added a second, later Admin-panel page —
        // PlatformMarketplaceAnalyticsPage — missed by checkpoint 11's
        // own allowlist above (that array was frozen at checkpoint
        // 11's own file set). Read-only aggregate reporting, gated by
        // PlatformStaffAccessPolicyService::canViewMarketplaceAnalytics(),
        // no Integration-domain reference. Caught by this checkpoint's
        // own final full fresh-DB regression gate, not by any earlier
        // narrower sweep.
        $mission2MarketplaceAnalyticsAllowedRelativeFiles = [
            'Pages'.DIRECTORY_SEPARATOR.'PlatformMarketplaceAnalyticsPage.php',
        ];

        // Mission 3 (MyAttorney Conversion + AI Intake) checkpoint 14
        // (privacy/retention/analytics + SuperAdmin AI oversight) added
        // a third Admin-panel page — PlatformAiOversightPage — plus its
        // one mutating action, ToggleAiKillSwitchAction, gated by
        // PlatformStaffAccessPolicyService::canAccessAiPolicySettings()/
        // canManageAiPolicySettings(). Same shape as
        // PlatformMarketplaceAnalyticsPage above: read-only aggregate
        // MyAttorney intake-funnel counts + the platform AI kill-switch
        // toggle, no Integration-domain reference. Caught by this
        // mission's own final full fresh-DB regression gate, not by
        // any earlier narrower sweep.
        $mission3AiOversightAllowedRelativeFiles = [
            'Pages'.DIRECTORY_SEPARATOR.'PlatformAiOversightPage.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'ToggleAiKillSwitchAction.php',
        ];

        // MyAttorney SuperAdmin console professionalization mission
        // (MYAT1-6): upgraded the read-only marketplace governance
        // surface above into a real operational console — a new
        // Marketplace Overview page, the FIRST Create/Edit pages this
        // panel has ever had (DirectoryFirmResource, the new
        // DirectoryAttorneyResource), and ~11 new domain-service-backed
        // Actions for the attorney/claim/correction/import workflows.
        // All gated by the same PlatformStaffAccessPolicyService
        // marketplace-governance/analytics checks as the arrays above,
        // no Integration-domain reference. Missed by this file's own
        // regression gate during MYAT1-6 itself (only caught here, by
        // MYAT8's own affected-suite sweep) — a real, now-closed gap in
        // that earlier work, not a new violation.
        $myattorneySuperAdminConsoleUpgradeAllowedRelativeFiles = [
            'Pages'.DIRECTORY_SEPARATOR.'PlatformMarketplaceOverviewPage.php',
            'Resources'.DIRECTORY_SEPARATOR.'DirectoryFirmResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'CreateDirectoryFirm.php',
            'Resources'.DIRECTORY_SEPARATOR.'DirectoryFirmResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'EditDirectoryFirm.php',
            'Resources'.DIRECTORY_SEPARATOR.'DirectoryAttorneyResource.php',
            'Resources'.DIRECTORY_SEPARATOR.'DirectoryAttorneyResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'CreateDirectoryAttorney.php',
            'Resources'.DIRECTORY_SEPARATOR.'DirectoryAttorneyResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'EditDirectoryAttorney.php',
            'Resources'.DIRECTORY_SEPARATOR.'DirectoryAttorneyResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ListDirectoryAttorneys.php',
            'Resources'.DIRECTORY_SEPARATOR.'DirectoryAttorneyResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ViewDirectoryAttorney.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'PublishDirectoryAttorneyAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'UnpublishDirectoryAttorneyAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'ArchiveDirectoryAttorneyAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'VerifyDirectoryAttorneyAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'RevokeDirectoryAttorneyVerificationAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'AssociateDirectoryAttorneyWithFirmAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'MarkClaimUnderReviewAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'RequireClaimEvidenceAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'MarkCorrectionUnderReviewAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'CreateInternalCorrectionRequestAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'UploadDirectoryImportBatchAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'DownloadImportBatchErrorCsvAction.php',
            'Pages'.DIRECTORY_SEPARATOR.'PlatformMarketplaceSettingsPage.php',
        ];

        // Prompt 2 of the seven-mission parallel SuperAdmin upgrade
        // program ("Integration Operations control plane",
        // admin/integration-operations). Two Integration-owned
        // presentation/validation helpers plus the two governed
        // kill-switch Actions that replaced ProviderKillSwitchResource's
        // previous inline, unaudited create/toggle closures. Same
        // cascade-safety reasoning as every block above: the
        // Integration-domain sweeps earlier in this file run
        // unconditionally against these files too, and every one of them
        // is gated behind Auth::guard('platform_admin') +
        // PlatformStaffAccessPolicyService exactly like their siblings.
        $promptTwoIntegrationOperationsAllowedRelativeFiles = [
            'Support'.DIRECTORY_SEPARATOR.'Integrations'.DIRECTORY_SEPARATOR.'IntegrationDisplay.php',
            'Support'.DIRECTORY_SEPARATOR.'Integrations'.DIRECTORY_SEPARATOR.'ProviderKillSwitchScope.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'CreateProviderKillSwitchAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'ToggleProviderKillSwitchAction.php',
        ];

        // FINAL ADMIN RECONCILIATION (admin/final-reconciliation).
        // Prompts 3-7 of the seven-mission parallel SuperAdmin upgrade
        // program each added their own Filament surfaces, and each
        // mission's targeted suite passed in isolation because this
        // firewall test lives in the Integration domain and was not in
        // any of their affected-file sets. Combined, those surfaces
        // tripped this sweep for the first time — a genuine
        // reconciliation-only regression, closed here by naming every
        // file exactly rather than by broadening the sweep.
        //
        // The allowlist deliberately stays exact-file: no directory
        // wildcard, no app/Filament/* entry. An unauthorized new file
        // under app/Filament still fails this test.
        //
        // Every entry below carries the same cascade-safety property as
        // the blocks above. Each Page and Resource resolves
        // Auth::guard('platform_admin') and defers to
        // PlatformStaffAccessPolicyService. The Resource *Pages* and the
        // Action carry no inline guard by design, exactly like the
        // already-allowlisted FirmResource/Pages/ListFirms.php: a
        // ListRecords/ViewRecord page inherits its Resource's
        // canViewAny()/canView(), and an Action renders only inside an
        // already-gated page. None references the Integration domain.

        // Prompt 3 — Governance console (admin/governance-console).
        $promptThreeGovernanceAllowedRelativeFiles = [
            'Pages'.DIRECTORY_SEPARATOR.'PlatformGovernanceOverviewPage.php',
        ];

        // Prompt 4 — Billing & Commercial (admin/billing-commercial).
        // BillingAccountResource is gated by
        // canAccessPlatformBilling(); its two Pages inherit that gate.
        $promptFourBillingCommercialAllowedRelativeFiles = [
            'Pages'.DIRECTORY_SEPARATOR.'PlatformBillingCommercialOverviewPage.php',
            'Pages'.DIRECTORY_SEPARATOR.'PlatformInternalSalesCommissionsPage.php',
            'Resources'.DIRECTORY_SEPARATOR.'BillingAccountResource.php',
            'Resources'.DIRECTORY_SEPARATOR.'BillingAccountResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ListBillingAccounts.php',
            'Resources'.DIRECTORY_SEPARATOR.'BillingAccountResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ViewBillingAccount.php',
        ];

        // Prompt 5 — Configuration control plane
        // (admin/configuration-control-plane). ProposePracticeAreaMerge
        // only PROPOSES: it executes no taxonomy merge (see that
        // action's own docblock and its mission report).
        $promptFiveConfigurationAllowedRelativeFiles = [
            'Pages'.DIRECTORY_SEPARATOR.'PlatformConfigurationOverviewPage.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'RevokeEntitlementOverrideAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'RevertFirmNotificationTemplateOverrideAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'ProposePracticeAreaMergeAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'PreviewNotificationTemplateAction.php',
        ];

        // Prompt 6 — Zero-trust support access (admin/support-access).
        $promptSixSupportAccessAllowedRelativeFiles = [
            'Pages'.DIRECTORY_SEPARATOR.'PlatformSupportOverviewPage.php',
        ];

        // Prompt 7 — Operations control plane
        // (admin/operations-control-plane).
        $promptSevenOperationsAllowedRelativeFiles = [
            'Pages'.DIRECTORY_SEPARATOR.'PlatformOperationsOverviewPage.php',
        ];

        // Non-Payment Completion Program (reconciliation branch) — two
        // independent, non-Integration-domain additions (confirmed via
        // grep for `App\Integrations\`/`FirmIntegrationResource` across
        // every file listed below — zero matches), same cascade-safety
        // reasoning as every block above:
        //  - PlatformAutomationOversightPage.php: cross-tenant
        //    AutomationRule/AutomationActionExecution/dead-lettered
        //    domain-event oversight, read-only, no requeue/retry action.
        //  - The entire app/Filament/ClientPortal tree: a third,
        //    genuinely separate Filament panel (its own guard, its own
        //    tenant-context bootstrap via
        //    EstablishClientPortalTenantContext) — not a Firm-panel or
        //    Admin-panel surface at all, so it necessarily lives outside
        //    app/Filament/Firm exactly like Checkpoint 11's own
        //    admin-panel tree does above. Mission 4 (Client Portal
        //    Activation) plus its document-sharing/payment-plan-visibility
        //    follow-ups.
        $nonPaymentCompletionProgramAllowedRelativeFiles = [
            'Pages'.DIRECTORY_SEPARATOR.'PlatformAutomationOversightPage.php',
            'ClientPortal'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'Dashboard.php',
            'ClientPortal'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'Profile.php',
            'ClientPortal'.DIRECTORY_SEPARATOR.'Resources'.DIRECTORY_SEPARATOR.'MatterResource.php',
            'ClientPortal'.DIRECTORY_SEPARATOR.'Resources'.DIRECTORY_SEPARATOR.'MatterResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ListMatters.php',
            'ClientPortal'.DIRECTORY_SEPARATOR.'Resources'.DIRECTORY_SEPARATOR.'MatterResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ViewMatter.php',
            'ClientPortal'.DIRECTORY_SEPARATOR.'Resources'.DIRECTORY_SEPARATOR.'MatterResource'.DIRECTORY_SEPARATOR.'RelationManagers'.DIRECTORY_SEPARATOR.'DeadlinesRelationManager.php',
            'ClientPortal'.DIRECTORY_SEPARATOR.'Resources'.DIRECTORY_SEPARATOR.'DocumentResource.php',
            'ClientPortal'.DIRECTORY_SEPARATOR.'Resources'.DIRECTORY_SEPARATOR.'DocumentResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ListDocuments.php',
            'ClientPortal'.DIRECTORY_SEPARATOR.'Resources'.DIRECTORY_SEPARATOR.'DocumentResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ViewDocument.php',
            'ClientPortal'.DIRECTORY_SEPARATOR.'Resources'.DIRECTORY_SEPARATOR.'InvoiceResource.php',
            'ClientPortal'.DIRECTORY_SEPARATOR.'Resources'.DIRECTORY_SEPARATOR.'InvoiceResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ListInvoices.php',
            'ClientPortal'.DIRECTORY_SEPARATOR.'Resources'.DIRECTORY_SEPARATOR.'InvoiceResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ViewInvoice.php',
            'ClientPortal'.DIRECTORY_SEPARATOR.'Resources'.DIRECTORY_SEPARATOR.'PaymentPlanResource.php',
            'ClientPortal'.DIRECTORY_SEPARATOR.'Resources'.DIRECTORY_SEPARATOR.'PaymentPlanResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ListPaymentPlans.php',
            'ClientPortal'.DIRECTORY_SEPARATOR.'Resources'.DIRECTORY_SEPARATOR.'PaymentPlanResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ViewPaymentPlan.php',
            'ClientPortal'.DIRECTORY_SEPARATOR.'Resources'.DIRECTORY_SEPARATOR.'PaymentPlanResource'.DIRECTORY_SEPARATOR.'RelationManagers'.DIRECTORY_SEPARATOR.'InstallmentsRelationManager.php',
        ];

        $allowedRelativeFiles = array_merge($checkpoint11AllowedRelativeFiles, $phase1AdminControlCenterAllowedRelativeFiles, $mfaAndPlatformAdministratorAllowedRelativeFiles, $executiveDashboardAllowedRelativeFiles, $phase2IntegrationOperationsCenterAllowedRelativeFiles, $phase3BillingAndCommercialAdministrationAllowedRelativeFiles, $phase4GovernanceAllowedRelativeFiles, $phase4SupportAndConfigurationAllowedRelativeFiles, $phase4OperationsAllowedRelativeFiles, $checkpoint4PlaidAllowedRelativeFiles, $checkpoint82ProviderOperationReconciliationAllowedRelativeFiles, $platformFirmProvisioningAllowedRelativeFiles, $practiceAreaCatalogAllowedRelativeFiles, $mission1bExtremeSecurityHardeningAllowedRelativeFiles, $mission1cSecurityValidationActivationAllowedRelativeFiles, $mission2MarketplaceSuperAdminControlsAllowedRelativeFiles, $mission2MarketplaceAnalyticsAllowedRelativeFiles, $mission3AiOversightAllowedRelativeFiles, $myattorneySuperAdminConsoleUpgradeAllowedRelativeFiles, $promptTwoIntegrationOperationsAllowedRelativeFiles, $promptThreeGovernanceAllowedRelativeFiles, $promptFourBillingCommercialAllowedRelativeFiles, $promptFiveConfigurationAllowedRelativeFiles, $promptSixSupportAccessAllowedRelativeFiles, $promptSevenOperationsAllowedRelativeFiles, $nonPaymentCompletionProgramAllowedRelativeFiles);

        $unauthorizedNonFirmFilamentFiles = [];

        foreach ($this->phpFilesUnder($filamentDir) as $file) {
            $relative = str_replace($filamentDir.DIRECTORY_SEPARATOR, '', $file);

            if (str_starts_with($relative, 'Firm'.DIRECTORY_SEPARATOR)) {
                continue;
            }

            if (in_array($relative, $allowedRelativeFiles, true)) {
                continue;
            }

            $unauthorizedNonFirmFilamentFiles[] = $relative;
        }

        $this->assertEmpty(
            $unauthorizedNonFirmFilamentFiles,
            'Every file under app/Filament must live under app/Filament/Firm OR be on one of this file\'s own '.
            'explicit cascade allowlists — found unauthorized: '.implode(', ', $unauthorizedNonFirmFilamentFiles)
        );
    }

    /**
     * @param  string[]  $allowedBasenames  basenames legitimately exempt
     *                                      from this sweep (Checkpoint
     *                                      11's own frozen allowlist for
     *                                      the directory being checked)
     *                                      — matched and skipped
     *                                      entirely (both the basename
     *                                      shortcut AND the source-scan
     *                                      below), since these files
     *                                      legitimately reference the
     *                                      Integration domain by design.
     */
    private function assertNoIntegrationDomainReferenceUnder(string $dir, array $allowedBasenames = []): void
    {
        $violations = [];

        foreach ($this->phpFilesUnder($dir) as $file) {
            $basename = basename($file);

            if (in_array($basename, $allowedBasenames, true)) {
                continue;
            }

            if (str_contains($basename, 'Integration') || str_contains($basename, 'FirmIntegration')) {
                $violations[] = $file;

                continue;
            }

            $source = file_get_contents($file);

            if ($source !== false && (str_contains($source, 'App\\Integrations\\') || str_contains($source, 'FirmIntegrationResource'))) {
                $violations[] = $file;
            }
        }

        $this->assertEmpty($violations, "No unauthorized Integration-domain class/reference may exist under {$dir}: ".implode(', ', $violations));
    }

    // ------------------------------------------------------------
    // 2. Credential-DTO-only-binding structural check (frozen design
    //    §9) — mirrors IntegrationWebhookRoutingIndexNoRlsAndNoSecretColumnTest's
    //    str_contains() scan convention.
    // ------------------------------------------------------------

    public function test_no_file_under_app_filament_firm_contains_integration_credential_class_outside_the_one_allowed_dto_construction_site(): void
    {
        // The ONE allowed reference: ViewFirmIntegration.php's
        // credential_summary TextEntry closure, which uses
        // IntegrationCredential ONLY to query rows and immediately map
        // each one through IntegrationCredentialService::getMaskedMetadata()
        // -> FirmIntegrationCredentialSummary::fromMaskedMetadata() — never
        // binding the raw model to any Field/Column/Entry directly.
        $allowedFiles = [
            'ViewFirmIntegration.php',
        ];

        $violations = [];

        foreach ($this->phpFilesUnder(base_path('app/Filament/Firm')) as $file) {
            if (in_array(basename($file), $allowedFiles, true)) {
                continue;
            }

            $source = file_get_contents($file);

            if ($source !== false && str_contains($source, 'IntegrationCredential::class')) {
                $violations[] = $file;
            }
        }

        $this->assertEmpty(
            $violations,
            'IntegrationCredential::class must never appear under app/Filament/Firm outside ViewFirmIntegration.php\'s masked-metadata DTO construction: '.implode(', ', $violations)
        );
    }

    public function test_no_file_under_app_filament_firm_binds_an_integration_credential_query_result_to_a_field_column_or_entry_directly(): void
    {
        // Defense-in-depth beyond the class-reference scan above: even
        // in the one allowed file, IntegrationCredential must only ever
        // flow through ::query()/->get()/->map() into the masked-DTO
        // pipeline (getMaskedMetadata()/fromMaskedMetadata()) — never
        // handed directly to a Field/Column/Entry constructor or
        // returned bare from a ->state() closure.
        $viewFirmIntegrationPath = app_path('Filament/Firm/Resources/FirmIntegrationResource/Pages/ViewFirmIntegration.php');

        $this->assertFileExists($viewFirmIntegrationPath);

        $source = file_get_contents($viewFirmIntegrationPath);
        $this->assertIsString($source);

        // The masked-metadata pipeline is the ONLY legitimate use.
        $this->assertStringContainsString('getMaskedMetadata(', $source);
        $this->assertStringContainsString('FirmIntegrationCredentialSummary::fromMaskedMetadata(', $source);

        // No naive direct binding: an IntegrationCredential instance
        // handed straight to make()'s own argument list, e.g.
        // `TextEntry::make($credential)`.
        $this->assertDoesNotMatchRegularExpression(
            '/(TextEntry|TextColumn)::make\(\s*\$credential/',
            $source,
            'No Field/Column/Entry may bind directly to an IntegrationCredential instance.'
        );

        // Every ->map() over the IntegrationCredential query result must
        // route through fromMaskedMetadata() on the same line/statement
        // — confirms the query result is never returned/mapped bare.
        $this->assertMatchesRegularExpression(
            '/->map\(fn \(IntegrationCredential \$credential\) => FirmIntegrationCredentialSummary::fromMaskedMetadata\(/',
            $source,
            'The IntegrationCredential query result must be mapped through FirmIntegrationCredentialSummary::fromMaskedMetadata() on the same map() call.'
        );
    }

    public function test_no_file_under_app_filament_firm_ever_references_the_encrypted_payload_ciphertext_or_webhook_routing_token_columns(): void
    {
        $violations = [];

        foreach ($this->phpFilesUnder(base_path('app/Filament/Firm')) as $file) {
            $source = file_get_contents($file);

            if ($source === false) {
                continue;
            }

            if (str_contains($source, 'encrypted_payload_ciphertext')) {
                $violations[] = "{$file} (encrypted_payload_ciphertext)";
            }

            // webhook_routing_token: the ONE narrow, disclosed exception is
            // ViewFirmIntegration.php's own docblock discussion and its
            // display of enableWebhookRouting()'s plaintext RETURN VALUE
            // (never a re-read of the model attribute) — grep the literal
            // attribute-access shape `->webhook_routing_token` specifically,
            // never merely the substring, so the docblock prose itself
            // (which legitimately discusses the column by name) does not
            // trip this check.
            if (preg_match('/->webhook_routing_token\b/', $source)) {
                $violations[] = "{$file} (->webhook_routing_token direct attribute access)";
            }
        }

        $this->assertEmpty($violations, 'No file under app/Filament/Firm may read these HIDDEN-ONLY/NEVER columns directly: '.implode(', ', $violations));
    }

    /**
     * @return string[]
     */
    private function phpFilesUnder(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $result = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $fileInfo) {
            if ($fileInfo->getExtension() === 'php') {
                $result[] = $fileInfo->getPathname();
            }
        }

        return $result;
    }
}
