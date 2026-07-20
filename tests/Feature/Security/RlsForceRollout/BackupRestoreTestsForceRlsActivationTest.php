<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Models\BackupRestoreTest;
use App\Models\Firm;
use App\Services\ComplianceGapRegistryService;
use App\Services\RowLevelSecurityCoverageMappingService;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * BackupRestoreTestsForceRlsActivationTest — Section 39A-3L, Checkpoint
 * 27 (Phase B6). Proves the forty-fifth staged FORCE ROW LEVEL SECURITY
 * activation batch (database/migrations/2026_08_25_930027_force_rls_on_
 * backup_restore_tests_table.php) is permanently active for
 * backup_restore_tests and behaves correctly — including the genuinely
 * new policy shape this checkpoint introduces (the first table in this
 * mission where firm_id is legitimately, routinely NULL in normal
 * operation): every previously-forced table remains forced
 * simultaneously; missing-context read/insert denial; a firm-specific
 * row remains strictly single-firm-visible; a platform-wide
 * (firm_id = NULL) row is visible under EVERY firm-scoped session's
 * context, positively proving the read-side "every tenant sees every
 * platform-wide row" design decision; the asymmetric WITH CHECK closes
 * both the INSERT-side forgery gap (a firm-scoped session forging a
 * fake firm_id = NULL row) AND — the central, previously-uncaught
 * finding of this checkpoint's design review (Design Reviewer 2,
 * tenant-context-auditor) — the mirror-image DELETE-side gap (a
 * firm-scoped session deleting a real platform-wide row that every
 * other firm still needs to see), which a naive single-policy design
 * reusing one USING clause for both SELECT and DELETE would have left
 * open, since WITH CHECK is never consulted for DELETE in PostgreSQL.
 *
 * Full design rationale and the exact approved SQL:
 * rls-checkpoints/39a3l/B6-backup_restore_tests-design-dossier.md
 * (APPROVED by both rls-policy-designer and tenant-context-auditor).
 *
 * Unlike contacts/parties, backup_restore_tests required real
 * application-code prerequisites ahead of this FORCE migration —
 * TenantContextService::runWithoutFirmContext() (the deliberate inverse
 * of runWithFirmContext(), proving no tenant context is active at the
 * database-session level before a platform-wide write),
 * BackupRestoreTestService::runDrill()/latestFor() wrapping, and
 * BackupRestoreTestFactory's context-hold create() override with an
 * explicit null-firm_id branch — all committed independently ahead of
 * this migration, per the dossier's own note that the preparation and
 * the FORCE activation are split into two commits here, matching the
 * contacts/parties (Checkpoints 25/26) precedent.
 */
class BackupRestoreTestsForceRlsActivationTest extends TestCase
{
    // Narrowly updated by Section 39A-5 Wave 11 (webhooks domain, the final wave of the 60-table rollout, covering webhook_deliveries, webhook_delivery_attempts, webhook_events, webhook_secrets, webhook_subscriptions) for the same reason — additive only, no existing assertion removed or weakened. Total prepared/forced count is now 113.
    // Narrowly updated by Stage B Checkpoint 3 of the FirmsBase
    // Integration Platform mission (firm_integrations, a new genuine
    // tenant-owned table with RLS prepared and FORCE-activated in the
    // same migration, 2026_09_02_020002_prepare_row_level_security_and_force_rls_on_firm_integrations_table.php)
    // for the same reason — additive only, no existing assertion
    // removed or weakened. Total prepared/forced count is now 114.
    use RefreshDatabase;

    private const PREVIOUSLY_FORCED_TABLES = [
        'clients', 'firm_users', 'documents', 'deadlines', 'tasks', 'matters', 'invoices', 'payments',
        'conflict_check_runs', 'lead_sources', 'consultation_outcomes', 'firm_leads', 'consultations',
        'firm_practice_areas', 'document_chase_rules', 'employee_rates', 'calendar_events',
        'client_communication_preferences', 'payment_classification_events', 'activation_checklists',
        'firm_activation_events', 'firm_entitlements', 'firm_entitlement_events', 'installed_template_packs',
        'template_upgrade_logs', 'template_upgrade_previews', 'seat_allocations', 'document_requests',
        'communication_consents', 'communication_consent_events', 'intake_submissions',
        'matter_readiness_scores', 'readiness_score_events', 'tenant_encryption_keys', 'document_chase_events',
        'firm_settings', 'firm_licenses', 'time_tracking_sessions', 'time_entries', 'payment_plans',
        'payment_plan_events', 'notification_events', 'contacts', 'parties',
    ];

    private function tenantContext(): TenantContextService
    {
        return new TenantContextService();
    }

