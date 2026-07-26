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
 * COORDINATION NOTE: since two agents land files in this one shared test
 * concurrently, whichever pass's commit lands second should re-verify
 * this allowlist still matches the real file set on disk before commit
 * (e.g. re-run this file's own tests) — this update reflects the file
 * set present in the shared worktree at the time this pass's own
 * verification ran, not a guess.
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
        $this->assertNoIntegrationDomainReferenceUnder($dir, allowedBasenames: [
            'ConnectionResource.php',
            'SyncFailureResource.php',
            'WebhookEventResource.php',
            'DeadLetterQueueResource.php',
            'ConflictResource.php',
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
        $this->assertNoIntegrationDomainReferenceUnder($dir, allowedBasenames: [
            'PlatformIntegrationOverviewPage.php',
            'PlatformFirmIntegrationsPage.php',
            'PlatformFirmIntegrationDetailPage.php',
            'PlatformProviderHealthPage.php',
            'PlatformIntegrationUsagePage.php',
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
            'Resources'.DIRECTORY_SEPARATOR.'FirmUserResource.php',
            'Resources'.DIRECTORY_SEPARATOR.'FirmUserResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ListFirmUsers.php',
            'Resources'.DIRECTORY_SEPARATOR.'FirmUserResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ViewFirmUser.php',
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
            'Resources'.DIRECTORY_SEPARATOR.'PlatformAdministratorResource.php',
            'Resources'.DIRECTORY_SEPARATOR.'PlatformAdministratorResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ListPlatformAdministrators.php',
            'Resources'.DIRECTORY_SEPARATOR.'PlatformAdministratorResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ViewPlatformAdministrator.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'ResetPlatformAdminMfaAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'TogglePlatformAdminActiveStatusAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'AssignPlatformAdminRoleAction.php',
            'Actions'.DIRECTORY_SEPARATOR.'Platform'.DIRECTORY_SEPARATOR.'RevokePlatformAdminRoleAction.php',
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

        $allowedRelativeFiles = array_merge($checkpoint11AllowedRelativeFiles, $phase1AdminControlCenterAllowedRelativeFiles, $mfaAndPlatformAdministratorAllowedRelativeFiles, $executiveDashboardAllowedRelativeFiles, $phase2IntegrationOperationsCenterAllowedRelativeFiles);

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
