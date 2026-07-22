<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Services\ComplianceGapRegistryService;
use App\Services\RowLevelSecurityCoverageMappingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * RlsForceRolloutFirewallTest — Section 39A-3B. Proves this staged
 * activation batch stayed inside its declared boundary: FORCE ROW
 * LEVEL SECURITY was activated for firm_users only in THIS branch
 * (clients was already forced by Section 39A-3A and stays that way,
 * but this branch did not touch it) — not the other 50 prepared
 * tables, not the 43 still-uncovered tenant-owned tables — no new RLS
 * policy was added, no UI/routes/controllers were introduced, and
 * ComplianceGapRegistryService was not deleted/rewritten.
 *
 * Narrowly updated by Section 39A-3J (this repo's thirteenth staged
 * FORCE activation batch, covering lead_sources,
 * consultation_outcomes, firm_leads, and consultations together) to
 * extend the "exactly these tables are forced" firewall list and add
 * this batch's own four migration-existence checks — following the
 * exact same pattern every prior 39A-3C..39A-3I section already used
 * here, not a restructure of this test's own original scope/assertions.
 *
 * Narrowly updated AGAIN by Section 39A-3K (this repo's fourteenth
 * through eighteenth staged FORCE activation batch, covering
 * firm_practice_areas, document_chase_rules, employee_rates,
 * calendar_events, and client_communication_preferences together) to
 * extend the "exactly these tables are forced" firewall list from
 * thirteen to eighteen tables and add this batch's own five
 * migration-existence checks — same additive-only pattern, no existing
 * assertion removed or weakened.
 *
 * Narrowly updated AGAIN by Section 39A-3L, Checkpoint 1, Table Phase C
 * (this repo's nineteenth staged FORCE activation batch, covering
 * payment_classification_events) to extend the "exactly these tables
 * are forced" firewall list from eighteen to nineteen tables and add
 * this batch's own migration-existence check — same additive-only
 * pattern, no existing assertion removed or weakened.
 *
 * Narrowly updated AGAIN by Section 39A-3L, Checkpoint 2, Table Phase C
 * (this repo's twentieth staged FORCE activation batch, covering
 * activation_checklists) to extend the "exactly these tables are
 * forced" firewall list from nineteen to twenty tables and add this
 * batch's own migration-existence check — same additive-only pattern,
 * no existing assertion removed or weakened.
 *
 * Narrowly updated AGAIN by Section 39A-3L, Checkpoint 3, Table Phase C
 * (this repo's twenty-first staged FORCE activation batch, covering
 * firm_activation_events) to extend the "exactly these tables are
 * forced" firewall list from twenty to twenty-one tables and add this
 * batch's own migration-existence check — same additive-only pattern,
 * no existing assertion removed or weakened.
 *
 * Narrowly updated AGAIN by Section 39A-3L, Checkpoint 4, Table Phase C
 * (this repo's twenty-second staged FORCE activation batch, covering
 * firm_entitlements) to extend the "exactly these tables are forced"
 * firewall list from twenty-one to twenty-two tables and add this
 * batch's own migration-existence check — same additive-only pattern,
 * no existing assertion removed or weakened.
 *
 * Narrowly updated AGAIN by Section 39A-3L, Checkpoint 5, Table Phase C
 * (this repo's twenty-third staged FORCE activation batch, covering
 * firm_entitlement_events) to extend the "exactly these tables are
 * forced" firewall list from twenty-two to twenty-three tables and add
 * this batch's own migration-existence check — same additive-only
 * pattern, no existing assertion removed or weakened.
 *
 * Narrowly updated AGAIN by Section 39A-3L, Checkpoint 6, Table Phase C
 * (this repo's twenty-fourth staged FORCE activation batch, covering
 * installed_template_packs) to extend the "exactly these tables are
 * forced" firewall list from twenty-three to twenty-four tables and add
 * this batch's own migration-existence check — same additive-only
 * pattern, no existing assertion removed or weakened.
 *
 * Narrowly updated AGAIN by Section 39A-3L, Checkpoint 7, Table Phase C
 * (this repo's twenty-fifth staged FORCE activation batch, covering
 * template_upgrade_logs) to extend the "exactly these tables are
 * forced" firewall list from twenty-four to twenty-five tables and add
 * this batch's own migration-existence check — same additive-only
 * pattern, no existing assertion removed or weakened.
 *
 * Narrowly updated AGAIN by Section 39A-3L, Checkpoint 8, Table Phase C
 * (this repo's twenty-sixth staged FORCE activation batch, covering
 * template_upgrade_previews) to extend the "exactly these tables are
 * forced" firewall list from twenty-five to twenty-six tables and add
 * this batch's own migration-existence check — same additive-only
 * pattern, no existing assertion removed or weakened.
 *
 * Narrowly updated AGAIN by Section 39A-3L, Checkpoint 9, Table Phase C
 * (this repo's twenty-seventh staged FORCE activation batch, covering
 * seat_allocations) to extend the "exactly these tables are forced"
 * firewall list from twenty-six to twenty-seven tables and add this
 * batch's own migration-existence check — same additive-only pattern,
 * no existing assertion removed or weakened.
 *
 * Narrowly updated AGAIN by Section 39A-3L, Checkpoint 10, Table Phase C
 * (this repo's twenty-eighth staged FORCE activation batch, covering
 * document_requests) to extend the "exactly these tables are forced"
 * firewall list from twenty-seven to twenty-eight tables and add this
 * batch's own migration-existence check — same additive-only pattern,
 * no existing assertion removed or weakened.
 *
 * Narrowly updated AGAIN by Section 39A-3L, Checkpoint 11, Table Phase C
 * (this repo's twenty-ninth staged FORCE activation batch, covering
 * communication_consents) to extend the "exactly these tables are
 * forced" firewall list from twenty-eight to twenty-nine tables and add
 * this batch's own migration-existence check — same additive-only
 * pattern, no existing assertion removed or weakened.
 *
 * Narrowly updated AGAIN by Section 39A-3L, Checkpoint 12, Table Phase C
 * (this repo's thirtieth staged FORCE activation batch, covering
 * communication_consent_events) to extend the "exactly these tables are
 * forced" firewall list from twenty-nine to thirty tables and add this
 * batch's own migration-existence check — same additive-only pattern,
 * no existing assertion removed or weakened.
 *
 * Narrowly updated AGAIN by Section 39A-3L, Checkpoint 13, Table Phase C
 * (this repo's thirty-first staged FORCE activation batch, covering
 * intake_submissions) to extend the "exactly these tables are forced"
 * firewall list from thirty to thirty-one tables and add this batch's
 * own migration-existence check — same additive-only pattern, no
 * existing assertion removed or weakened.
 *
 * Narrowly updated AGAIN by Section 39A-3L, Checkpoint 14, Table
 * Phase C (this repo's thirty-second staged FORCE activation batch,
 * covering matter_readiness_scores) to extend the "exactly these
 * tables are forced" firewall list from thirty-one to thirty-two
 * tables and add this batch's own migration-existence check — same
 * additive-only pattern, no existing assertion removed or weakened.
 *
 * Narrowly updated AGAIN by Section 39A-3L, Checkpoint 15, Table
 * Phase C (this repo's thirty-third staged FORCE activation batch,
 * covering readiness_score_events) to extend the "exactly these
 * tables are forced" firewall list from thirty-two to thirty-three
 * tables and add this batch's own migration-existence check — same
 * additive-only pattern, no existing assertion removed or weakened.
 *
 * Narrowly updated AGAIN by Section 39A-3L, Checkpoint 16, Table
 * Phase C (this repo's thirty-fourth staged FORCE activation batch,
 * covering tenant_encryption_keys) to extend the "exactly these
 * tables are forced" firewall list from thirty-three to thirty-four
 * tables and add this batch's own migration-existence check — same
 * additive-only pattern, no existing assertion removed or weakened.
 *
 * Narrowly updated AGAIN by Section 39A-3L, Checkpoint 19, Table
 * Phase C (this repo's thirty-seventh staged FORCE activation batch,
 * covering firm_licenses) to extend the "exactly these tables are
 * forced" list from thirty-six to thirty-seven tables and add this
 * batch's own migration-existence check — same additive-only pattern,
 * no existing assertion removed or weakened.
 *
 * Narrowly updated AGAIN by Section 39A-3L, Checkpoint 20, Table
 * Phase C (this repo's thirty-eighth staged FORCE activation batch,
 * covering time_tracking_sessions) to extend the "exactly these tables
 * are forced" list from thirty-seven to thirty-eight tables and add
 * this batch's own migration-existence check — same additive-only
 * pattern, no existing assertion removed or weakened.
 *
 * Narrowly updated AGAIN by Section 39A-3L, Checkpoint 21, Table
 * Phase C (this repo's thirty-ninth staged FORCE activation batch,
 * covering time_entries) to extend the "exactly these tables are
 * forced" list from thirty-eight to thirty-nine tables and add this
 * batch's own migration-existence check — same additive-only pattern,
 * no existing assertion removed or weakened.
 *
 * Narrowly updated AGAIN by Section 39A-3L, Checkpoint 22, Table
 * Phase C (this repo's fortieth staged FORCE activation batch,
 * covering payment_plans) to extend the "exactly these tables are
 * forced" list from thirty-nine to forty tables and add this batch's
 * own migration-existence check — same additive-only pattern, no
 * existing assertion removed or weakened.
 *
 * Narrowly updated AGAIN by Section 39A-3L, Checkpoint 23, Table
 * Phase C (this repo's forty-first staged FORCE activation batch,
 * covering payment_plan_events) to extend the "exactly these tables
 * are forced" list from forty to forty-one tables and add this
 * batch's own migration-existence check — same additive-only pattern,
 * no existing assertion removed or weakened.
 *
 * Narrowly updated AGAIN by Section 39A-3L, Checkpoint 24 (this
 * repo's forty-second staged FORCE activation batch, covering
 * notification_events) to extend the "exactly these tables are
 * forced" list from forty-one to forty-two tables and add this
 * batch's own migration-existence check — same additive-only pattern,
 * no existing assertion removed or weakened.
 *
 * Narrowly updated AGAIN by Section 39A-3L, Checkpoint 25 (this
 * repo's forty-third staged FORCE activation batch, covering
 * contacts) to extend the "exactly these tables are forced" list
 * from forty-two to forty-three tables and add this batch's own
 * migration-existence check — same additive-only pattern, no existing
 * assertion removed or weakened. parties, contacts' sibling table
 * under the same prerequisite remediation (Section 39A-3L Phase B5),
 * remained untouched/unforced by that checkpoint — it was addressed
 * by a separate checkpoint (Checkpoint 26).
 *
 * Narrowly updated AGAIN by Section 39A-3L, Checkpoint 26 (this
 * repo's forty-fourth staged FORCE activation batch, covering
 * parties, database/migrations/2026_08_25_930026_force_rls_on_parties_
 * table.php) to extend the "exactly these tables are forced" list
 * from forty-three to forty-four tables and add this batch's own
 * migration-existence check — same additive-only pattern, no existing
 * assertion removed or weakened.
 *
 * Narrowly updated AGAIN by Section 39A-3L, Checkpoint 27 (this
 * repo's forty-fifth staged FORCE activation batch, covering
 * backup_restore_tests, database/migrations/2026_08_25_930027_
 * force_rls_on_backup_restore_tests_table.php) to extend the "exactly
 * these tables are forced" list from forty-four to forty-five tables
 * and add this batch's own migration-existence check — same
 * additive-only pattern, no existing assertion removed or weakened.
 *
 * Narrowly updated AGAIN by Section 39A-3L, Checkpoint 28 (this
 * repo's forty-sixth staged FORCE activation batch, covering
 * health_checks, database/migrations/2026_08_25_930028_force_rls_on_
 * health_checks_table.php) to extend the "exactly these tables are
 * forced" list from forty-five to forty-six tables and add this
 * batch's own migration-existence check — same additive-only pattern,
 * no existing assertion removed or weakened.
 *
 * Narrowly updated AGAIN by Section 39A-3L, Checkpoint 29 (this
 * repo's forty-seventh staged FORCE activation batch, covering
 * incident_events, database/migrations/2026_08_25_930029_force_rls_on_
 * incident_events_table.php) to extend the "exactly these tables are
 * forced" list from forty-six to forty-seven tables and add this
 * batch's own migration-existence check — same additive-only pattern,
 * no existing assertion removed or weakened.
 *
 * Narrowly updated AGAIN by Section 39A-3L, Checkpoint 30 (this
 * repo's forty-eighth staged FORCE activation batch, covering
 * maintenance_windows, database/migrations/2026_08_25_930030_force_
 * rls_on_maintenance_windows_table.php) to extend the "exactly these
 * tables are forced" list from forty-seven to forty-eight tables and
 * add this batch's own migration-existence check — same additive-only
 * pattern, no existing assertion removed or weakened.
 *
 * Narrowly updated AGAIN by Section 39A-3L, Checkpoint 31, Phase B6
 * (this repo's forty-ninth staged FORCE activation batch, covering
 * notification_templates, database/migrations/2026_08_25_930031_
 * force_rls_on_notification_templates_table.php) to extend the
 * "exactly these tables are forced" list from forty-eight to
 * forty-nine tables and add this batch's own migration-existence
 * check — same additive-only pattern, no existing assertion removed
 * or weakened.
 *
 * Narrowly updated AGAIN by Section 39A-3L, Checkpoint 32, Phase B6
 * (this repo's fiftieth staged FORCE activation batch, covering
 * pilot_feedback_items, database/migrations/2026_08_25_930032_
 * force_rls_on_pilot_feedback_items_table.php) to extend the
 * "exactly these tables are forced" list from forty-nine to fifty
 * tables and add this batch's own migration-existence check — same
 * additive-only pattern, no existing assertion removed or weakened.
 *
 * Narrowly updated AGAIN by Section 39A-3L, Checkpoint 33, Phase B6
 * (this repo's fifty-first staged FORCE activation batch, covering
 * timeline_events, database/migrations/2026_08_25_930033_force_rls_
 * on_timeline_events_table.php) to extend the "exactly these tables
 * are forced" list from fifty to fifty-one tables and add this
 * batch's own migration-existence check — same additive-only pattern,
 * no existing assertion removed or weakened. Unlike every prior
 * checkpoint in this file's own history, this migration issues no
 * DROP POLICY/CREATE POLICY at all — a bare FORCE flip only, since
 * timeline_events' existing single-clause policy was already exactly
 * correct (no IS NULL branch needed or wanted; see this batch's own
 * TimelineEventsForceRlsActivationTest for the full proof).
 *
 * Narrowly updated AGAIN by Section 39A-3L, Phase B6, Checkpoint 34
 * (this repo's fifty-second and FINAL staged FORCE activation batch in
 * this arc, covering security_events, database/migrations/2026_08_25_
 * 930034_force_rls_on_security_events_table.php) to extend the
 * "exactly these tables are forced" list from fifty-one to fifty-two
 * tables and add this batch's own migration-existence check — same
 * additive-only pattern, no existing assertion removed or weakened.
 * The highest production-blast-radius checkpoint in the whole mission
 * (two of this table's four write call sites fire synchronously inside
 * Laravel's own authentication flow); see this batch's own
 * SecurityEventsForceRlsActivationTest for the full proof, including
 * the new read/write policy shape (a third distinct nullable-firm_id
 * design, different from both the six "easy" tables and
 * timeline_events).
 *
 * Narrowly updated AGAIN by Section 39A-5, Checkpoint 1 (this repo's
 * fifty-third staged FORCE activation batch, and the FIRST drawn from
 * missingPreparedTables() rather than the now-fully-forced 39A-3
 * PREPARED_TABLES arc — every one of those 52 tables already had FORCE
 * active as of the security_events checkpoint above) covering
 * customer_success_health_scores, database/migrations/2026_08_26_
 * 940001_prepare_row_level_security_and_force_rls_on_customer_success_health_scores_table.php,
 * to extend the "exactly these tables are forced" list from
 * fifty-two to fifty-three tables and add this batch's own
 * migration-existence check — same additive-only pattern, no existing
 * assertion removed or weakened. Unlike every prior checkpoint, this
 * migration both prepares (ENABLE ROW LEVEL SECURITY + CREATE POLICY,
 * with an explicit WITH CHECK clause) and forces the table in a single
 * batch, since no prior preparation migration existed for it; see this
 * batch's own CustomerSuccessHealthScoresForceRlsActivationTest for
 * the full proof.
 *
 * Narrowly updated AGAIN by Section 39A-5 Wave 5 (independent Phase 7
 * test authoring for this batch, run before its own wave-integration
 * commit) to add this batch's own four migration-existence checks
 * (test_the_wave_5_force_rls_migration_files_exist), covering
 * email_accounts, email_messages, email_attachments, and
 * email_sync_events — same additive-only pattern every prior wave used
 * here. Deliberately does NOT touch
 * test_only_clients_and_firm_users_have_permanent_force_row_level_security_among_prepared_tables()'s
 * own $expectedForced list or
 * test_no_new_rls_policy_was_added_for_any_still_uncovered_tenant_table():
 * both read RowLevelSecurityCoverageMappingService's PREPARED_TABLES/
 * MISSING_PREPARED_TABLES arrays, which this batch's own checkpoint
 * deliberately does NOT modify (see EmailAccountsForceRlsActivationTest's
 * own docblock) — the coordinator updates that shared registry once, in
 * a later, separate wave-integration commit, exactly like every prior
 * wave. Until that lands, this batch's four tables are genuinely still
 * listed in MISSING_PREPARED_TABLES while already having real RLS
 * enabled — an expected, temporary, structural artifact of this
 * specific test-authoring phase, not a defect this file's own
 * assertions should be narrowed to hide.
 *
 * Narrowly updated AGAIN by Section 39A-6 Wave 6 (independent Phase 7
 * test authoring for this batch, run before its own wave-integration
 * commit) to add this batch's own migration-existence check
 * (test_the_wave_6_force_rls_migration_files_exist), covering
 * generated_documents, form_drafts, generated_document_events,
 * form_review_events, document_hashes, and pdf_view_events — same
 * additive-only pattern every prior wave used here. UNLIKE the
 * email-domain wave (Section 39A-5 Wave 5), this wave's six tables genuinely
 * ARE still listed in RowLevelSecurityCoverageMappingService's own
 * MISSING_PREPARED_TABLES array (confirmed by direct inspection — each
 * of this wave's own migrations both PREPARES (ENABLE ROW LEVEL
 * SECURITY + CREATE POLICY) and FORCES its table in one migration,
 * exactly like the customer_success_health_scores precedent, rather
 * than merely flipping FORCE on for an already-prepared table like the
 * email domain's four tables did). This means
 * test_no_new_rls_policy_was_added_for_any_still_uncovered_tenant_table()
 * below would otherwise report a false positive for these six genuinely
 * newly-RLS'd tables — narrowly updated to exempt exactly this batch's
 * six tables, with an explanatory comment at the exemption site, rather
 * than weakening the check for any other still-genuinely-uncovered
 * table. The coordinator's later, separate wave-integration commit is
 * still what actually moves these six tables out of
 * MISSING_PREPARED_TABLES and into PREPARED_TABLES — this narrow
 * per-batch exemption is temporary scaffolding for this test-authoring
 * phase only, exactly like the customer_success_health_scores precedent
 * needed before its own registry update eventually landed.
 *
 * Narrowly updated AGAIN by Section 39A-7 Wave 7 (independent Phase 7
 * test authoring for this batch, run before its own wave-integration
 * commit) to add this batch's own migration-existence check
 * (test_the_wave_7_force_rls_migration_files_exist), covering
 * signature_requests, signature_request_recipients, signature_events,
 * and signature_certificates (the e-signature domain) — same
 * additive-only pattern every prior wave used here. Like Section
 * 39A-6 Wave 6 (and unlike the email-domain Wave 5), this wave's four
 * tables genuinely ARE still listed in
 * RowLevelSecurityCoverageMappingService's own MISSING_PREPARED_TABLES
 * array (confirmed by direct inspection — each of this wave's own
 * migrations both PREPARES (ENABLE ROW LEVEL SECURITY + CREATE POLICY)
 * and FORCES its table in one migration). This means
 * test_no_new_rls_policy_was_added_for_any_still_uncovered_tenant_table()
 * below would otherwise report a false positive for these four
 * genuinely newly-RLS'd tables — narrowly updated to exempt exactly
 * this batch's four tables, with an explanatory comment at the
 * exemption site, rather than weakening the check for any other still-
 * genuinely-uncovered table. The coordinator's later, separate
 * wave-integration commit is still what actually moves these four
 * tables out of MISSING_PREPARED_TABLES and into PREPARED_TABLES — this
 * narrow per-batch exemption is temporary scaffolding for this
 * test-authoring phase only, exactly like every prior wave's precedent.
 *
 * Narrowly updated AGAIN by Section 39A-9 Wave 9 (independent Phase 7
 * test authoring for this batch, run before its own wave-integration
 * commit) to add this batch's own migration-existence check
 * (test_the_wave_9_force_rls_migration_files_exist), covering
 * export_jobs, migration_projects, import_batches,
 * implementation_projects, fleet_migration_instance_status, and
 * offboarding_requests (the migration/export domain) — same
 * additive-only pattern every prior wave used here. Like Section
 * 39A-6/39A-7 Wave 6/7 (and unlike the email-domain Wave 5), this
 * wave's six tables genuinely ARE still listed in
 * RowLevelSecurityCoverageMappingService's own MISSING_PREPARED_TABLES
 * array (confirmed by direct inspection — each of this wave's own
 * migrations both PREPARES (ENABLE ROW LEVEL SECURITY + CREATE POLICY)
 * and FORCES its table in one migration). This means
 * test_no_new_rls_policy_was_added_for_any_still_uncovered_tenant_table()
 * below would otherwise report a false positive for these six genuinely
 * newly-RLS'd tables — narrowly updated to exempt exactly this batch's
 * six tables, with an explanatory comment at the exemption site, rather
 * than weakening the check for any other still-genuinely-uncovered
 * table. The coordinator's later, separate wave-integration commit is
 * still what actually moves these six tables out of
 * MISSING_PREPARED_TABLES and into PREPARED_TABLES — this narrow
 * per-batch exemption is temporary scaffolding for this test-authoring
 * phase only, exactly like every prior wave's precedent.
 */
class RlsForceRolloutFirewallTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_clients_and_firm_users_have_permanent_force_row_level_security_among_prepared_tables(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService();

        // Section 39A-3C/39A-3D/39A-3E/39A-3F/39A-3G/39A-3H/39A-3I/39A-3J/
        // 39A-3K/39A-3L (later, distinct staged-FORCE-activation branches)
        // legitimately activated FORCE for documents, deadlines, tasks,
        // matters, invoices, payments, conflict_check_runs, lead_sources,
        // consultation_outcomes, firm_leads, consultations (Section
        // 39A-3J), (Section 39A-3K) firm_practice_areas,
        // document_chase_rules, employee_rates, calendar_events,
        // client_communication_preferences, (Section 39A-3L, Checkpoint
        // 1, Table Phase C) payment_classification_events, (Section
        // 39A-3L, Checkpoint 2, Table Phase C) activation_checklists,
        // (Section 39A-3L, Checkpoint 3, Table Phase C)
        // firm_activation_events, (Section 39A-3L, Checkpoint 4,
        // Table Phase C) firm_entitlements, (Section 39A-3L,
        // Checkpoint 5, Table Phase C) firm_entitlement_events, and
        // (Section 39A-3L, Checkpoint 6, Table Phase C)
        // installed_template_packs, and (Section 39A-3L, Checkpoint 7,
        // Table Phase C) template_upgrade_logs, and (Section 39A-3L,
        // Checkpoint 8, Table Phase C) template_upgrade_previews, and
        // (Section 39A-3L, Checkpoint 9, Table Phase C) seat_allocations,
        // and (Section 39A-3L, Checkpoint 10, Table Phase C)
        // document_requests, and (Section 39A-3L, Checkpoint 11, Table
        // Phase C) communication_consents, and (Section 39A-3L,
        // Checkpoint 12, Table Phase C) communication_consent_events,
        // and (Section 39A-3L, Checkpoint 13, Table Phase C)
        // intake_submissions, and (Section 39A-3L, Checkpoint 14, Table
        // Phase C) matter_readiness_scores, and (Section 39A-3L,
        // Checkpoint 15, Table Phase C) readiness_score_events, and
        // (Section 39A-3L, Checkpoint 16, Table Phase C)
        // tenant_encryption_keys too — this test's own scope (39A-3B)
        // only asserts clients and firm_users here.
        $expectedForced = [
            'clients', 'firm_users', 'documents', 'deadlines', 'tasks', 'matters', 'invoices', 'payments', 'conflict_check_runs',
            'lead_sources', 'consultation_outcomes', 'firm_leads', 'consultations',
            'firm_practice_areas', 'document_chase_rules', 'employee_rates', 'calendar_events', 'client_communication_preferences',
            'payment_classification_events', 'activation_checklists', 'firm_activation_events', 'firm_entitlements', 'firm_entitlement_events',
            'installed_template_packs', 'template_upgrade_logs', 'template_upgrade_previews', 'seat_allocations', 'document_requests',
            'communication_consents', 'communication_consent_events', 'intake_submissions',
            'matter_readiness_scores', 'readiness_score_events', 'tenant_encryption_keys', 'document_chase_events',
            // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 18,
            // Table Phase C (this repo's thirty-sixth staged FORCE
            // activation batch, covering firm_settings) to extend the
            // "exactly these tables are forced" list from thirty-five
            // to thirty-six tables — same additive-only pattern, no
            // existing assertion removed or weakened.
            'firm_settings',
            // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 19,
            // Table Phase C (this repo's thirty-seventh staged FORCE
            // activation batch, covering firm_licenses) to extend the
            // "exactly these tables are forced" list from thirty-six to
            // thirty-seven tables — same additive-only pattern, no
            // existing assertion removed or weakened.
            'firm_licenses',
            // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 20,
            // Table Phase C (this repo's thirty-eighth staged FORCE
            // activation batch, covering time_tracking_sessions) to
            // extend the "exactly these tables are forced" list from
            // thirty-seven to thirty-eight tables — same additive-only
            // pattern, no existing assertion removed or weakened.
            'time_tracking_sessions',
            // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 21,
            // Table Phase C (this repo's thirty-ninth staged FORCE
            // activation batch, covering time_entries) to extend the
            // "exactly these tables are forced" list from thirty-eight
            // to thirty-nine tables — same additive-only pattern, no
            // existing assertion removed or weakened.
            'time_entries',
            // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 22,
            // Table Phase C (this repo's fortieth staged FORCE
            // activation batch, covering payment_plans) to extend the
            // "exactly these tables are forced" list from thirty-nine
            // to forty tables — same additive-only pattern, no
            // existing assertion removed or weakened.
            'payment_plans',
            // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 23,
            // Table Phase C (this repo's forty-first staged FORCE
            // activation batch, covering payment_plan_events) to
            // extend the "exactly these tables are forced" list from
            // forty to forty-one tables — same additive-only pattern,
            // no existing assertion removed or weakened.
            'payment_plan_events',
            // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 24
            // (this repo's forty-second staged FORCE activation
            // batch, covering notification_events) to extend the
            // "exactly these tables are forced" list from forty-one
            // to forty-two tables — same additive-only pattern, no
            // existing assertion removed or weakened.
            'notification_events',
            // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 25
            // (this repo's forty-third staged FORCE activation batch,
            // covering contacts) to extend the "exactly these tables
            // are forced" list from forty-two to forty-three tables —
            // same additive-only pattern, no existing assertion
            // removed or weakened. parties, contacts' sibling table
            // under the same prerequisite remediation, remained
            // untouched/unforced at that point — it was addressed by
            // the separate Checkpoint 26 below.
            'contacts',
            // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 26
            // (this repo's forty-fourth staged FORCE activation batch,
            // covering parties) to extend the "exactly these tables
            // are forced" list from forty-three to forty-four tables —
            // same additive-only pattern, no existing assertion
            // removed or weakened.
            'parties',
            // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 27
            // (this repo's forty-fifth staged FORCE activation batch,
            // covering backup_restore_tests) to extend the "exactly
            // these tables are forced" list from forty-four to
            // forty-five tables — same additive-only pattern, no
            // existing assertion removed or weakened.
            'backup_restore_tests',
            // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 28
            // (this repo's forty-sixth staged FORCE activation batch,
            // covering health_checks) to extend the "exactly these
            // tables are forced" list from forty-five to forty-six
            // tables — same additive-only pattern, no existing
            // assertion removed or weakened.
            'health_checks',
            // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 29
            // (this repo's forty-seventh staged FORCE activation
            // batch, covering incident_events) to extend the "exactly
            // these tables are forced" list from forty-six to
            // forty-seven tables — same additive-only pattern, no
            // existing assertion removed or weakened.
            'incident_events',
            // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 30
            // (this repo's forty-eighth staged FORCE activation
            // batch, covering maintenance_windows) to extend the
            // "exactly these tables are forced" list from forty-seven
            // to forty-eight tables — same additive-only pattern, no
            // existing assertion removed or weakened.
            'maintenance_windows',
            // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 31,
            // Phase B6 (this repo's forty-ninth staged FORCE
            // activation batch, covering notification_templates) to
            // extend the "exactly these tables are forced" list from
            // forty-eight to forty-nine tables — same additive-only
            // pattern, no existing assertion removed or weakened.
            'notification_templates',
            // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 32,
            // Phase B6 (this repo's fiftieth staged FORCE activation
            // batch, covering pilot_feedback_items) to extend the
            // "exactly these tables are forced" list from forty-nine
            // to fifty tables — same additive-only pattern, no
            // existing assertion removed or weakened.
            'pilot_feedback_items',
            // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 33,
            // Phase B6 (this repo's fifty-first staged FORCE activation
            // batch, covering timeline_events) to extend the "exactly
            // these tables are forced" list from fifty to fifty-one
            // tables — same additive-only pattern, no existing
            // assertion removed or weakened.
            'timeline_events',
            // Narrowly updated AGAIN by Section 39A-3L, Phase B6,
            // Checkpoint 34 (this repo's fifty-second and FINAL staged
            // FORCE activation batch in this arc, covering
            // security_events) to extend the "exactly these tables are
            // forced" list from fifty-one to fifty-two tables — same
            // additive-only pattern, no existing assertion removed or
            // weakened.
            'security_events',
            // Narrowly updated AGAIN by Section 39A-5, Checkpoint 1 —
            // the first staged FORCE activation batch drawn from
            // missingPreparedTables() rather than the now-fully-forced
            // 39A-3 PREPARED_TABLES arc, covering
            // customer_success_health_scores (database/migrations/
            // 2026_08_26_940001_prepare_row_level_security_and_force_
            // rls_on_customer_success_health_scores_table.php) — extends the "exactly
            // these tables are forced" list from fifty-two to
            // fifty-three tables. Unlike every prior entry in this
            // list, this table had no pre-existing RLS preparation;
            // the migration both prepares and forces it in the same
            // batch — same additive-only pattern, no existing
            // assertion removed or weakened.
            'customer_success_health_scores',
            // Narrowly updated AGAIN by Section 39A-5 Wave 1 — the
            // first coordinated multi-table wave of this arc, covering
            // ai_retrieval_indexes, deployment_configs, and
            // firm_ai_settings together (each with its own combined
            // prepare+force migration) — extends the "exactly these
            // tables are forced" list from fifty-three to fifty-six —
            // same additive-only pattern, no existing assertion
            // removed or weakened.
            'ai_retrieval_indexes', 'deployment_configs', 'firm_ai_settings',
            // Narrowly updated AGAIN by Section 39A-5 Wave 2 — the
            // second coordinated multi-table wave of this arc, covering
            // email_visibility_rules, private_enterprise_settings,
            // matter_expenses, and email_message_links together (each
            // with its own combined prepare+force migration) — extends
            // the "exactly these tables are forced" list from
            // fifty-six to sixty — same additive-only pattern, no
            // existing assertion removed or weakened.
            'email_visibility_rules', 'private_enterprise_settings', 'matter_expenses', 'email_message_links',
            'ai_usage_events', 'ai_tool_actions', 'firm_ai_provider_keys', 'ai_approval_requests', 'ai_approval_events',
            // Narrowly updated AGAIN by Section 39A-5 Wave 4 — the
            // fourth coordinated multi-table wave, covering the
            // accounting/expense domain (chart_of_accounts,
            // expense_categories, expenses, expense_receipts,
            // expense_approvals, accounting_export_batches,
            // accounting_export_lines) implemented as one combined unit
            // — extends the "exactly these tables are forced" list from
            // sixty-five to seventy-two — same additive-only pattern, no
            // existing assertion removed or weakened.
            'chart_of_accounts', 'expense_categories', 'expenses', 'expense_receipts',
            'expense_approvals', 'accounting_export_batches', 'accounting_export_lines',
            // Narrowly updated AGAIN by Section 39A-5 Wave 5 — the
            // fifth coordinated multi-table wave, covering the email
            // domain (email_accounts, email_messages, email_attachments,
            // email_sync_events) implemented as one combined unit —
            // extends the "exactly these tables are forced" list from
            // seventy-two to seventy-six — same additive-only pattern,
            // no existing assertion removed or weakened.
            'email_accounts', 'email_messages', 'email_attachments', 'email_sync_events',
            'generated_documents', 'form_drafts', 'generated_document_events', 'form_review_events', 'document_hashes', 'pdf_view_events',
            // Narrowly updated AGAIN by Section 39A-7 Wave 7 — the
            // seventh coordinated multi-table wave, covering the
            // e-signature domain (signature_requests,
            // signature_request_recipients, signature_events,
            // signature_certificates) implemented as one combined unit.
            'signature_requests', 'signature_request_recipients', 'signature_events', 'signature_certificates',
            // Narrowly updated AGAIN by Section 39A-8 Wave 8 — the
            // eighth coordinated multi-table wave, covering the
            // governance/support/platform domain (legal_holds,
            // deletion_requests, key_destruction_requests,
            // support_access_requests, support_access_sessions,
            // deployment_health_checks) implemented as one combined unit.
            'legal_holds', 'deletion_requests', 'key_destruction_requests',
            'support_access_requests', 'support_access_sessions', 'deployment_health_checks',
            // Narrowly updated AGAIN by Section 39A-5 Wave 9 — the
            // ninth coordinated multi-table wave, covering the
            // migration/export domain (export_jobs, migration_projects,
            // import_batches, implementation_projects,
            // fleet_migration_instance_status, offboarding_requests)
            // implemented as one combined unit.
            'export_jobs', 'migration_projects', 'import_batches',
            'implementation_projects', 'fleet_migration_instance_status', 'offboarding_requests',
            // Narrowly updated AGAIN by Section 39A-5 Wave 10 — the tenth
            // coordinated multi-table wave, covering the trust accounting
            // domain (trust_accounts, trust_ledgers, trust_balances,
            // matter_trust_balances, trust_ledger_entries,
            // trust_approval_events, trust_chargeback_events,
            // trust_reconciliations, trust_refund_requests,
            // trust_transfer_requests) implemented as one combined unit.
            'trust_accounts', 'trust_ledgers', 'trust_balances', 'matter_trust_balances',
            'trust_ledger_entries', 'trust_approval_events', 'trust_chargeback_events',
            'trust_reconciliations', 'trust_refund_requests', 'trust_transfer_requests',
            // Narrowly updated AGAIN by Section 39A-5 Wave 11 — the
            // eleventh and FINAL coordinated multi-table wave of this
            // arc, covering the webhooks domain (webhook_deliveries,
            // webhook_delivery_attempts, webhook_events,
            // webhook_secrets, webhook_subscriptions) implemented as
            // one combined unit — extends the "exactly these tables
            // are forced" list from one hundred eight to one hundred
            // thirteen — same additive-only pattern, no existing
            // assertion removed or weakened. This closes the entire
            // 60-table RLS backlog.
            'webhook_deliveries', 'webhook_delivery_attempts', 'webhook_events',
            'webhook_secrets', 'webhook_subscriptions',
            // Narrowly updated by Stage B Checkpoint 3 of the FirmsBase
            // Integration Platform mission (firm_integrations, a brand-new
            // genuine tenant-owned table, RLS prepared and FORCE-activated
            // in the same migration, NOT part of the old 60-table rollout
            // above) — extends the "exactly these tables are forced" list
            // from one hundred thirteen to one hundred fourteen — same
            // additive-only pattern, no existing assertion removed or
            // weakened.
            'firm_integrations', 'integration_credentials', 'integration_oauth_states',
            // Narrowly updated AGAIN by Stage B Checkpoint 6 of the
            // FirmsBase Integration Platform mission ("Transactional
            // Outbox and Sync Persistence Foundation") — six brand-new
            // genuine tenant-owned tables (integration_sync_runs,
            // integration_sync_items, integration_external_mappings,
            // integration_sync_cursors, integration_conflicts,
            // integration_outbox_events), each RLS prepared and
            // FORCE-activated in its own combined migration — extends
            // the "exactly these tables are forced" list from one
            // hundred sixteen to one hundred twenty-two — same
            // additive-only pattern, no existing assertion removed or
            // weakened.
            'integration_sync_runs', 'integration_sync_items', 'integration_external_mappings',
            'integration_sync_cursors', 'integration_conflicts', 'integration_outbox_events',
        ];

        foreach ($coverage->preparedTables() as $table) {
            $row = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);

            $this->assertNotNull($row, "Table {$table} not found in pg_class.");

            $shouldBeForced = in_array($table, $expectedForced, true);

            $this->assertSame(
                $shouldBeForced,
                (bool) $row->relforcerowsecurity,
                $shouldBeForced
                    ? "{$table} must have permanent FORCE ROW LEVEL SECURITY active."
                    : "{$table} must not have permanent FORCE ROW LEVEL SECURITY — Section 39A-3B activates firm_users only (clients was already forced by 39A-3A)."
            );
        }
    }

    public function test_no_new_rls_policy_was_added_for_any_still_uncovered_tenant_table(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService();

        // Section 39A-6 Wave 6's own six tables genuinely ARE still
        // listed in MISSING_PREPARED_TABLES (the coordinator's registry
        // update is a later, separate wave-integration commit — see
        // this file's own class docblock) but DO now legitimately have
        // real RLS enabled via this wave's own combined
        // prepare-and-force migrations (the same shape
        // customer_success_health_scores used). Exempting exactly these
        // six, by name, is not a general weakening of this check — every
        // OTHER still-genuinely-uncovered tenant table must still report
        // relrowsecurity = false below.
        $wave6ExemptTables = [
            'generated_documents', 'form_drafts', 'generated_document_events',
            'form_review_events', 'document_hashes', 'pdf_view_events',
        ];

        // Section 39A-7 Wave 7's own four tables — identical exemption
        // shape as Wave 6 immediately above: genuinely still listed in
        // MISSING_PREPARED_TABLES (coordinator's registry update is a
        // later, separate wave-integration commit) but DO now
        // legitimately have real RLS enabled via this wave's own
        // combined prepare-and-force migrations.
        $wave7ExemptTables = [
            'signature_requests', 'signature_request_recipients',
            'signature_events', 'signature_certificates',
        ];

        // Section 39A-8 Wave 8's own six tables — identical exemption
        // shape as Wave 6/Wave 7 immediately above: genuinely still
        // listed in MISSING_PREPARED_TABLES (coordinator's registry
        // update is a later, separate wave-integration commit) but DO
        // now legitimately have real RLS enabled via this wave's own
        // combined prepare-and-force migrations.
        $wave8ExemptTables = [
            'legal_holds', 'deletion_requests', 'key_destruction_requests',
            'support_access_requests', 'support_access_sessions', 'deployment_health_checks',
        ];

        // Section 39A-9 Wave 9's own six tables — identical exemption
        // shape as Wave 6/Wave 7 immediately above: genuinely still
        // listed in MISSING_PREPARED_TABLES (coordinator's registry
        // update is a later, separate wave-integration commit) but DO
        // now legitimately have real RLS enabled via this wave's own
        // combined prepare-and-force migrations.
        $wave9ExemptTables = [
            'export_jobs', 'migration_projects', 'import_batches',
            'implementation_projects', 'fleet_migration_instance_status',
            'offboarding_requests',
        ];

        // Section 39A-10 Wave 10's own ten tables — identical exemption
        // shape as Wave 6/7/8/9 immediately above. Unlike those waves,
        // this exemption is likely a no-op in practice: by the time this
        // test runs, the coordinator's registry-update commit has already
        // moved these ten tables out of MISSING_PREPARED_TABLES, so they
        // will not even be visited by the loop below. It is added anyway
        // to mirror every prior wave's precedent and to remain correct if
        // ever run against a commit ordered before that registry update.
        $wave10ExemptTables = [
            'trust_accounts', 'trust_ledgers', 'trust_balances', 'matter_trust_balances',
            'trust_ledger_entries', 'trust_approval_events', 'trust_chargeback_events',
            'trust_reconciliations', 'trust_refund_requests', 'trust_transfer_requests',
        ];

        foreach ($coverage->missingPreparedTables() as $table) {
            if (in_array($table, $wave6ExemptTables, true) || in_array($table, $wave7ExemptTables, true) || in_array($table, $wave8ExemptTables, true) || in_array($table, $wave9ExemptTables, true) || in_array($table, $wave10ExemptTables, true)) {
                continue;
            }

            $row = DB::selectOne('select relrowsecurity from pg_class where relname = ?', [$table]);

            if ($row === null) {
                continue;
            }

            $this->assertFalse(
                (bool) $row->relrowsecurity,
                "{$table} was reported as missing RLS preparation, but RLS is now enabled — Section 39A-3B must not add new policies for uncovered tables."
            );
        }
    }

    public function test_the_firm_users_force_rls_migration_file_exists(): void
    {
        // File-existence check, not a git-diff/untracked-state check:
        // this branch's own instructions say "do not commit," but this
        // test file itself must still work correctly if a future
        // section commits/merges it (matching the lesson learned from
        // Section 39A-3A's own equivalent test).
        $this->assertFileExists(base_path('database/migrations/2026_07_31_900001_force_rls_on_firm_users_table.php'));
    }

    public function test_the_documents_force_rls_migration_file_exists(): void
    {
        // Section 39A-3C's own migration — same file-existence
        // reasoning as the firm_users check above.
        $this->assertFileExists(base_path('database/migrations/2026_08_01_900001_force_rls_on_documents_table.php'));
    }

    public function test_the_deadlines_force_rls_migration_file_exists(): void
    {
        // Section 39A-3D's own migration — same file-existence
        // reasoning as the firm_users/documents checks above.
        $this->assertFileExists(base_path('database/migrations/2026_08_02_900001_force_rls_on_deadlines_table.php'));
    }

    public function test_the_tasks_force_rls_migration_file_exists(): void
    {
        // Section 39A-3E's own migration — same file-existence
        // reasoning as the firm_users/documents/deadlines checks above.
        $this->assertFileExists(base_path('database/migrations/2026_08_03_900001_force_rls_on_tasks_table.php'));
    }

    public function test_the_matters_force_rls_migration_file_exists(): void
    {
        // Section 39A-3F's own migration — same file-existence
        // reasoning as the firm_users/documents/deadlines/tasks checks
        // above.
        $this->assertFileExists(base_path('database/migrations/2026_08_04_900001_force_rls_on_matters_table.php'));
    }

    public function test_the_invoices_force_rls_migration_file_exists(): void
    {
        // Section 39A-3G's own migration — same file-existence
        // reasoning as the firm_users/documents/deadlines/tasks/
        // matters checks above.
        $this->assertFileExists(base_path('database/migrations/2026_08_05_900001_force_rls_on_invoices_table.php'));
    }

    public function test_the_payments_force_rls_migration_file_exists(): void
    {
        // Section 39A-3H's own migration — same file-existence
        // reasoning as the firm_users/documents/deadlines/tasks/
        // matters/invoices checks above.
        $this->assertFileExists(base_path('database/migrations/2026_08_06_900001_force_rls_on_payments_table.php'));
    }

    public function test_the_conflict_check_runs_force_rls_migration_file_exists(): void
    {
        // Section 39A-3I's own migration — same file-existence
        // reasoning as the firm_users/documents/deadlines/tasks/
        // matters/invoices/payments checks above.
        $this->assertFileExists(base_path('database/migrations/2026_08_11_900001_force_rls_on_conflict_check_runs_table.php'));
    }

    public function test_the_lead_sources_force_rls_migration_file_exists(): void
    {
        // Section 39A-3J's own migration (this batch, table 1 of 4) —
        // same file-existence reasoning as the firm_users/documents/
        // deadlines/tasks/matters/invoices/payments/conflict_check_runs
        // checks above.
        $this->assertFileExists(base_path('database/migrations/2026_08_12_900001_force_rls_on_lead_sources_table.php'));
    }

    public function test_the_consultation_outcomes_force_rls_migration_file_exists(): void
    {
        // Section 39A-3J's own migration (this batch, table 2 of 4) —
        // same file-existence reasoning as the checks above.
        $this->assertFileExists(base_path('database/migrations/2026_08_13_900001_force_rls_on_consultation_outcomes_table.php'));
    }

    public function test_the_firm_leads_force_rls_migration_file_exists(): void
    {
        // Section 39A-3J's own migration (this batch, table 3 of 4) —
        // same file-existence reasoning as the checks above.
        $this->assertFileExists(base_path('database/migrations/2026_08_14_900001_force_rls_on_firm_leads_table.php'));
    }

    public function test_the_consultations_force_rls_migration_file_exists(): void
    {
        // Section 39A-3J's own migration (this batch, table 4 of 4) —
        // same file-existence reasoning as the checks above.
        $this->assertFileExists(base_path('database/migrations/2026_08_15_900001_force_rls_on_consultations_table.php'));
    }

    public function test_the_firm_practice_areas_force_rls_migration_file_exists(): void
    {
        // Section 39A-3K's own migration (this batch, table 1 of 5) —
        // same file-existence reasoning as the checks above.
        $this->assertFileExists(base_path('database/migrations/2026_08_20_920001_force_rls_on_firm_practice_areas_table.php'));
    }

    public function test_the_document_chase_rules_force_rls_migration_file_exists(): void
    {
        // Section 39A-3K's own migration (this batch, table 2 of 5) —
        // same file-existence reasoning as the checks above.
        $this->assertFileExists(base_path('database/migrations/2026_08_20_920002_force_rls_on_document_chase_rules_table.php'));
    }

    public function test_the_employee_rates_force_rls_migration_file_exists(): void
    {
        // Section 39A-3K's own migration (this batch, table 3 of 5) —
        // same file-existence reasoning as the checks above.
        $this->assertFileExists(base_path('database/migrations/2026_08_20_920003_force_rls_on_employee_rates_table.php'));
    }

    public function test_the_calendar_events_force_rls_migration_file_exists(): void
    {
        // Section 39A-3K's own migration (this batch, table 4 of 5) —
        // same file-existence reasoning as the checks above.
        $this->assertFileExists(base_path('database/migrations/2026_08_20_920004_force_rls_on_calendar_events_table.php'));
    }

    public function test_the_client_communication_preferences_force_rls_migration_file_exists(): void
    {
        // Section 39A-3K's own migration (this batch, table 5 of 5) —
        // same file-existence reasoning as the checks above.
        $this->assertFileExists(base_path('database/migrations/2026_08_20_920005_force_rls_on_client_communication_preferences_table.php'));
    }

    public function test_the_payment_classification_events_force_rls_migration_file_exists(): void
    {
        // Section 39A-3L, Checkpoint 1, Table Phase C's own migration —
        // same file-existence reasoning as the checks above.
        $this->assertFileExists(base_path('database/migrations/2026_08_25_930001_force_rls_on_payment_classification_events_table.php'));
    }

    public function test_the_activation_checklists_force_rls_migration_file_exists(): void
    {
        // Section 39A-3L, Checkpoint 2, Table Phase C's own migration —
        // same file-existence reasoning as the checks above.
        $this->assertFileExists(base_path('database/migrations/2026_08_25_930002_force_rls_on_activation_checklists_table.php'));
    }

    public function test_the_firm_activation_events_force_rls_migration_file_exists(): void
    {
        // Section 39A-3L, Checkpoint 3, Table Phase C's own migration —
        // same file-existence reasoning as the checks above.
        $this->assertFileExists(base_path('database/migrations/2026_08_25_930003_force_rls_on_firm_activation_events_table.php'));
    }

    public function test_the_firm_entitlements_force_rls_migration_file_exists(): void
    {
        // Section 39A-3L, Checkpoint 4, Table Phase C's own migration —
        // same file-existence reasoning as the checks above.
        $this->assertFileExists(base_path('database/migrations/2026_08_25_930004_force_rls_on_firm_entitlements_table.php'));
    }

    public function test_the_firm_entitlement_events_force_rls_migration_file_exists(): void
    {
        // Section 39A-3L, Checkpoint 5, Table Phase C's own migration —
        // same file-existence reasoning as the checks above.
        $this->assertFileExists(base_path('database/migrations/2026_08_25_930005_force_rls_on_firm_entitlement_events_table.php'));
    }

    public function test_the_installed_template_packs_force_rls_migration_file_exists(): void
    {
        // Section 39A-3L, Checkpoint 6, Table Phase C's own migration —
        // same file-existence reasoning as the checks above.
        $this->assertFileExists(base_path('database/migrations/2026_08_25_930006_force_rls_on_installed_template_packs_table.php'));
    }

    public function test_the_template_upgrade_logs_force_rls_migration_file_exists(): void
    {
        // Section 39A-3L, Checkpoint 7, Table Phase C's own migration —
        // same file-existence reasoning as the checks above.
        $this->assertFileExists(base_path('database/migrations/2026_08_25_930007_force_rls_on_template_upgrade_logs_table.php'));
    }

    public function test_the_template_upgrade_previews_force_rls_migration_file_exists(): void
    {
        // Section 39A-3L, Checkpoint 8, Table Phase C's own migration —
        // same file-existence reasoning as the checks above.
        $this->assertFileExists(base_path('database/migrations/2026_08_25_930008_force_rls_on_template_upgrade_previews_table.php'));
    }

    public function test_the_seat_allocations_force_rls_migration_file_exists(): void
    {
        // Section 39A-3L, Checkpoint 9, Table Phase C's own migration —
        // same file-existence reasoning as the checks above.
        $this->assertFileExists(base_path('database/migrations/2026_08_25_930009_force_rls_on_seat_allocations_table.php'));
    }

    public function test_the_document_requests_force_rls_migration_file_exists(): void
    {
        // Section 39A-3L, Checkpoint 10, Table Phase C's own migration —
        // same file-existence reasoning as the checks above.
        $this->assertFileExists(base_path('database/migrations/2026_08_25_930010_force_rls_on_document_requests_table.php'));
    }

    public function test_the_communication_consents_force_rls_migration_file_exists(): void
    {
        // Section 39A-3L, Checkpoint 11, Table Phase C's own migration —
        // same file-existence reasoning as the checks above.
        $this->assertFileExists(base_path('database/migrations/2026_08_25_930011_force_rls_on_communication_consents_table.php'));
    }

    public function test_the_communication_consent_events_force_rls_migration_file_exists(): void
    {
        // Section 39A-3L, Checkpoint 12, Table Phase C's own migration —
        // same file-existence reasoning as the checks above.
        $this->assertFileExists(base_path('database/migrations/2026_08_25_930012_force_rls_on_communication_consent_events_table.php'));
    }

    public function test_the_intake_submissions_force_rls_migration_file_exists(): void
    {
        // Section 39A-3L, Checkpoint 13, Table Phase C's own migration —
        // same file-existence reasoning as the checks above.
        $this->assertFileExists(base_path('database/migrations/2026_08_25_930013_force_rls_on_intake_submissions_table.php'));
    }

    public function test_the_matter_readiness_scores_force_rls_migration_file_exists(): void
    {
        // Section 39A-3L, Checkpoint 14, Table Phase C's own migration —
        // same file-existence reasoning as the checks above.
        $this->assertFileExists(base_path('database/migrations/2026_08_25_930014_force_rls_on_matter_readiness_scores_table.php'));
    }

    public function test_the_readiness_score_events_force_rls_migration_file_exists(): void
    {
        // Section 39A-3L, Checkpoint 15, Table Phase C's own migration —
        // same file-existence reasoning as the checks above.
        $this->assertFileExists(base_path('database/migrations/2026_08_25_930015_force_rls_on_readiness_score_events_table.php'));
    }

    public function test_the_tenant_encryption_keys_force_rls_migration_file_exists(): void
    {
        // Section 39A-3L, Checkpoint 16, Table Phase C's own migration —
        // same file-existence reasoning as the checks above.
        $this->assertFileExists(base_path('database/migrations/2026_08_25_930016_force_rls_on_tenant_encryption_keys_table.php'));
    }

    public function test_the_firm_licenses_force_rls_migration_file_exists(): void
    {
        // Section 39A-3L, Checkpoint 19, Table Phase C's own migration —
        // same file-existence reasoning as the checks above.
        $this->assertFileExists(base_path('database/migrations/2026_08_25_930019_force_rls_on_firm_licenses_table.php'));
    }

    public function test_the_time_tracking_sessions_force_rls_migration_file_exists(): void
    {
        // Section 39A-3L, Checkpoint 20, Table Phase C's own migration —
        // same file-existence reasoning as the checks above.
        $this->assertFileExists(base_path('database/migrations/2026_08_25_930020_force_rls_on_time_tracking_sessions_table.php'));
    }

    public function test_the_time_entries_force_rls_migration_file_exists(): void
    {
        // Section 39A-3L, Checkpoint 21, Table Phase C's own migration —
        // same file-existence reasoning as the checks above.
        $this->assertFileExists(base_path('database/migrations/2026_08_25_930021_force_rls_on_time_entries_table.php'));
    }

    public function test_the_payment_plans_force_rls_migration_file_exists(): void
    {
        // Section 39A-3L, Checkpoint 22, Table Phase C's own migration —
        // same file-existence reasoning as the checks above.
        $this->assertFileExists(base_path('database/migrations/2026_08_25_930022_force_rls_on_payment_plans_table.php'));
    }

    public function test_the_payment_plan_events_force_rls_migration_file_exists(): void
    {
        // Section 39A-3L, Checkpoint 23, Table Phase C's own migration —
        // same file-existence reasoning as the checks above.
        $this->assertFileExists(base_path('database/migrations/2026_08_25_930023_force_rls_on_payment_plan_events_table.php'));
    }

    public function test_the_notification_events_force_rls_migration_file_exists(): void
    {
        // Section 39A-3L, Checkpoint 24's own migration — same
        // file-existence reasoning as the checks above.
        $this->assertFileExists(base_path('database/migrations/2026_08_25_930024_force_rls_on_notification_events_table.php'));
    }

    public function test_the_contacts_force_rls_migration_file_exists(): void
    {
        // Section 39A-3L, Checkpoint 25's own migration — same
        // file-existence reasoning as the checks above.
        $this->assertFileExists(base_path('database/migrations/2026_08_25_930025_force_rls_on_contacts_table.php'));
    }

    public function test_the_parties_force_rls_migration_file_exists(): void
    {
        // Section 39A-3L, Checkpoint 26's own migration — same
        // file-existence reasoning as the checks above.
        $this->assertFileExists(base_path('database/migrations/2026_08_25_930026_force_rls_on_parties_table.php'));
    }

    public function test_the_backup_restore_tests_force_rls_migration_file_exists(): void
    {
        // Section 39A-3L, Checkpoint 27's own migration — same
        // file-existence reasoning as the checks above.
        $this->assertFileExists(base_path('database/migrations/2026_08_25_930027_force_rls_on_backup_restore_tests_table.php'));
    }

    public function test_the_health_checks_force_rls_migration_file_exists(): void
    {
        // Section 39A-3L, Checkpoint 28's own migration — same
        // file-existence reasoning as the checks above.
        $this->assertFileExists(base_path('database/migrations/2026_08_25_930028_force_rls_on_health_checks_table.php'));
    }

    public function test_the_incident_events_force_rls_migration_file_exists(): void
    {
        // Section 39A-3L, Checkpoint 29's own migration — same
        // file-existence reasoning as the checks above.
        $this->assertFileExists(base_path('database/migrations/2026_08_25_930029_force_rls_on_incident_events_table.php'));
    }

    public function test_the_maintenance_windows_force_rls_migration_file_exists(): void
    {
        // Section 39A-3L, Checkpoint 30's own migration — same
        // file-existence reasoning as the checks above.
        $this->assertFileExists(base_path('database/migrations/2026_08_25_930030_force_rls_on_maintenance_windows_table.php'));
    }

    public function test_the_notification_templates_force_rls_migration_file_exists(): void
    {
        // Section 39A-3L, Checkpoint 31, Phase B6's own migration —
        // same file-existence reasoning as the checks above.
        $this->assertFileExists(base_path('database/migrations/2026_08_25_930031_force_rls_on_notification_templates_table.php'));
    }

    public function test_the_pilot_feedback_items_force_rls_migration_file_exists(): void
    {
        // Section 39A-3L, Checkpoint 32, Phase B6's own migration —
        // same file-existence reasoning as the checks above.
        $this->assertFileExists(base_path('database/migrations/2026_08_25_930032_force_rls_on_pilot_feedback_items_table.php'));
    }

    public function test_the_timeline_events_force_rls_migration_file_exists(): void
    {
        // Section 39A-3L, Checkpoint 33, Phase B6's own migration —
        // same file-existence reasoning as the checks above. Unlike
        // every prior checkpoint's own migration file, this one issues
        // no DROP POLICY/CREATE POLICY at all (bare FORCE flip only) —
        // its existence is still proven here the same way.
        $this->assertFileExists(base_path('database/migrations/2026_08_25_930033_force_rls_on_timeline_events_table.php'));
    }

    public function test_the_security_events_force_rls_migration_file_exists(): void
    {
        // Section 39A-3L, Phase B6, Checkpoint 34's own migration — same
        // file-existence reasoning as the checks above. This is the
        // eighth and final nullable-firm_id table in this arc, and the
        // highest production-blast-radius checkpoint in the whole
        // mission.
        $this->assertFileExists(base_path('database/migrations/2026_08_25_930034_force_rls_on_security_events_table.php'));
    }

    public function test_the_customer_success_health_scores_force_rls_migration_file_exists(): void
    {
        // Section 39A-5, Checkpoint 1's own migration — same
        // file-existence reasoning as the checks above. This is the
        // first checkpoint in this file's own history to draw its
        // table from missingPreparedTables() rather than the
        // now-fully-forced PREPARED_TABLES arc, and the first migration
        // to both prepare (ENABLE ROW LEVEL SECURITY + CREATE POLICY)
        // and force a table in a single batch.
        $this->assertFileExists(base_path('database/migrations/2026_08_26_940001_prepare_row_level_security_and_force_rls_on_customer_success_health_scores_table.php'));
    }

    public function test_the_wave_1_force_rls_migration_files_exist(): void
    {
        // Section 39A-5 Wave 1 — the first coordinated multi-table
        // wave, three independent combined prepare+force migrations
        // landed together via a wave-integration commit.
        $this->assertFileExists(base_path('database/migrations/2026_08_27_950001_prepare_row_level_security_and_force_rls_on_ai_retrieval_indexes_table.php'));
        $this->assertFileExists(base_path('database/migrations/2026_08_27_950002_prepare_row_level_security_and_force_rls_on_deployment_configs_table.php'));
        $this->assertFileExists(base_path('database/migrations/2026_08_27_950003_prepare_row_level_security_and_force_rls_on_firm_ai_settings_table.php'));
    }

    public function test_the_wave_2_force_rls_migration_files_exist(): void
    {
        // Section 39A-5 Wave 2 — the second coordinated multi-table
        // wave, four independent combined prepare+force migrations
        // landed together via a wave-integration commit.
        $this->assertFileExists(base_path('database/migrations/2026_08_27_950004_prepare_row_level_security_and_force_rls_on_email_message_links_table.php'));
        $this->assertFileExists(base_path('database/migrations/2026_08_27_950005_prepare_row_level_security_and_force_rls_on_email_visibility_rules_table.php'));
        $this->assertFileExists(base_path('database/migrations/2026_08_27_950011_prepare_row_level_security_and_force_rls_on_private_enterprise_settings_table.php'));
        $this->assertFileExists(base_path('database/migrations/2026_08_27_950012_prepare_row_level_security_and_force_rls_on_matter_expenses_table.php'));
    }

    public function test_the_wave_3_force_rls_migration_files_exist(): void
    {
        // Section 39A-5 Wave 3 — the third coordinated multi-table
        // wave (AI governance domain), five combined prepare+force
        // migrations landed together via a wave-integration commit.
        $this->assertFileExists(base_path('database/migrations/2026_08_27_950013_prepare_row_level_security_and_force_rls_on_ai_usage_events_table.php'));
        $this->assertFileExists(base_path('database/migrations/2026_08_27_950014_prepare_row_level_security_and_force_rls_on_ai_tool_actions_table.php'));
        $this->assertFileExists(base_path('database/migrations/2026_08_27_950015_prepare_row_level_security_and_force_rls_on_firm_ai_provider_keys_table.php'));
        $this->assertFileExists(base_path('database/migrations/2026_08_27_950016_prepare_row_level_security_and_force_rls_on_ai_approval_requests_table.php'));
        $this->assertFileExists(base_path('database/migrations/2026_08_27_950017_prepare_row_level_security_and_force_rls_on_ai_approval_events_table.php'));
    }

    public function test_the_wave_4_force_rls_migration_files_exist(): void
    {
        // Section 39A-5 Wave 4 — the fourth coordinated multi-table
        // wave (accounting/expense domain), seven combined prepare+force
        // migrations implemented and committed as one unit, then landed
        // together via a wave-integration commit.
        $this->assertFileExists(base_path('database/migrations/2026_08_27_950018_prepare_row_level_security_and_force_rls_on_chart_of_accounts_table.php'));
        $this->assertFileExists(base_path('database/migrations/2026_08_27_950019_prepare_row_level_security_and_force_rls_on_expense_categories_table.php'));
        $this->assertFileExists(base_path('database/migrations/2026_08_27_950020_prepare_row_level_security_and_force_rls_on_expenses_table.php'));
        $this->assertFileExists(base_path('database/migrations/2026_08_27_950021_prepare_row_level_security_and_force_rls_on_expense_receipts_table.php'));
        $this->assertFileExists(base_path('database/migrations/2026_08_27_950022_prepare_row_level_security_and_force_rls_on_expense_approvals_table.php'));
        $this->assertFileExists(base_path('database/migrations/2026_08_27_950023_prepare_row_level_security_and_force_rls_on_accounting_export_batches_table.php'));
        $this->assertFileExists(base_path('database/migrations/2026_08_27_950024_prepare_row_level_security_and_force_rls_on_accounting_export_lines_table.php'));
    }

    public function test_the_wave_5_force_rls_migration_files_exist(): void
    {
        // Section 39A-5 Wave 5 — the fifth coordinated multi-table wave
        // (email domain), four combined prepare+force migrations
        // implemented and committed as one unit, to be landed together
        // via a later wave-integration commit.
        $this->assertFileExists(base_path('database/migrations/2026_08_27_950025_prepare_row_level_security_and_force_rls_on_email_accounts_table.php'));
        $this->assertFileExists(base_path('database/migrations/2026_08_27_950026_prepare_row_level_security_and_force_rls_on_email_messages_table.php'));
        $this->assertFileExists(base_path('database/migrations/2026_08_27_950027_prepare_row_level_security_and_force_rls_on_email_attachments_table.php'));
        $this->assertFileExists(base_path('database/migrations/2026_08_27_950028_prepare_row_level_security_and_force_rls_on_email_sync_events_table.php'));
    }

    public function test_the_wave_6_force_rls_migration_files_exist(): void
    {
        // Section 39A-6 Wave 6 — the sixth coordinated multi-table wave
        // (documents/forms domain), six combined prepare+force
        // migrations implemented and committed as one unit, to be landed
        // together via a later wave-integration commit.
        $this->assertFileExists(base_path('database/migrations/2026_08_27_950029_prepare_row_level_security_and_force_rls_on_generated_documents_table.php'));
        $this->assertFileExists(base_path('database/migrations/2026_08_27_950030_prepare_row_level_security_and_force_rls_on_form_drafts_table.php'));
        $this->assertFileExists(base_path('database/migrations/2026_08_27_950031_prepare_row_level_security_and_force_rls_on_generated_document_events_table.php'));
        $this->assertFileExists(base_path('database/migrations/2026_08_27_950032_prepare_row_level_security_and_force_rls_on_form_review_events_table.php'));
        $this->assertFileExists(base_path('database/migrations/2026_08_27_950033_prepare_row_level_security_and_force_rls_on_document_hashes_table.php'));
        $this->assertFileExists(base_path('database/migrations/2026_08_27_950034_prepare_row_level_security_and_force_rls_on_pdf_view_events_table.php'));
    }

    public function test_the_wave_7_force_rls_migration_files_exist(): void
    {
        // Section 39A-7 Wave 7 — the seventh coordinated multi-table
        // wave (e-signature domain), four combined prepare+force
        // migrations implemented and committed as one unit, to be
        // landed together via a later wave-integration commit.
        $this->assertFileExists(base_path('database/migrations/2026_08_27_950035_prepare_row_level_security_and_force_rls_on_signature_requests_table.php'));
        $this->assertFileExists(base_path('database/migrations/2026_08_27_950036_prepare_row_level_security_and_force_rls_on_signature_request_recipients_table.php'));
        $this->assertFileExists(base_path('database/migrations/2026_08_27_950037_prepare_row_level_security_and_force_rls_on_signature_events_table.php'));
        $this->assertFileExists(base_path('database/migrations/2026_08_27_950038_prepare_row_level_security_and_force_rls_on_signature_certificates_table.php'));
    }

    public function test_the_wave_8_force_rls_migration_files_exist(): void
    {
        // Section 39A-8 Wave 8 — the eighth coordinated multi-table
        // wave (governance/support/platform domain), six combined
        // prepare+force migrations implemented and committed as one
        // unit, to be landed together via a later wave-integration
        // commit.
        $this->assertFileExists(base_path('database/migrations/2026_08_28_960001_prepare_row_level_security_and_force_rls_on_legal_holds_table.php'));
        $this->assertFileExists(base_path('database/migrations/2026_08_28_960002_prepare_row_level_security_and_force_rls_on_deletion_requests_table.php'));
        $this->assertFileExists(base_path('database/migrations/2026_08_28_960003_prepare_row_level_security_and_force_rls_on_key_destruction_requests_table.php'));
        $this->assertFileExists(base_path('database/migrations/2026_08_28_960004_prepare_row_level_security_and_force_rls_on_support_access_requests_table.php'));
        $this->assertFileExists(base_path('database/migrations/2026_08_28_960005_prepare_row_level_security_and_force_rls_on_support_access_sessions_table.php'));
        $this->assertFileExists(base_path('database/migrations/2026_08_28_960006_prepare_row_level_security_and_force_rls_on_deployment_health_checks_table.php'));
    }

    public function test_the_wave_9_force_rls_migration_files_exist(): void
    {
        // Section 39A-9 Wave 9 — the ninth coordinated multi-table wave
        // (migration/export domain), six combined prepare+force
        // migrations implemented and committed as one unit, to be
        // landed together via a later wave-integration commit.
        $this->assertFileExists(base_path('database/migrations/2026_08_29_970001_prepare_row_level_security_and_force_rls_on_export_jobs_table.php'));
        $this->assertFileExists(base_path('database/migrations/2026_08_29_970002_prepare_row_level_security_and_force_rls_on_migration_projects_table.php'));
        $this->assertFileExists(base_path('database/migrations/2026_08_29_970003_prepare_row_level_security_and_force_rls_on_import_batches_table.php'));
        $this->assertFileExists(base_path('database/migrations/2026_08_29_970004_prepare_row_level_security_and_force_rls_on_implementation_projects_table.php'));
        $this->assertFileExists(base_path('database/migrations/2026_08_29_970005_prepare_row_level_security_and_force_rls_on_fleet_migration_instance_status_table.php'));
        $this->assertFileExists(base_path('database/migrations/2026_08_29_970006_prepare_row_level_security_and_force_rls_on_offboarding_requests_table.php'));
    }

    public function test_the_wave_10_force_rls_migration_files_exist(): void
    {
        // Section 39A-10 Wave 10 — the tenth coordinated multi-table wave
        // (trust accounting domain), ten combined prepare+force
        // migrations implemented and committed as one unit (no safe
        // partial rollout — see this wave's own design doc §9 on the
        // shared TrustConcurrencyLockService coupling), to be landed
        // together via a later wave-integration commit.
        $this->assertFileExists(base_path('database/migrations/2026_08_30_980001_prepare_row_level_security_and_force_rls_on_trust_accounts_table.php'));
        $this->assertFileExists(base_path('database/migrations/2026_08_30_980002_prepare_row_level_security_and_force_rls_on_trust_ledgers_table.php'));
        $this->assertFileExists(base_path('database/migrations/2026_08_30_980003_prepare_row_level_security_and_force_rls_on_trust_balances_table.php'));
        $this->assertFileExists(base_path('database/migrations/2026_08_30_980004_prepare_row_level_security_and_force_rls_on_matter_trust_balances_table.php'));
        $this->assertFileExists(base_path('database/migrations/2026_08_30_980005_prepare_row_level_security_and_force_rls_on_trust_ledger_entries_table.php'));
        $this->assertFileExists(base_path('database/migrations/2026_08_30_980006_prepare_row_level_security_and_force_rls_on_trust_approval_events_table.php'));
        $this->assertFileExists(base_path('database/migrations/2026_08_30_980007_prepare_row_level_security_and_force_rls_on_trust_chargeback_events_table.php'));
        $this->assertFileExists(base_path('database/migrations/2026_08_30_980008_prepare_row_level_security_and_force_rls_on_trust_reconciliations_table.php'));
        $this->assertFileExists(base_path('database/migrations/2026_08_30_980009_prepare_row_level_security_and_force_rls_on_trust_refund_requests_table.php'));
        $this->assertFileExists(base_path('database/migrations/2026_08_30_980010_prepare_row_level_security_and_force_rls_on_trust_transfer_requests_table.php'));
    }

    public function test_no_ui_routes_or_controllers_were_introduced(): void
    {
        foreach (['routes', 'app/Http/Controllers', 'app/Filament', 'resources/views', 'app/Livewire'] as $relativeDir) {
            $changed = $this->changedOrUntrackedPaths($relativeDir);

            $this->assertEmpty($changed, "Section 39A-3B must introduce no UI/route surface, but found changes under {$relativeDir}: ".implode(', ', $changed));
        }

        $this->assertDirectoryDoesNotExist(base_path('app/Filament'));
        $this->assertDirectoryDoesNotExist(base_path('app/Livewire'));
    }

    public function test_compliance_gap_registry_service_was_not_deleted_or_rewritten(): void
    {
        $changed = $this->changedOrUntrackedPaths('app/Services/ComplianceGapRegistryService.php');

        $this->assertEmpty($changed, 'ComplianceGapRegistryService.php must remain untouched — no resolved-state lifecycle exists to safely mark the gap resolved.');
    }

    public function test_gap_registry_still_tracks_the_rls_gap_and_count_remains_twenty_one(): void
    {
        $registry = new ComplianceGapRegistryService();

        $this->assertTrue($registry->isTracked('rls_prepared_not_enforced'));
        $this->assertCount(21, $registry->all());
    }

    /**
     * @return array<int, string>
     */
    private function changedOrUntrackedPaths(string $scope): array
    {
        $changed = trim((string) shell_exec(
            'git -C '.escapeshellarg(base_path()).' ls-files --modified --others --exclude-standard -- '.escapeshellarg($scope)
        ));

        if ($changed === '') {
            return [];
        }

        return preg_split('/\R/', $changed) ?: [];
    }
}