    private function insertRow(?int $firmId, string $suffix): int
    {
        return DB::table('backup_restore_tests')->insertGetId([
            'firm_id' => $firmId,
            'status' => 'passed',
            'components_verified_json' => json_encode(['database_records']),
            'rpo_target_seconds' => 86400,
            'rto_target_seconds' => 28800,
            'rpo_actual_seconds' => 3600,
            'rto_actual_seconds' => 7200,
            'started_at' => now(),
            'completed_at' => now(),
            'notes' => 'RLS proof row '.$suffix,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ---------------------------------------------------------------
    // FORCE state / policy / cumulative-coverage proofs
    // ---------------------------------------------------------------

    public function test_every_previously_forced_table_remains_force_row_level_security_enabled(): void
    {
        foreach (self::PREVIOUSLY_FORCED_TABLES as $table) {
            $row = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);

            $this->assertNotNull($row, "Table {$table} not found in pg_class.");
            $this->assertTrue(
                (bool) $row->relforcerowsecurity,
                "{$table} must remain FORCE RLS enabled after this batch."
            );
        }
    }

    public function test_backup_restore_tests_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'backup_restore_tests'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_backup_restore_tests_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'backup_restore_tests'");

        $this->assertNotNull($row);
        $this->assertTrue(
            (bool) $row->relforcerowsecurity,
            'backup_restore_tests must have permanent FORCE ROW LEVEL SECURITY active — this is not a transient, per-test setting.'
        );
    }

    /**
     * Exactly forty-five tables (the forty-four previously forced plus
     * backup_restore_tests) must be FORCE-enabled among ALL prepared
     * tables — no more, no less.
     */
    public function test_exactly_forty_five_prepared_tables_are_force_row_level_security_enabled(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService();

        // Narrowly updated by Section 39A-3L, Checkpoint 28 (health_checks) for the same reason — additive only, no existing assertion removed or weakened.
        $expectedForced = array_merge(self::PREVIOUSLY_FORCED_TABLES, ['ai_retrieval_indexes', 'deployment_configs', 'firm_ai_settings', 'email_visibility_rules', 'private_enterprise_settings', 'matter_expenses', 'email_message_links', 'ai_usage_events', 'ai_tool_actions', 'firm_ai_provider_keys', 'ai_approval_requests', 'ai_approval_events', 'chart_of_accounts', 'expense_categories', 'expenses', 'expense_receipts', 'expense_approvals', 'accounting_export_batches', 'accounting_export_lines', 'email_accounts', 'email_messages', 'email_attachments', 'email_sync_events', 'generated_documents', 'form_drafts', 'generated_document_events', 'form_review_events', 'document_hashes', 'pdf_view_events', 'customer_success_health_scores', 'backup_restore_tests', 'health_checks', 'incident_events', 'maintenance_windows', 'notification_templates', 'pilot_feedback_items', 'timeline_events', 'security_events', 'signature_certificates', 'signature_events', 'signature_request_recipients', 'signature_requests', 'legal_holds', 'deletion_requests', 'key_destruction_requests', 'support_access_requests', 'support_access_sessions', 'deployment_health_checks', 'export_jobs', 'migration_projects', 'import_batches', 'implementation_projects', 'fleet_migration_instance_status', 'offboarding_requests', 'trust_accounts', 'trust_ledgers', 'trust_balances', 'matter_trust_balances', 'trust_ledger_entries', 'trust_approval_events', 'trust_chargeback_events', 'trust_reconciliations', 'trust_refund_requests', 'trust_transfer_requests', 'webhook_deliveries', 'webhook_delivery_attempts', 'webhook_events', 'webhook_secrets', 'webhook_subscriptions', 'firm_integrations']);

        $actuallyForced = [];

        foreach ($coverage->preparedTables() as $table) {
            $row = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);

            $this->assertNotNull($row, "Table {$table} not found in pg_class.");

            if ((bool) $row->relforcerowsecurity) {
                $actuallyForced[] = $table;
            }
        }

        sort($expectedForced);
        sort($actuallyForced);

        $this->assertSame(114, count($actuallyForced), 'Exactly forty-five prepared tables must be FORCE RLS enabled after Section 39A-3L, Checkpoint 27 — no more, no less.');
        $this->assertSame($expectedForced, $actuallyForced);
    }

    /**
     * Uncovered/uninvolved tables must be untouched by this batch.
     */
    public function test_no_unrelated_prepared_table_became_force_enabled(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService();
        // Narrowly updated by Section 39A-3L, Checkpoint 28 (health_checks) for the same reason — additive only, no existing assertion removed or weakened.
        $forced = array_merge(self::PREVIOUSLY_FORCED_TABLES, ['ai_retrieval_indexes', 'deployment_configs', 'firm_ai_settings', 'email_visibility_rules', 'private_enterprise_settings', 'matter_expenses', 'email_message_links', 'ai_usage_events', 'ai_tool_actions', 'firm_ai_provider_keys', 'ai_approval_requests', 'ai_approval_events', 'chart_of_accounts', 'expense_categories', 'expenses', 'expense_receipts', 'expense_approvals', 'accounting_export_batches', 'accounting_export_lines', 'email_accounts', 'email_messages', 'email_attachments', 'email_sync_events', 'generated_documents', 'form_drafts', 'generated_document_events', 'form_review_events', 'document_hashes', 'pdf_view_events', 'customer_success_health_scores', 'backup_restore_tests', 'health_checks', 'incident_events', 'maintenance_windows', 'notification_templates', 'pilot_feedback_items', 'timeline_events', 'security_events', 'signature_certificates', 'signature_events', 'signature_request_recipients', 'signature_requests', 'legal_holds', 'deletion_requests', 'key_destruction_requests', 'support_access_requests', 'support_access_sessions', 'deployment_health_checks', 'export_jobs', 'migration_projects', 'import_batches', 'implementation_projects', 'fleet_migration_instance_status', 'offboarding_requests', 'trust_accounts', 'trust_ledgers', 'trust_balances', 'matter_trust_balances', 'trust_ledger_entries', 'trust_approval_events', 'trust_chargeback_events', 'trust_reconciliations', 'trust_refund_requests', 'trust_transfer_requests', 'webhook_deliveries', 'webhook_delivery_attempts', 'webhook_events', 'webhook_secrets', 'webhook_subscriptions', 'firm_integrations']);

        // Section 39A-3L, Phase B6, Checkpoint 34 (security_events) is
        // the final checkpoint in this arc: $forced now equals the FULL
        // preparedTables() set exactly, so the per-table loop below
        // legitimately has zero remaining iterations (a real, positive
        // end state, not a lost assertion). This explicit equality
        // check keeps the test genuinely assertive regardless of loop
        // iteration count.
        $forcedSorted = $forced;
        sort($forcedSorted);
        $preparedTablesSorted = $coverage->preparedTables();
        sort($preparedTablesSorted);
        $this->assertSame($forcedSorted, $preparedTablesSorted, 'Every originally "prepared" table must now be force-enabled, no more, no fewer.');

        foreach ($coverage->preparedTables() as $table) {
            if (in_array($table, $forced, true)) {
                continue;
            }

            $row = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);
            $this->assertNotNull($row, "Table {$table} not found in pg_class.");
            $this->assertFalse((bool) $row->relforcerowsecurity, "{$table} must not have accidentally become FORCE RLS enabled.");
        }
    }

    /**
     * This migration REPLACES the original single-expression policy
     * with two new policies — unlike every prior FORCE-only checkpoint,
     * where the pre-existing policy was left completely untouched.
     */
    public function test_the_original_single_policy_no_longer_exists(): void
    {
        $policy = DB::selectOne(
            "select polname from pg_policy where polrelid = 'backup_restore_tests'::regclass and polname = 'backup_restore_tests_tenant_isolation'"
        );

        $this->assertNull($policy, 'The original single-expression policy must have been dropped and replaced by the two new policies.');
    }

    public function test_the_read_and_write_policies_exist_with_the_expected_shape(): void
    {
        $readPolicy = DB::selectOne(
            "select polname, polcmd, pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy
             where polrelid = 'backup_restore_tests'::regclass and polname = 'backup_restore_tests_tenant_read'"
        );

        $this->assertNotNull($readPolicy, 'backup_restore_tests_tenant_read must exist.');
        $this->assertSame('r', $readPolicy->polcmd, 'the read policy must be FOR SELECT only.');
        $this->assertStringContainsString('firm_id IS NULL', $readPolicy->using_expr);
        $this->assertNull($readPolicy->with_check_expr, 'a FOR SELECT policy has no WITH CHECK.');

        $writePolicy = DB::selectOne(
            "select polname, pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy
             where polrelid = 'backup_restore_tests'::regclass and polname = 'backup_restore_tests_tenant_write'"
        );

        $this->assertNotNull($writePolicy, 'backup_restore_tests_tenant_write must exist.');
        $this->assertNotNull($writePolicy->with_check_expr, 'the write policy must carry an explicit, asymmetric WITH CHECK — not the FOR ALL implicit reuse of USING.');
        $this->assertStringContainsString('firm_id IS NULL', $writePolicy->using_expr);
        $this->assertStringContainsString('firm_id IS NULL', $writePolicy->with_check_expr);
    }

    /**
     * No other table's policy was modified by this migration — spot
     * check clients' own policy (the very first table forced in this
     * arc) and parties' own policy (the immediately prior checkpoint)
     * as representative unrelated policies.
     */
    public function test_no_other_tables_policy_was_changed(): void
    {
        $clientsPolicy = DB::selectOne("select polname from pg_policy where polrelid = 'clients'::regclass");
        $this->assertNotNull($clientsPolicy);
        $this->assertSame('clients_tenant_isolation', $clientsPolicy->polname);

        $partiesPolicy = DB::selectOne("select polname from pg_policy where polrelid = 'parties'::regclass");
        $this->assertNotNull($partiesPolicy);
        $this->assertSame('parties_tenant_isolation', $partiesPolicy->polname);
    }

    // ---------------------------------------------------------------
    // Missing-context fail-closed proofs
    // ---------------------------------------------------------------

    public function test_missing_tenant_context_cannot_read_a_firm_specific_row(): void
    {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext($firm, fn () => $this->insertRow($firm->id, 'firm-specific'));

        $this->tenantContext()->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $this->assertSame(0, BackupRestoreTest::query()->where('firm_id', $firm->id)->count());
    }

    public function test_missing_tenant_context_cannot_insert_a_firm_specific_row(): void
    {
        $firm = Firm::factory()->create();

        $this->tenantContext()->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->insertRow($firm->id, 'no-context-insert');
    }

    // ---------------------------------------------------------------
    // Same-firm access / cross-firm isolation proofs — firm-specific
    // rows remain strictly single-firm-visible, unchanged from the
    // original policy's own intent.
    // ---------------------------------------------------------------

    public function test_firm_a_context_can_read_its_own_firm_specific_row(): void
    {
        $firmA = Firm::factory()->create();
        $rowId = $this->runWithFirmContext($firmA, fn () => $this->insertRow($firmA->id, 'firm-a-own'));

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => BackupRestoreTest::query()->where('firm_id', $firmA->id)->pluck('id')->all(),
        );

        $this->assertSame([$rowId], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_specific_row(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $rowB = $this->runWithFirmContext($firmB, fn () => $this->insertRow($firmB->id, 'firm-b-only'));

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => BackupRestoreTest::query()->pluck('id')->all(),
        );

        $this->assertNotContains($rowB, $visibleIds);
    }

    public function test_valid_insert_with_correct_firm_context_succeeds(): void
    {
        $firm = Firm::factory()->create();

        $insertedId = $this->runWithFirmContext($firm, fn () => $this->insertRow($firm->id, 'valid-insert'));

        $this->assertIsInt($insertedId);
    }

    public function test_firm_a_context_cannot_insert_a_row_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, fn () => $this->insertRow($firmB->id, 'claimed-ownership'));
    }

    public function test_firm_a_context_cannot_update_firm_b_row(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $rowB = $this->runWithFirmContext($firmB, fn () => $this->insertRow($firmB->id, 'update-target'));

        $affected = $this->runWithFirmContext($firmA, function () use ($rowB) {
            return DB::table('backup_restore_tests')->where('id', $rowB)->update(['notes' => 'Hijacked']);
        });

        $this->assertSame(0, $affected, 'No rows should be visible/updatable — Firm A must not be able to update Firm B\'s backup_restore_tests row.');

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => BackupRestoreTest::query()->find($rowB),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertStringStartsWith('RLS proof row update-target', $reReadAsFirmB->notes);
    }

    public function test_firm_a_context_cannot_reassign_firm_b_row_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $rowB = $this->runWithFirmContext($firmB, fn () => $this->insertRow($firmB->id, 'reassign-target'));

        $affected = $this->runWithFirmContext($firmA, function () use ($firmA, $rowB) {
            return DB::table('backup_restore_tests')->where('id', $rowB)->update(['firm_id' => $firmA->id]);
        });

        $this->assertSame(0, $affected, 'No rows should be visible/updatable — Firm A must not be able to reassign Firm B\'s backup_restore_tests row to itself.');

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => BackupRestoreTest::query()->find($rowB),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame($firmB->id, $reReadAsFirmB->firm_id);
    }

    public function test_firm_a_context_cannot_delete_firm_b_specific_row(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $rowB = $this->runWithFirmContext($firmB, fn () => $this->insertRow($firmB->id, 'delete-target'));

        $this->runWithFirmContext($firmA, function () use ($rowB) {
            DB::table('backup_restore_tests')->where('id', $rowB)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => BackupRestoreTest::query()->find($rowB),
        );

        $this->assertNotNull($reReadAsFirmB, 'Firm A context must not be able to delete Firm B\'s backup_restore_tests row.');
    }

    // ---------------------------------------------------------------
    // Platform-wide (firm_id = NULL) row visibility proofs — the
    // central, positive read-side design decision this checkpoint
    // proves: every tenant may see every platform-wide row.
    // ---------------------------------------------------------------

    /**
     * A platform-wide row, created via runWithoutFirmContext() (the
     * real production write path — see BackupRestoreTestService::
     * runDrill($runner, firm: null)), must be visible under EVERY
     * firm-scoped session's own context — not merely under no context
     * at all. This is the positive proof of the read-side design
     * decision, not an assumption.
     */
    public function test_a_platform_wide_row_is_visible_under_every_firm_scoped_sessions_context(): void
    {
        $platformWideId = $this->tenantContext()->runWithoutFirmContext(fn () => $this->insertRow(null, 'platform-wide'));

        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $visibleToA = $this->runWithFirmContext($firmA, fn () => BackupRestoreTest::query()->whereNull('firm_id')->pluck('id')->all());
        $visibleToB = $this->runWithFirmContext($firmB, fn () => BackupRestoreTest::query()->whereNull('firm_id')->pluck('id')->all());

        $this->assertContains($platformWideId, $visibleToA, 'Firm A must see the platform-wide row.');
        $this->assertContains($platformWideId, $visibleToB, 'Firm B must also independently see the same platform-wide row.');
    }

    /**
     * A firm-specific row must NOT leak into a platform-wide read —
     * the "every tenant sees every platform-wide row" rule only
     * widens visibility for firm_id = NULL rows, it does not widen
     * visibility of one firm's own rows to another firm.
     */
    public function test_a_platform_wide_row_does_not_expose_sibling_firm_specific_rows(): void
    {
        $this->tenantContext()->runWithoutFirmContext(fn () => $this->insertRow(null, 'platform-wide-isolation-check'));

        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $rowB = $this->runWithFirmContext($firmB, fn () => $this->insertRow($firmB->id, 'firm-b-not-leaked'));

        $visibleToA = $this->runWithFirmContext($firmA, fn () => BackupRestoreTest::query()->pluck('id')->all());

        $this->assertNotContains($rowB, $visibleToA, 'Firm A must still not see Firm B\'s firm-specific row, even though a platform-wide row is visible to both.');
    }

    // ---------------------------------------------------------------
    // WITH CHECK asymmetry proofs — INSERT-side forgery prevention.
    // ---------------------------------------------------------------

    /**
     * The actual bug this checkpoint's design closes: a firm-scoped
     * session must NOT be able to forge a firm_id = NULL "platform-
     * wide" row — if it could, that forged row would become visible
     * to every OTHER firm too, an authorization-widening bug.
     */
    public function test_a_firm_scoped_session_cannot_insert_a_forged_platform_wide_row(): void
    {
        $firm = Firm::factory()->create();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firm, fn () => $this->insertRow(null, 'forged-platform-wide'));
    }

    /**
     * A genuinely context-free session (no tenant context active at
     * all — the real production shape of runWithoutFirmContext()) CAN
     * insert a firm_id = NULL row. This is the legitimate, intended
     * counterpart to the forgery-prevention test above — the write
     * policy's "no context active" branch is not merely a denial
     * rule, it is how platform-wide rows are meant to be written.
     */
    public function test_a_genuinely_context_free_session_can_insert_a_platform_wide_row(): void
    {
        $this->tenantContext()->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $insertedId = $this->insertRow(null, 'legitimate-platform-wide');

        $this->assertIsInt($insertedId);
    }

    // ---------------------------------------------------------------
    // WITH CHECK/USING asymmetry proofs — DELETE-side gap closure.
    // This is the most important new proof in this checkpoint: Design
    // Reviewer 2 (tenant-context-auditor) found that a naive single-
    // policy design (one USING clause reused for SELECT and for
    // DELETE/UPDATE-old-row checks) would let ANY firm-scoped session
    // delete every platform-wide row, because WITH CHECK is never
    // consulted for DELETE in PostgreSQL — an asymmetric WITH CHECK
    // alone (closing INSERT-side forgery) does nothing for this
    // mirror-image case. The two-policy split (a FOR SELECT-only read
    // policy plus a FOR ALL write policy — "FOR INSERT, UPDATE, DELETE"
    // is not valid PostgreSQL CREATE POLICY syntax, a bug this test
    // file's own first verification pass caught in the originally
    // staged migration; FOR ALL carries the identical asymmetric
    // condition on BOTH USING and WITH CHECK and is behaviorally
    // equivalent here, since the write policy's condition is always a
    // subset of the read policy's wider condition) closes this.
    // ---------------------------------------------------------------

    /**
     * A firm-scoped session must NOT be able to delete a real
     * platform-wide row — even though that row is fully readable to
     * it (per the universal read-visibility design decision above).
     * This is the specific DELETE-side gap Design Reviewer 2 found and
     * the write policy's own USING clause (not just WITH CHECK) now
     * closes.
     */
    public function test_a_firm_scoped_session_cannot_delete_a_platform_wide_row(): void
    {
        $platformWideId = $this->tenantContext()->runWithoutFirmContext(fn () => $this->insertRow(null, 'delete-gap-target'));

        $firm = Firm::factory()->create();

        $affected = $this->runWithFirmContext($firm, function () use ($platformWideId) {
            return DB::table('backup_restore_tests')->where('id', $platformWideId)->delete();
        });

        $this->assertSame(0, $affected, 'A firm-scoped session must not be able to delete a platform-wide (firm_id = NULL) row — this is the DELETE-side gap Design Reviewer 2 found and the two-policy split closes.');

        $stillExists = $this->tenantContext()->runWithoutFirmContext(
            fn () => BackupRestoreTest::query()->whereNull('firm_id')->find($platformWideId),
        );

        $this->assertNotNull($stillExists, 'The platform-wide row must genuinely still exist in the database after the blocked delete attempt.');
    }

    /**
     * A firm-scoped session must also not be able to delete its own
     * firm's row while pretending no filter is applied to a
     * platform-wide row's id — a second, more direct proof that the
     * write policy's own USING clause (not merely WITH CHECK) is what
     * blocks the DELETE, by attempting a delete scoped only by
     * firm_id IS NULL directly (matching the exact exploit shape
     * Design Reviewer 2 described in the dossier).
     */
    public function test_a_firm_scoped_session_cannot_delete_all_platform_wide_rows_via_a_direct_is_null_filter(): void
    {
        $this->tenantContext()->runWithoutFirmContext(fn () => $this->insertRow(null, 'is-null-exploit-shape-1'));
        $this->tenantContext()->runWithoutFirmContext(fn () => $this->insertRow(null, 'is-null-exploit-shape-2'));

        $firm = Firm::factory()->create();

        $affected = $this->runWithFirmContext($firm, function () {
            return DB::table('backup_restore_tests')->whereNull('firm_id')->delete();
        });

        $this->assertSame(0, $affected, 'DELETE FROM backup_restore_tests WHERE firm_id IS NULL must affect zero rows under a firm-scoped session — the exact exploit shape Design Reviewer 2 described.');

        $remaining = $this->tenantContext()->runWithoutFirmContext(
            fn () => BackupRestoreTest::query()->whereNull('firm_id')->count(),
        );

        $this->assertSame(2, $remaining, 'Both platform-wide rows must genuinely still exist.');
    }

    /**
     * A genuinely context-free session (the real production shape a
     * platform/admin-scoped connection would have) CAN delete a
     * platform-wide row — the write policy's "no context active"
     * branch governs DELETE symmetrically with INSERT/UPDATE, not
     * merely INSERT.
     */
    public function test_a_genuinely_context_free_session_can_delete_a_platform_wide_row(): void
    {
        $this->tenantContext()->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $platformWideId = $this->insertRow(null, 'context-free-delete-target');

        $affected = DB::table('backup_restore_tests')->where('id', $platformWideId)->delete();

        $this->assertSame(1, $affected, 'A genuinely context-free session must be able to delete a platform-wide row it is also able to write.');
    }

    // ---------------------------------------------------------------
    // Factory correctness proofs
    // ---------------------------------------------------------------

    /**
     * Bare factory default: a bare BackupRestoreTest::factory()->
     * create() must succeed even though its own definition() defaults
     * firm_id to null — the factory's context-hold create() override
     * has an explicit null-firm_id branch (NOT a byte-identical copy
     * of ClientFactory/ContactFactory, which would TypeError calling
     * setDatabaseTenantContextForFirmId(null)). The resulting row must
     * be immediately readable under every firm's own context, per the
     * same universal read-visibility rule proven above.
     */
    public function test_bare_factory_default_creation_is_safe_and_immediately_readable_under_any_firm(): void
    {
        $row = BackupRestoreTest::factory()->create();

        $this->assertNotNull($row->id);
        $this->assertNull($row->firm_id);

        $firm = Firm::factory()->create();
        $persisted = $this->runWithFirmContext($firm, fn () => BackupRestoreTest::query()->find($row->id));

        $this->assertNotNull($persisted, 'A bare factory-created platform-wide row must be visible under any firm\'s own context.');
        $this->assertNull($persisted->firm_id);
    }

    /**
     * Explicit firm-scoped factory state — creating with a real
     * firm_id genuinely persists under, and is readable only under,
     * exactly that firm's own context (not visible to a sibling firm).
     */
    public function test_explicit_firm_id_factory_state_is_internally_consistent_and_firm_scoped(): void
    {
        $firm = Firm::factory()->create();
        $otherFirm = Firm::factory()->create();

        $row = BackupRestoreTest::factory()->create(['firm_id' => $firm->id]);

        $this->assertSame($firm->id, $row->firm_id);

        $persisted = $this->runWithFirmContext($firm, fn () => BackupRestoreTest::query()->find($row->id));
        $this->assertNotNull($persisted);
        $this->assertSame($firm->id, $persisted->firm_id);

        $notVisibleToOther = $this->runWithFirmContext($otherFirm, fn () => BackupRestoreTest::query()->where('firm_id', $firm->id)->find($row->id));
        $this->assertNull($notVisibleToOther, 'A firm-scoped factory row must not be visible under a sibling firm\'s own context.');
    }

    /**
     * Design Reviewer 1's own requested regression proof: a bare
     * (null-firm_id) factory create() must succeed even AFTER a prior
     * ClientFactory/ContactFactory-style call already left a
     * non-null app.current_firm_id DB-level session setting active in
     * the same test — the null-firm_id branch must actively CLEAR
     * that stale setting via clearDatabaseTenantContext(), not merely
     * assume absence of context.
     */
    public function test_bare_factory_create_succeeds_after_a_prior_client_factory_call_in_the_same_test(): void
    {
        $firm = Firm::factory()->create();

        // ClientFactory deliberately leaves app.current_firm_id set
        // (DB-level) after it runs — this is the stale-context
        // scenario the dossier's Design Reviewer 1 fix specifically
        // guards against.
        \App\Models\Client::factory()->forFirm($firm)->create();
        $this->assertDatabaseTenantContextIs($firm, 'ClientFactory must have left a stale, non-null DB-level context active.');

        $row = BackupRestoreTest::factory()->create();

        $this->assertNull($row->firm_id, 'The bare factory create() must still succeed and produce a genuinely null-firm_id row, despite the stale ambient context.');
    }

    // ---------------------------------------------------------------
    // Residual gap scope note — unlike contacts (client_id) or other
    // tables in this arc with a second, independently-scoped tenant
    // foreign key, backup_restore_tests has no OTHER tenant-owned
    // relation at all: firm_id is both its only foreign key into
    // tenant-owned data AND the exact column RLS itself governs (the
    // "IS NULL or matches current context" shape), so there is no
    // second, independently-resolved relation whose firm could
    // plausibly mismatch this row's own firm_id — no transitive
    // cross-firm foreign-key surface exists here to prove a gap
    // against. created_by is a nullable FK to users, and users itself
    // carries no firm_id at all (firm membership is resolved via the
    // firm_users pivot, not a column on users), so "a created_by user
    // from a different firm" is not a coherent concept for this table
    // — asserting a "residual gap" proof against it would misrepresent
    // what RLS does and does not check here. This is stated plainly
    // rather than fabricating a test against a gap that does not
    // exist on this specific table, matching parties' own equivalent
    // scope note (PartiesForceRlsActivationTest's docblock: "parties
    // has no nullable/other tenant foreign key of its own at all").
    // ---------------------------------------------------------------

    // ---------------------------------------------------------------
    // Context lifecycle proofs
    // ---------------------------------------------------------------

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();

        $this->runWithFirmContext($firm, fn () => $this->insertRow($firm->id, 'context-clears-success'));

        $this->assertNoDatabaseTenantContext();
    }

    public function test_tenant_context_clears_after_exception(): void
    {
        $firm = Firm::factory()->create();

        try {
            $this->runWithFirmContext($firm, function () {
                throw new \RuntimeException('simulated failure inside firm context');
            });
        } catch (\RuntimeException $e) {
            // expected
        }

        $this->assertNoDatabaseTenantContext();
    }

    /**
     * runWithoutFirmContext() itself must clear database tenant
     * context after a successful callback.
     */
    public function test_run_without_firm_context_clears_database_context_after_success(): void
    {
        $this->tenantContext()->runWithoutFirmContext(fn () => $this->insertRow(null, 'without-context-success'));

        $this->assertNoDatabaseTenantContext();
    }

    /**
     * runWithoutFirmContext() must clear database tenant context even
     * when its callback throws.
     */
    public function test_run_without_firm_context_clears_database_context_after_exception(): void
    {
        try {
            $this->tenantContext()->runWithoutFirmContext(function () {
                throw new \RuntimeException('simulated failure inside runWithoutFirmContext');
            });
        } catch (\RuntimeException $e) {
            // expected
        }

        $this->assertNoDatabaseTenantContext();
    }

    /**
     * runWithoutFirmContext(), when nested inside an outer
     * runWithFirmContext($firmA, ...) call, must restore firm A's own
     * DB-level context afterward — not merely PHP-memory context —
     * per Design Reviewer 1's own traced SET LOCAL/SAVEPOINT finding.
     * Proven concretely: after the nested call returns, a real
     * RLS-protected read against firm A's own row (via a raw DB
     * query, not Eloquent, so no PHP-memory global scope could mask a
     * DB-level context bug) must still succeed.
     */
    public function test_run_without_firm_context_restores_the_outer_firms_db_level_context_when_nested(): void
    {
        $firmA = Firm::factory()->create();
        $rowA = $this->runWithFirmContext($firmA, fn () => $this->insertRow($firmA->id, 'nested-outer-firm-a'));

        $resultAfterNestedCall = $this->runWithFirmContext($firmA, function () use ($rowA) {
            $this->tenantContext()->runWithoutFirmContext(fn () => $this->insertRow(null, 'nested-platform-wide'));

            // Immediately after the nested runWithoutFirmContext()
            // call returns, firm A's own DB-level context must be
            // restored — proven via a raw query against firm A's own
            // row, which only succeeds if app.current_firm_id is
            // genuinely still set to firm A's id at the DB level.
            return DB::table('backup_restore_tests')->where('id', $rowA)->first();
        });

        $this->assertNotNull($resultAfterNestedCall, 'firm A\'s own DB-level tenant context must be restored after a nested runWithoutFirmContext() call returns.');
        $this->assertSame($firmA->id, $resultAfterNestedCall->firm_id);
    }

    // ---------------------------------------------------------------
    // Real production writer/reader proofs — BackupRestoreTestService
    // ---------------------------------------------------------------

    public function test_backup_restore_test_service_run_drill_with_no_firm_persists_a_genuinely_visible_platform_wide_row(): void
    {
        $service = new \App\Services\BackupRestoreTestService();
        $runner = new \App\Services\BackupRestore\FakeBackupRestoreDrillRunner();

        $test = $service->runDrill($runner);

        $this->assertNull($test->firm_id);

        $firm = Firm::factory()->create();
        $visible = $this->runWithFirmContext($firm, fn () => BackupRestoreTest::query()->find($test->id));

        $this->assertNotNull($visible, 'runDrill() with no firm must genuinely persist a row visible under any firm\'s context.');
    }

    public function test_backup_restore_test_service_run_drill_with_a_firm_persists_a_firm_scoped_row(): void
    {
        $firm = Firm::factory()->create();
        $service = new \App\Services\BackupRestoreTestService();
        $runner = new \App\Services\BackupRestore\FakeBackupRestoreDrillRunner();

        $test = $service->runDrill($runner, $firm);

        $this->assertSame($firm->id, $test->firm_id);

        $visible = $this->runWithFirmContext($firm, fn () => BackupRestoreTest::query()->find($test->id));
        $this->assertNotNull($visible);
    }

    public function test_backup_restore_test_service_latest_for_null_genuinely_reads_the_platform_wide_row(): void
    {
        $service = new \App\Services\BackupRestoreTestService();
        $runner = new \App\Services\BackupRestore\FakeBackupRestoreDrillRunner();

        $first = $service->runDrill($runner);
        $second = $service->runDrill($runner);

        $latest = $service->latestFor(null);

        $this->assertNotNull($latest);
        $this->assertTrue($latest->is($second));
    }

    // ---------------------------------------------------------------
    // Gap registry / simultaneous-isolation proofs
    // ---------------------------------------------------------------

    public function test_compliance_gap_registry_service_still_tracks_the_rls_gap(): void
    {
        $registry = new ComplianceGapRegistryService();

        $this->assertTrue($registry->isTracked('rls_prepared_not_enforced'));
    }

    public function test_compliance_gap_registry_service_was_not_modified(): void
    {
        $changed = trim((string) shell_exec(
            'git -C '.escapeshellarg(base_path()).' ls-files --modified --others --exclude-standard -- '.escapeshellarg('app/Services/ComplianceGapRegistryService.php')
        ));

        $this->assertSame('', $changed, 'ComplianceGapRegistryService.php must remain untouched by this checkpoint.');
    }

    /**
     * No UI/route/domain/deployment/payment/storage/AI/client-portal/
     * marketplace surface was added by this checkpoint — an
     * application-code-prerequisite-plus-migration-plus-test change
     * only, matching the contacts/parties precedent's own scope.
     */
    public function test_no_ui_routes_or_controllers_were_introduced_by_this_checkpoint(): void
    {
        foreach (['routes', 'app/Http/Controllers', 'app/Filament', 'resources/views', 'app/Livewire', 'app/Services/Payments', 'app/Services/Storage', 'app/Services/Ai', 'app/Http/Controllers/ClientPortal', 'app/Services/Marketplace'] as $relativeDir) {
            $changed = trim((string) shell_exec(
                'git -C '.escapeshellarg(base_path()).' ls-files --modified --others --exclude-standard -- '.escapeshellarg($relativeDir)
            ));

            $this->assertSame('', $changed, "Section 39A-3L, Checkpoint 27 must introduce no UI/route/domain surface, but found changes under {$relativeDir}.");
        }
    }

    /**
     * Forty-four previously forced tables plus backup_restore_tests
     * must be independently force-active and independently isolated
     * at the same time — proof this batch did not weaken or interfere
     * with any prior section's own enforcement. Uses parties as the
     * companion table (forced immediately prior, at Checkpoint 26).
     */
    public function test_backup_restore_tests_are_isolated_independently_and_simultaneously_with_parties(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $rowA = $this->runWithFirmContext($firmA, fn () => $this->insertRow($firmA->id, 'simultaneous-a'));
        $rowB = $this->runWithFirmContext($firmB, fn () => $this->insertRow($firmB->id, 'simultaneous-b'));

        $partyA = $this->runWithFirmContext($firmA, fn () => \App\Models\Party::factory()->create(['firm_id' => $firmA->id]));
        $partyB = $this->runWithFirmContext($firmB, fn () => \App\Models\Party::factory()->create(['firm_id' => $firmB->id]));

        $resultA = $this->runWithFirmContext($firmA, fn () => [
            'backup_restore_tests' => BackupRestoreTest::query()->where('firm_id', $firmA->id)->pluck('id')->all(),
            'parties' => \App\Models\Party::query()->pluck('id')->all(),
        ]);

        $this->assertSame([$rowA], $resultA['backup_restore_tests']);
        $this->assertNotContains($rowB, $resultA['backup_restore_tests']);
        $this->assertSame([$partyA->id], $resultA['parties']);
        $this->assertNotContains($partyB->id, $resultA['parties']);
    }

    // ---------------------------------------------------------------
    // Migration down()/up() restoration proofs
    // ---------------------------------------------------------------

    /**
     * Rollback support: the backup_restore_tests migration's down()
     * must genuinely restore the Section 39A baseline — RLS still
     * enabled, but NOT forced, and the ORIGINAL single-expression
     * policy restored byte-for-byte (both new policies dropped) — a
     * deviation from every prior FORCE-only migration's down(), which
     * only ever toggled FORCE, required here because this checkpoint's
     * up() replaces the policy shape itself, not just the FORCE flag.
     * Also proves rollback affects ONLY this one table — every other
     * previously-forced table must be untouched. up() is re-run in a
     * finally block so this test leaves the schema in the same state
     * it found it in.
     */
    public function test_backup_restore_tests_migration_down_restores_the_original_single_policy_and_affects_only_this_table(): void
    {
        $migration = require base_path('database/migrations/2026_08_25_930027_force_rls_on_backup_restore_tests_table.php');

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'backup_restore_tests'");

            $this->assertTrue((bool) $row->relrowsecurity, 'Rollback must not disable RLS itself.');
            $this->assertFalse((bool) $row->relforcerowsecurity, 'Rollback must clear FORCE.');

            foreach (self::PREVIOUSLY_FORCED_TABLES as $table) {
                $otherRow = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);

                $this->assertTrue(
                    (bool) $otherRow->relforcerowsecurity,
                    "{$table} must remain FORCE RLS enabled even while backup_restore_tests is rolled back."
                );
            }

            $readPolicy = DB::selectOne("select polname from pg_policy where polrelid = 'backup_restore_tests'::regclass and polname = 'backup_restore_tests_tenant_read'");
            $writePolicy = DB::selectOne("select polname from pg_policy where polrelid = 'backup_restore_tests'::regclass and polname = 'backup_restore_tests_tenant_write'");
            $this->assertNull($readPolicy, 'Rollback must drop the new read policy.');
            $this->assertNull($writePolicy, 'Rollback must drop the new write policy.');

            $originalPolicy = DB::selectOne(
                "select polname, pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
                 from pg_policy
                 where polrelid = 'backup_restore_tests'::regclass and polname = 'backup_restore_tests_tenant_isolation'"
            );
            $this->assertNotNull($originalPolicy, 'Rollback must restore the original single-expression policy.');
            $this->assertStringContainsString('current_setting', $originalPolicy->using_expr);
            $this->assertStringContainsString('firm_id', $originalPolicy->using_expr);
            $this->assertStringNotContainsString('IS NULL', $originalPolicy->using_expr, 'The restored original policy must be byte-for-byte the Phase 5 preparation text — it never had an IS NULL branch.');
            $this->assertNull($originalPolicy->with_check_expr, 'The restored original policy never had a separate WITH CHECK clause.');
        } finally {
            $migration->up();
        }

        $rowAfterUp = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'backup_restore_tests'");
        $this->assertTrue((bool) $rowAfterUp->relforcerowsecurity, 'up() must be restored in the finally block.');

        $writePolicyAfterUp = DB::selectOne("select polname from pg_policy where polrelid = 'backup_restore_tests'::regclass and polname = 'backup_restore_tests_tenant_write'");
        $this->assertNotNull($writePolicyAfterUp, 'up() must recreate the write policy.');
    }
}
