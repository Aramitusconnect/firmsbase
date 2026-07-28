<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Integrations\Core\ProviderRegistry;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Models\IntegrationProvider;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\IntegrationProviderFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use Tests\TestCase;

/**
 * IntegrationProviderTest — Checkpoint 2 (Platform Provider Metadata),
 * checkpoint-00-final-specification.md §5 table #1.
 *
 * `integration_providers` is Global/platform-wide reference data: there
 * is no firm_id column and no tenant dimension at all, so RLS/FORCE RLS
 * is a deliberate exemption, not an oversight. Because there is no
 * firm_id to deny by, there is no "ordinary firm denial" test possible
 * or appropriate here (per the assignment brief) — the correct proof
 * for a Global table is that it is genuinely, verifiably open at the
 * database catalog level (RLS not enabled, not forced, zero policies),
 * not merely "nobody happened to add a policy yet."
 */
class IntegrationProviderTest extends TestCase
{
    use RefreshDatabase;

    private const EXPECTED_COLUMNS = [
        'id',
        'code',
        'display_name',
        'category',
        'auth_method',
        'status',
        'module_code',
        'degradation_type_key',
        'required_oauth_scopes_json',
        'webhook_event_types_json',
        'created_at',
        'updated_at',
    ];

    /**
     * POST-CHECKPOINT-6 UPDATE: Checkpoint 6 added 5 tables that
     * independently carry a real composite FK (firm_id,
     * firm_integration_id) -> firm_integrations(firm_id, id) —
     * integration_sync_runs, integration_external_mappings,
     * integration_sync_cursors, integration_conflicts,
     * integration_outbox_events — plus integration_sync_items, which is
     * not itself a direct dependent of firm_integrations but is a
     * composite-FK dependent of integration_sync_runs and must be rolled
     * back before it. Both rollback tests below now also roll back this
     * entire 12-migration Checkpoint 6 wave (in the frozen
     * reverse-dependency order, frozen-design-post-review.md §2:
     * outbox_events -> conflicts -> sync_cursors -> external_mappings ->
     * sync_items -> sync_runs) before firm_integrations' own rollback —
     * mirroring this checkpoint's own
     * IntegrationSyncRunsForceRlsActivationTest::WHOLE_WAVE_MIGRATION_PATHS
     * precedent and Checkpoint 5's identical rollback-order-dependency
     * precedent (reviews/checkpoint-05/precommit-failure-triage.md).
     * Order between this CP6 wave and the pre-existing
     * integration_credentials / integration_oauth_states rollback blocks
     * below does not matter — neither references the other, only each
     * independently references firm_integrations.
     *
     * @var list<string>
     */
    private const CP6_WHOLE_WAVE_MIGRATION_PATHS = [
        'database/migrations/2026_09_05_050001_create_integration_sync_runs_table.php',
        'database/migrations/2026_09_05_050002_prepare_row_level_security_and_force_rls_on_integration_sync_runs_table.php',
        'database/migrations/2026_09_05_051001_create_integration_sync_items_table.php',
        'database/migrations/2026_09_05_051002_prepare_row_level_security_and_force_rls_on_integration_sync_items_table.php',
        'database/migrations/2026_09_05_052001_create_integration_external_mappings_table.php',
        'database/migrations/2026_09_05_052002_prepare_row_level_security_and_force_rls_on_integration_external_mappings_table.php',
        'database/migrations/2026_09_05_053001_create_integration_sync_cursors_table.php',
        'database/migrations/2026_09_05_053002_prepare_row_level_security_and_force_rls_on_integration_sync_cursors_table.php',
        'database/migrations/2026_09_05_054001_create_integration_conflicts_table.php',
        'database/migrations/2026_09_05_054002_prepare_row_level_security_and_force_rls_on_integration_conflicts_table.php',
        'database/migrations/2026_09_05_055001_create_integration_outbox_events_table.php',
        'database/migrations/2026_09_05_055002_prepare_row_level_security_and_force_rls_on_integration_outbox_events_table.php',
    ];

    /**
     * @var list<string>
     */
    private const CP6_WHOLE_WAVE_TABLES = [
        'integration_sync_runs',
        'integration_sync_items',
        'integration_external_mappings',
        'integration_sync_cursors',
        'integration_conflicts',
        'integration_outbox_events',
    ];

    /**
     * POST-CHECKPOINT-7 UPDATE: Checkpoint 7 ("inbound webhook security",
     * reviews/checkpoint-07/frozen-design-post-security-review.md §5.1/
     * §10/§11) added a FOURTH independent dependency chain reaching all
     * the way back to integration_providers —
     * integration_webhook_routing_index carries a real FK
     * (restrictOnDelete()) directly into integration_providers
     * (integration_provider_id), in addition to its own composite FK into
     * firm_integrations, so integration_providers can no longer be
     * dropped/rolled back while it exists either.
     * integration_inbound_webhook_events is a second, independent direct
     * dependent of firm_integrations from the same Checkpoint 7 wave.
     * integration_webhook_receipts carries no FK into either table, but
     * integration_inbound_webhook_events FK-references it (receipt_id),
     * so it must be rolled back before that table. The trailing
     * add_triggering_webhook_event_id_to_integration_sync_runs_table
     * migration adds a real composite FK from the Checkpoint 6
     * integration_sync_runs table into integration_inbound_webhook_events,
     * so it must be rolled back before that table too — and because it
     * ALTERs integration_sync_runs itself, it must ALSO be rolled back
     * before CP6_WHOLE_WAVE_MIGRATION_PATHS' own sync_runs table is
     * dropped below. Both rollback tests below therefore roll back this
     * entire 5-migration Checkpoint 7 wave FIRST — before the Checkpoint
     * 6 whole-wave block, since Checkpoint 7 is the newest layer and its
     * own trailing migration reaches back into a Checkpoint 6 table — in
     * exact reverse of its own creation order (§11: "down() runs exact
     * reverse" of routing-index, receipts, events, events-RLS,
     * sync_runs-FK), then reapply it LAST, after the Checkpoint 6
     * whole-wave block, in forward (creation) order. This mirrors the
     * exact whole-wave precedent Checkpoint 6 itself established to fix
     * this identical class of problem (CP6_WHOLE_WAVE_MIGRATION_PATHS
     * above) — order between this Checkpoint 7 wave and the pre-existing
     * firm_integrations / integration_credentials /
     * integration_oauth_states blocks does not matter to each other,
     * except that this wave must be fully torn down before
     * firm_integrations itself (its two direct dependents also
     * composite-FK firm_integrations) and, independently, before
     * integration_providers itself (routing_index's own direct FK).
     *
     * @var list<string>
     */
    private const CP7_WHOLE_WAVE_MIGRATION_PATHS = [
        'database/migrations/2026_09_06_060001_create_integration_webhook_routing_index_table.php',
        'database/migrations/2026_09_06_060002_create_integration_webhook_receipts_table.php',
        'database/migrations/2026_09_06_060003_create_integration_inbound_webhook_events_table.php',
        'database/migrations/2026_09_06_060004_prepare_row_level_security_and_force_rls_on_integration_inbound_webhook_events_table.php',
        'database/migrations/2026_09_06_060005_add_triggering_webhook_event_id_to_integration_sync_runs_table.php',
    ];

    /**
     * @var list<string>
     */
    private const CP7_WHOLE_WAVE_TABLES = [
        'integration_webhook_routing_index',
        'integration_webhook_receipts',
        'integration_inbound_webhook_events',
    ];

    /**
     * POST-CHECKPOINT-8 UPDATE: Checkpoint 8 ("jobs, retries, health,
     * dispatch, retention",
     * reviews/checkpoint-08/agent-8h-architecture-security-review.md §1
     * item 6) added a FIFTH independent dependency chain reaching
     * firm_integrations (transitively, integration_providers) —
     * integration_connection_health (see
     * database/migrations/2026_09_07_070001_create_integration_connection_health_table.php)
     * carries a real composite FK (firm_id, firm_integration_id) ->
     * firm_integrations(firm_id, id) with cascadeOnDelete(), identical in
     * shape to integration_credentials'/integration_oauth_states' own
     * composite FK. Unlike Checkpoint 7's
     * integration_webhook_routing_index, it carries NO FK directly into
     * integration_providers — confirmed by reading the migration file —
     * so its only relevance here is that it must be fully torn down
     * before firm_integrations' own rollback can succeed, one level
     * further up the same transitive chain that already required tearing
     * it down before integration_providers' own rollback. Both rollback
     * tests below now also roll back this 2-migration Checkpoint 8 wave
     * FIRST — before the Checkpoint 7 whole-wave block, since Checkpoint
     * 8 is the newest layer — in exact reverse of its own creation order
     * (RLS-prep down(), then create-table down()), then reapply it LAST,
     * after the Checkpoint 7 whole-wave block, in forward (creation)
     * order. Order between this Checkpoint 8 wave and the pre-existing
     * firm_integrations / integration_credentials /
     * integration_oauth_states / Checkpoint 6 / Checkpoint 7 blocks does
     * not matter to each other, except that this wave must be fully torn
     * down before firm_integrations itself.
     *
     * @var list<string>
     */
    private const CP8_WHOLE_WAVE_MIGRATION_PATHS = [
        'database/migrations/2026_09_07_070001_create_integration_connection_health_table.php',
        'database/migrations/2026_09_07_070002_prepare_row_level_security_and_force_rls_on_integration_connection_health_table.php',
    ];

    /**
     * @var list<string>
     */
    private const CP8_WHOLE_WAVE_TABLES = [
        'integration_connection_health',
    ];

    /**
     * POST-CHECKPOINT-9 UPDATE: Checkpoint 9 ("usage, audit, retention,
     * access, and governance") added a SIXTH independent dependency chain
     * reaching all the way back to firm_integrations (transitively,
     * integration_providers) — integration_usage_records carries a real
     * composite FK (firm_id, firm_integration_id) ->
     * firm_integrations(firm_id, id) with cascadeOnDelete(), identical in
     * shape to integration_connection_health's own composite FK, PLUS
     * three more composite FKs (ON DELETE SET NULL) into
     * integration_sync_runs, integration_sync_items (both Checkpoint 6),
     * and integration_inbound_webhook_events (Checkpoint 7). Both
     * rollback tests below now also roll back this 2-migration Checkpoint
     * 9 wave FIRST — before the Checkpoint 8 whole-wave block, since
     * Checkpoint 9 is the newest layer — in exact reverse of its own
     * creation order (RLS-prep down(), then create-table down()), then
     * reapply it LAST, after the Checkpoint 8 whole-wave block, in
     * forward (creation) order. Order between this wave and the
     * pre-existing firm_integrations / integration_credentials /
     * integration_oauth_states / Checkpoint 6 / Checkpoint 7 / Checkpoint
     * 8 blocks does not matter to each other, except that this wave must
     * be fully torn down before any of integration_sync_runs,
     * integration_sync_items, integration_inbound_webhook_events, or
     * firm_integrations itself.
     *
     * @var list<string>
     */
    private const CP9_WHOLE_WAVE_MIGRATION_PATHS = [
        'database/migrations/2026_09_08_080001_create_integration_usage_records_table.php',
        'database/migrations/2026_09_08_080002_prepare_row_level_security_and_force_rls_on_integration_usage_records_table.php',
    ];

    /**
     * @var list<string>
     */
    private const CP9_WHOLE_WAVE_TABLES = [
        'integration_usage_records',
    ];

    /**
     * POST-CHECKPOINT-4-PLAID UPDATE: Checkpoint 4 ("Plaid financial
     * evidence add-on") added an EIGHTH independent dependency chain
     * reaching firm_integrations — provider_billable_call_reservations
     * carries a real (bare, single-column) FK `usage_record_id` ->
     * integration_usage_records(id) (nullOnDelete()), and several more
     * Checkpoint 4 tables (integration_plaid_item_routes,
     * financial_evidence_bank_accounts,
     * financial_evidence_client_consents,
     * financial_evidence_matter_authorizations, and
     * provider_balance_snapshots among them) carry their own real
     * composite FKs directly into firm_integrations — confirmed
     * empirically: rolling back only the two
     * provider_billable_call_reservations migrations was NOT sufficient
     * to unblock a firm_integrations drop; the FULL Checkpoint 4
     * migration wave (every migration dated 2026_09_24/2026_09_25 for
     * this checkpoint) must be rolled back as one unit FIRST — before
     * the CP9 whole-wave block above, since Checkpoint 4 is the newest
     * layer — or that rollback fails with "cannot drop table ... because
     * other objects depend on it". Reapplied LAST of all, after the CP9
     * whole-wave block, in forward (creation) order. Mirrors every other
     * whole-wave precedent in this file exactly.
     *
     * @var list<string>
     */
    private const CP4_WHOLE_WAVE_MIGRATION_PATHS = [
        'database/migrations/2026_09_24_180001_create_client_portal_users_table.php',
        'database/migrations/2026_09_24_180001_create_integration_plaid_item_routes_table.php',
        'database/migrations/2026_09_24_180002_seed_plaid_integration_provider_catalog_entry.php',
        'database/migrations/2026_09_24_180003_create_client_portal_password_reset_tokens_table.php',
        'database/migrations/2026_09_24_180003_create_financial_evidence_bank_accounts_table.php',
        'database/migrations/2026_09_24_180004_create_client_portal_matter_grants_table.php',
        'database/migrations/2026_09_24_180004_prepare_row_level_security_and_force_rls_on_financial_evidence_bank_accounts_table.php',
        'database/migrations/2026_09_24_180005_create_financial_evidence_transactions_table.php',
        'database/migrations/2026_09_24_180005_prepare_row_level_security_and_force_rls_on_client_portal_matter_grants_table.php',
        'database/migrations/2026_09_24_180006_add_self_lookup_clause_to_clients_rls_policy.php',
        'database/migrations/2026_09_24_180006_prepare_row_level_security_and_force_rls_on_financial_evidence_transactions_table.php',
        'database/migrations/2026_09_24_180007_create_financial_evidence_income_records_table.php',
        'database/migrations/2026_09_24_180008_prepare_row_level_security_and_force_rls_on_financial_evidence_income_records_table.php',
        'database/migrations/2026_09_24_180009_create_financial_evidence_liabilities_table.php',
        'database/migrations/2026_09_24_180010_prepare_row_level_security_and_force_rls_on_financial_evidence_liabilities_table.php',
        'database/migrations/2026_09_24_180011_create_financial_evidence_investment_records_table.php',
        'database/migrations/2026_09_24_180012_prepare_row_level_security_and_force_rls_on_financial_evidence_investment_records_table.php',
        'database/migrations/2026_09_24_180013_create_financial_evidence_statements_table.php',
        'database/migrations/2026_09_24_180014_prepare_row_level_security_and_force_rls_on_financial_evidence_statements_table.php',
        'database/migrations/2026_09_24_180015_create_financial_evidence_identity_records_table.php',
        'database/migrations/2026_09_24_180016_prepare_row_level_security_and_force_rls_on_financial_evidence_identity_records_table.php',
        'database/migrations/2026_09_24_500001_create_provider_rate_card_entries_table.php',
        'database/migrations/2026_09_24_500002_create_provider_billable_call_reservations_table.php',
        'database/migrations/2026_09_24_500003_prepare_row_level_security_and_force_rls_on_provider_billable_call_reservations_table.php',
        'database/migrations/2026_09_24_500004_create_provider_kill_switches_table.php',
        'database/migrations/2026_09_24_500005_create_provider_operation_default_policies_table.php',
        'database/migrations/2026_09_24_500006_create_provider_firm_operation_policies_table.php',
        'database/migrations/2026_09_24_500007_prepare_row_level_security_and_force_rls_on_provider_firm_operation_policies_table.php',
        'database/migrations/2026_09_24_500008_create_provider_balance_snapshots_table.php',
        'database/migrations/2026_09_24_500009_prepare_row_level_security_and_force_rls_on_provider_balance_snapshots_table.php',
        'database/migrations/2026_09_24_500010_create_provider_invoice_reconciliations_table.php',
        'database/migrations/2026_09_24_500011_seed_plaid_module_catalog_entry.php',
        'database/migrations/2026_09_25_190001_create_financial_evidence_matter_requests_table.php',
        'database/migrations/2026_09_25_190002_prepare_row_level_security_and_force_rls_on_financial_evidence_matter_requests_table.php',
        'database/migrations/2026_09_25_190003_create_financial_evidence_client_consents_table.php',
        'database/migrations/2026_09_25_190004_prepare_row_level_security_and_force_rls_on_financial_evidence_client_consents_table.php',
        'database/migrations/2026_09_25_190005_create_financial_evidence_matter_authorizations_table.php',
        'database/migrations/2026_09_25_190006_prepare_row_level_security_and_force_rls_on_financial_evidence_matter_authorizations_table.php',
        'database/migrations/2026_09_25_190007_create_financial_evidence_matter_notes_table.php',
        'database/migrations/2026_09_25_190008_prepare_row_level_security_and_force_rls_on_financial_evidence_matter_notes_table.php',
        'database/migrations/2026_09_25_190009_create_financial_evidence_snapshots_table.php',
        'database/migrations/2026_09_25_190010_prepare_row_level_security_and_force_rls_on_financial_evidence_snapshots_table.php',
        'database/migrations/2026_09_25_190011_create_financial_evidence_transaction_reviews_table.php',
        'database/migrations/2026_09_25_190012_prepare_row_level_security_and_force_rls_on_financial_evidence_transaction_reviews_table.php',
        'database/migrations/2026_09_25_190013_create_financial_evidence_duplicate_transfer_flags_table.php',
        'database/migrations/2026_09_25_190014_prepare_row_level_security_and_force_rls_on_financial_evidence_duplicate_transfer_flags_table.php',
        'database/migrations/2026_09_25_190015_create_financial_evidence_large_deposit_flags_table.php',
        'database/migrations/2026_09_25_190016_prepare_row_level_security_and_force_rls_on_financial_evidence_large_deposit_flags_table.php',
        'database/migrations/2026_09_25_190017_create_financial_evidence_large_deposit_thresholds_table.php',
        'database/migrations/2026_09_25_190018_create_financial_evidence_reconciliation_candidates_table.php',
        'database/migrations/2026_09_25_190019_prepare_row_level_security_and_force_rls_on_financial_evidence_reconciliation_candidates_table.php',
        'database/migrations/2026_09_25_190020_create_financial_account_reclassification_requests_table.php',
        'database/migrations/2026_09_25_190021_prepare_row_level_security_and_force_rls_on_financial_account_reclassification_requests_table.php',
    ];

    /**
     * @var list<string>
     */
    private const CP4_WHOLE_WAVE_TABLES = [
        'client_portal_users',
        'integration_plaid_item_routes',
        'client_portal_password_reset_tokens',
        'financial_evidence_bank_accounts',
        'client_portal_matter_grants',
        'financial_evidence_transactions',
        'financial_evidence_income_records',
        'financial_evidence_liabilities',
        'financial_evidence_investment_records',
        'financial_evidence_statements',
        'financial_evidence_identity_records',
        'provider_rate_card_entries',
        'provider_billable_call_reservations',
        'provider_kill_switches',
        'provider_operation_default_policies',
        'provider_firm_operation_policies',
        'provider_balance_snapshots',
        'provider_invoice_reconciliations',
        'financial_evidence_matter_requests',
        'financial_evidence_client_consents',
        'financial_evidence_matter_authorizations',
        'financial_evidence_matter_notes',
        'financial_evidence_snapshots',
        'financial_evidence_transaction_reviews',
        'financial_evidence_duplicate_transfer_flags',
        'financial_evidence_large_deposit_flags',
        'financial_evidence_large_deposit_thresholds',
        'financial_evidence_reconciliation_candidates',
        'financial_account_reclassification_requests',
    ];

    /**
     * FirmsVault Live Integrations, Checkpoint 2 (Microsoft 365 provider)
     * UPDATE: Checkpoint 2 added a SEVENTH independent dependency chain
     * reaching all the way back to firm_integrations (transitively,
     * integration_providers) — integration_provider_webhook_subscriptions
     * carries a real composite FK (firm_id, firm_integration_id) ->
     * firm_integrations(firm_id, id) with cascadeOnDelete()
     * (constraint integration_provider_webhook_subscriptions_firm_integration_fk),
     * identical in shape to integration_connection_health's/
     * integration_usage_records' own composite FK
     * (checkpoint2-combined-design.md §2 P-17). Both rollback tests below
     * now also roll back this 2-migration Checkpoint 2 wave FIRST — before
     * the Checkpoint 9 whole-wave block, since this Checkpoint 2 wave is
     * the newest layer (dated 2026_09_22, after Checkpoint 9's
     * 2026_09_08) — in exact reverse of its own creation order (RLS-prep
     * down(), then create-table down()), then reapply it LAST, after the
     * Checkpoint 9 whole-wave block, in forward (creation) order. Order
     * between this wave and the pre-existing firm_integrations /
     * integration_credentials / integration_oauth_states / Checkpoint 6 /
     * Checkpoint 7 / Checkpoint 8 / Checkpoint 9 blocks does not matter to
     * each other, except that this wave must be fully torn down before
     * firm_integrations itself. Mirrors the identical whole-wave precedent
     * Checkpoint 6 through Checkpoint 9 each established for this exact
     * class of problem.
     *
     * @var list<string>
     */
    private const CP2_WEBHOOK_SUBSCRIPTIONS_WHOLE_WAVE_MIGRATION_PATHS = [
        'database/migrations/2026_09_22_160001_create_integration_provider_webhook_subscriptions_table.php',
        'database/migrations/2026_09_22_160002_prepare_row_level_security_and_force_rls_on_integration_provider_webhook_subscriptions_table.php',
    ];

    /**
     * @var list<string>
     */
    private const CP2_WEBHOOK_SUBSCRIPTIONS_WHOLE_WAVE_TABLES = [
        'integration_provider_webhook_subscriptions',
    ];

    /**
     * FirmsVault Live Integrations, Checkpoint 3 (Google Workspace
     * provider) added an EIGHTH independent dependency chain reaching
     * integration_providers directly — integration_gmail_mailbox_routes
     * carries a real composite FK (firm_id, firm_integration_id) ->
     * firm_integrations(firm_id, id) with cascadeOnDelete(), AND a
     * separate direct FK (integration_provider_id) ->
     * integration_providers(id) with restrictOnDelete() (identical in
     * shape to integration_webhook_routing_index's own direct FK on
     * integration_providers). Unlike every prior whole-wave layer, this
     * one is a SINGLE migration — the table is Global/no-RLS by
     * deliberate design (mirrors integration_webhook_routing_index's own
     * no-RLS classification), so there is no companion RLS-prepare
     * migration. Both rollback tests below now also roll back this
     * 1-migration Checkpoint 3 wave FIRST — before the Checkpoint 2
     * webhook-subscriptions whole-wave block, since this Checkpoint 3 wave
     * is the newest layer of all (dated 2026_09_23, after Checkpoint 2's
     * own 2026_09_22) — then reapply it LAST, after the Checkpoint 2
     * webhook-subscriptions whole-wave block. Order between this wave and
     * the pre-existing firm_integrations / integration_credentials /
     * integration_oauth_states / Checkpoint 6 / Checkpoint 7 / Checkpoint
     * 8 / Checkpoint 9 / Checkpoint 2 webhook-subscriptions blocks does
     * not matter to each other, except that this wave must be fully torn
     * down before integration_providers itself. Mirrors the identical
     * whole-wave precedent Checkpoint 6 through the Checkpoint 2
     * webhook-subscriptions wave each established for this exact class of
     * problem.
     *
     * @var list<string>
     */
    private const CP3_GMAIL_MAILBOX_ROUTES_WHOLE_WAVE_MIGRATION_PATHS = [
        'database/migrations/2026_09_23_170001_create_integration_gmail_mailbox_routes_table.php',
    ];

    /**
     * @var list<string>
     */
    private const CP3_GMAIL_MAILBOX_ROUTES_WHOLE_WAVE_TABLES = [
        'integration_gmail_mailbox_routes',
    ];

    // ------------------------------------------------------------
    // 1. Schema correctness
    // ------------------------------------------------------------

    public function test_integration_providers_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('integration_providers'));
    }

    public function test_integration_providers_has_exactly_the_expected_columns(): void
    {
        $columns = Schema::getColumnListing('integration_providers');

        sort($columns);
        $expected = self::EXPECTED_COLUMNS;
        sort($expected);

        $this->assertSame(
            $expected,
            $columns,
            'integration_providers must have exactly the documented column set — no more, no fewer.'
        );
    }

    public function test_integration_providers_has_no_firm_id_column(): void
    {
        $this->assertFalse(
            Schema::hasColumn('integration_providers', 'firm_id'),
            'integration_providers is Global reference data with no tenant dimension — it must never gain a firm_id column.'
        );
    }

    // ------------------------------------------------------------
    // 2. RLS exemption is real and deliberate, not accidental
    // ------------------------------------------------------------

    public function test_integration_providers_does_not_have_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'integration_providers'");

        $this->assertNotNull($row);
        $this->assertFalse(
            (bool) $row->relrowsecurity,
            'integration_providers has no firm_id/tenant dimension at all — RLS must not be enabled on it.'
        );
    }

    public function test_integration_providers_does_not_have_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'integration_providers'");

        $this->assertNotNull($row);
        $this->assertFalse(
            (bool) $row->relforcerowsecurity,
            'integration_providers has no firm_id/tenant dimension at all — FORCE RLS must not be enabled on it.'
        );
    }

    public function test_integration_providers_has_zero_row_level_security_policies(): void
    {
        $rows = DB::select("select policyname from pg_policies where tablename = 'integration_providers'");

        $this->assertCount(
            0,
            $rows,
            'integration_providers must have zero pg_policies rows — a Global, un-tenanted table should never accumulate a stray policy.'
        );
    }

    // ------------------------------------------------------------
    // 3. Seed data correctness
    // ------------------------------------------------------------

    /**
     * FirmsVault Live Integrations, Checkpoint 4 (Plaid financial
     * evidence add-on) RENAME AGAIN: this method was previously named
     * test_exactly_three_rows_are_seeded_after_migration and asserted a
     * count of 3 (itself a rename of test_exactly_two_rows_are_seeded_after_migration,
     * before that test_exactly_one_row_is_seeded_after_migration — see
     * this method's own prior docblock in git history for the full
     * lineage). The new
     * 2026_09_24_500011_seed_plaid_module_catalog_entry.php migration
     * seeds a fourth, independent catalog row (`plaid`) alongside `test`,
     * `microsoft365`, and `googleworkspace` — mirrors this codebase's own
     * RlsForceRollout convention of encoding the exact expected count in
     * the test name and renaming it whenever that count legitimately
     * changes.
     */
    public function test_exactly_four_rows_are_seeded_after_migration(): void
    {
        $this->assertSame(4, DB::table('integration_providers')->count());
    }

    /**
     * FirmsVault Live Integrations, Checkpoint 2 UPDATE: now that a
     * second catalog row (`microsoft365`) is seeded alongside `test`
     * (see test_exactly_four_rows_are_seeded_after_migration() above),
     * bare ->first() with no ORDER BY is no longer a safe way to locate
     * the `test` row specifically — SQL gives no ordering guarantee
     * without an explicit ORDER BY once more than one row exists. This
     * now filters explicitly by code, matching what the test's own name
     * always claimed to prove.
     */
    public function test_seeded_row_matches_the_test_provider_key(): void
    {
        $row = DB::table('integration_providers')->where('code', ProviderKey::Test->value)->first();

        $this->assertNotNull($row);
        $this->assertSame(ProviderKey::Test->value, $row->code);
    }

    /**
     * FirmsVault Live Integrations, Checkpoint 4 UPDATE: `plaid` is now a
     * genuine, real, in-scope provider (the "Plaid financial evidence
     * add-on") — deliberately REMOVED from this list, not an oversight.
     * Every other entry remains a real provider this mission has NOT
     * built (still correctly asserted absent).
     */
    public function test_no_row_exists_for_any_real_provider_code_not_yet_built_by_this_mission(): void
    {
        $realProviderCodesNotYetBuilt = [
            'google', 'microsoft', 'stripe', 'quickbooks', 'lawpay',
            'clio', 'zoom', 'dropbox', 'xero', 'docusign',
        ];

        $existing = DB::table('integration_providers')
            ->whereIn('code', $realProviderCodesNotYetBuilt)
            ->pluck('code')
            ->all();

        $this->assertSame(
            [],
            $existing,
            'No real provider outside this mission\'s own built scope (google/microsoft/stripe/etc.) may be registered — seeding a catalog row for one would be out of scope.'
        );
    }

    // ------------------------------------------------------------
    // 3b. Migration reversibility — automated, repeatable proof
    // ------------------------------------------------------------

    /**
     * Durable, automated replacement for the checkpoint's manual
     * rollback/reapplication verification (which was previously only
     * performed once by hand via direct psql queries against pg_class
     * around `artisan migrate:rollback`/`migrate`, and never captured
     * as a repeatable test).
     *
     * This targets the Checkpoint 2 migration file explicitly via
     * `--path` (not a bare `--step=1`) so the test keeps proving the
     * right thing even if a later migration is added after this one —
     * `--step=1` would silently start rolling back whatever migration
     * happens to be most-recently-applied at that point, which is not
     * what this test is meant to prove.
     *
     * Safety note on running DDL mid-test under RefreshDatabase: this
     * class's tests each run inside a real outer PostgreSQL transaction
     * (RefreshDatabase migrates once for the whole run, then wraps each
     * test method in `$connection->beginTransaction()` / `rollBack()`).
     * PostgreSQL — unlike MySQL — supports fully transactional DDL, and
     * Laravel's own Migrator wraps each migration's up()/down() in
     * `$connection->transaction()` whenever
     * `$grammar->supportsSchemaTransactions()` is true (true for pgsql)
     * and the migration doesn't opt out; because the outer test
     * transaction is already open, that inner call becomes a SAVEPOINT,
     * not a second top-level transaction. So the DROP TABLE (rollback)
     * and CREATE TABLE + INSERT (reapply) performed by this test happen
     * entirely inside the test's own outer transaction and are fully
     * undone by RefreshDatabase's normal end-of-test `rollBack()` —
     * exactly like any other write a test makes. No other test in this
     * class (or process) observes the table missing, and the table is
     * guaranteed to exist again after this test regardless of how it
     * ends, because rollback of the *outer* transaction — not any
     * cleanup code in this method — is what restores it. This was
     * confirmed empirically against a disposable database (running
     * this test alongside the full class) before being finalized here.
     */
    /**
     * NARROW, DISCLOSED UPDATE (post-Checkpoint-3): this test originally
     * assumed integration_providers had no dependents and could be
     * rolled back in true isolation. Checkpoint 3 — a later, legitimate
     * schema addition — introduced firm_integrations.integration_provider_id
     * as a real FK (restrictOnDelete()) pointing at integration_providers,
     * so integration_providers can no longer be dropped/rolled back while
     * firm_integrations still exists; testing its reversibility in true
     * isolation is no longer physically possible. This test now rolls
     * back firm_integrations' two migrations first (in FK-dependency /
     * reverse-chronological order: its RLS-prep migration, then its
     * create-table migration), then integration_providers, and reapplies
     * in forward order. This is a strengthening of the test, not a
     * weakening: it now proves cross-table rollback safety, and asserts
     * firm_integrations itself ends up correctly restored too (table
     * exists again, its own RLS/FORCE state and policy are correct) as a
     * natural side-effect proof, not merely that integration_providers
     * round-trips.
     */
    /**
     * NARROW, DISCLOSED UPDATE (post-Checkpoint-4): Checkpoint 4 extended
     * the same FK chain one level further — integration_credentials
     * carries a real composite FK (firm_id, firm_integration_id) ->
     * firm_integrations(firm_id, id) with cascadeOnDelete(), so
     * firm_integrations can no longer be dropped/rolled back while
     * integration_credentials still exists either. This test now rolls
     * back integration_credentials' two migrations FIRST (in FK-dependency
     * / reverse-chronological order: its RLS-prep migration, then its
     * create-table migration), then firm_integrations' own two migrations,
     * then integration_providers, and reapplies in forward order
     * (integration_providers, then firm_integrations, then
     * integration_credentials). This is a further strengthening of the
     * test, not a weakening: it now proves cross-table rollback safety
     * three tables deep, and asserts integration_credentials itself ends
     * up correctly restored too, exactly as it already did for
     * firm_integrations.
     */
    /**
     * NARROW, DISCLOSED UPDATE (post-Checkpoint-5): Checkpoint 5
     * introduced a SECOND, independent real dependent of firm_integrations
     * at the same level as integration_credentials —
     * integration_oauth_states — carrying the identical composite-FK
     * shape (firm_id, firm_integration_id) -> firm_integrations(firm_id,
     * id) with cascadeOnDelete(). This test now also rolls back
     * integration_oauth_states' two migrations FIRST, alongside
     * integration_credentials' (order between the two does not matter to
     * each other, since neither references the other — only each
     * independently references firm_integrations), and reapplies it after
     * integration_credentials in forward order. This is a further
     * strengthening of the test, not a weakening: it now proves
     * cross-table rollback safety for BOTH of firm_integrations' real
     * dependents, and asserts integration_oauth_states itself ends up
     * correctly restored too, exactly as it already did for
     * integration_credentials.
     *
     * NARROW, DISCLOSED UPDATE (post-Checkpoint-6): Checkpoint 6 added a
     * THIRD independent dependency chain on firm_integrations — the
     * 6-table / 12-migration Checkpoint 6 wave (see
     * CP6_WHOLE_WAVE_MIGRATION_PATHS above). This test now also rolls
     * back that entire wave (whole-wave order required internally —
     * integration_sync_items and integration_conflicts are themselves
     * composite-FK dependents of other CP6 tables, not just of
     * firm_integrations) before firm_integrations' own rollback, and
     * reapplies it in forward order afterward. Order between this wave
     * and the pre-existing integration_credentials /
     * integration_oauth_states blocks does not matter to each other.
     */
    public function test_migration_rollback_and_reapplication_restores_exact_prior_state(): void
    {
        $migrationFile = 'database/migrations/2026_09_01_010001_create_integration_providers_table.php';
        $migrationName = '2026_09_01_010001_create_integration_providers_table';

        $firmIntegrationsCreateFile = 'database/migrations/2026_09_02_020001_create_firm_integrations_table.php';
        $firmIntegrationsCreateName = '2026_09_02_020001_create_firm_integrations_table';
        $firmIntegrationsRlsFile = 'database/migrations/2026_09_02_020002_prepare_row_level_security_and_force_rls_on_firm_integrations_table.php';
        $firmIntegrationsRlsName = '2026_09_02_020002_prepare_row_level_security_and_force_rls_on_firm_integrations_table';

        $integrationCredentialsCreateFile = 'database/migrations/2026_09_03_030001_create_integration_credentials_table.php';
        $integrationCredentialsCreateName = '2026_09_03_030001_create_integration_credentials_table';
        $integrationCredentialsRlsFile = 'database/migrations/2026_09_03_030002_prepare_row_level_security_and_force_rls_on_integration_credentials_table.php';
        $integrationCredentialsRlsName = '2026_09_03_030002_prepare_row_level_security_and_force_rls_on_integration_credentials_table';

        $oauthStatesCreateFile = 'database/migrations/2026_09_04_040001_create_integration_oauth_states_table.php';
        $oauthStatesCreateName = '2026_09_04_040001_create_integration_oauth_states_table';
        $oauthStatesRlsFile = 'database/migrations/2026_09_04_040002_prepare_row_level_security_and_force_rls_on_integration_oauth_states_table.php';
        $oauthStatesRlsName = '2026_09_04_040002_prepare_row_level_security_and_force_rls_on_integration_oauth_states_table';

        $this->assertFileExists(
            base_path($migrationFile),
            'This test targets the Checkpoint 2 migration by an explicit path — the file must exist at the expected location.'
        );

        // 1. Confirm current state: the table exists with exactly the
        // documented seed row, and the migration is recorded as run.
        $this->assertTrue(Schema::hasTable('integration_providers'));

        $before = DB::table('integration_providers')->where('code', 'test')->first();
        $this->assertNotNull($before);
        $this->assertSame('test', $before->code);
        $this->assertSame('Internal Test Provider (non-production)', $before->display_name);

        $this->assertNotNull(
            DB::table('migrations')->where('migration', $migrationName)->first(),
            'The Checkpoint 2 migration must already be recorded as run before this test can prove rollback/reapply.'
        );

        // Confirm firm_integrations' own pre-rollback state too, so its
        // restoration can be proven later, not just asserted by absence
        // of error.
        $this->assertTrue(
            Schema::hasTable('firm_integrations'),
            'firm_integrations (Checkpoint 3) must exist before this test begins, since it is now integration_providers\' one real FK dependent.'
        );
        $firmIntegrationsRlsBefore = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'firm_integrations'");
        $this->assertNotNull($firmIntegrationsRlsBefore);
        $this->assertTrue((bool) $firmIntegrationsRlsBefore->relrowsecurity);
        $this->assertTrue((bool) $firmIntegrationsRlsBefore->relforcerowsecurity);

        // Confirm integration_credentials' own pre-rollback state too
        // (Checkpoint 4 — one level further down the same FK chain).
        $this->assertTrue(
            Schema::hasTable('integration_credentials'),
            'integration_credentials (Checkpoint 4) must exist before this test begins, since it is now firm_integrations\' one real FK dependent.'
        );
        $integrationCredentialsRlsBefore = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'integration_credentials'");
        $this->assertNotNull($integrationCredentialsRlsBefore);
        $this->assertTrue((bool) $integrationCredentialsRlsBefore->relrowsecurity);
        $this->assertTrue((bool) $integrationCredentialsRlsBefore->relforcerowsecurity);

        // Confirm integration_oauth_states' own pre-rollback state too
        // (Checkpoint 5 — a second, independent dependent at the same
        // level as integration_credentials).
        $this->assertTrue(
            Schema::hasTable('integration_oauth_states'),
            'integration_oauth_states (Checkpoint 5) must exist before this test begins, since it is now also one of firm_integrations\' real FK dependents.'
        );
        $oauthStatesRlsBefore = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'integration_oauth_states'");
        $this->assertNotNull($oauthStatesRlsBefore);
        $this->assertTrue((bool) $oauthStatesRlsBefore->relrowsecurity);
        $this->assertTrue((bool) $oauthStatesRlsBefore->relforcerowsecurity);

        // Confirm the Checkpoint 6 whole-wave tables' pre-rollback
        // existence too (a THIRD, independent dependency chain on
        // firm_integrations).
        foreach (self::CP6_WHOLE_WAVE_MIGRATION_PATHS as $path) {
            $this->assertFileExists(base_path($path));
        }
        foreach (self::CP6_WHOLE_WAVE_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} (Checkpoint 6) must exist before this test begins, since it is now also one of firm_integrations' real (direct or transitive) FK dependents.");
        }

        // Confirm the Checkpoint 7 whole-wave tables' pre-rollback
        // existence too (a FOURTH, independent dependency chain reaching
        // integration_providers directly via
        // integration_webhook_routing_index), plus the column it adds to
        // the Checkpoint 6 integration_sync_runs table.
        foreach (self::CP7_WHOLE_WAVE_MIGRATION_PATHS as $path) {
            $this->assertFileExists(base_path($path));
        }
        foreach (self::CP7_WHOLE_WAVE_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} (Checkpoint 7) must exist before this test begins, since it is now also one of integration_providers' real (direct or transitive) FK dependents.");
        }
        $this->assertTrue(
            Schema::hasColumn('integration_sync_runs', 'triggering_webhook_event_id'),
            'integration_sync_runs.triggering_webhook_event_id (Checkpoint 7) must exist before this test begins.'
        );

        // Confirm the Checkpoint 8 whole-wave tables' pre-rollback
        // existence too (a FIFTH, independent dependency chain reaching
        // firm_integrations, transitively integration_providers).
        foreach (self::CP8_WHOLE_WAVE_MIGRATION_PATHS as $path) {
            $this->assertFileExists(base_path($path));
        }
        foreach (self::CP8_WHOLE_WAVE_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} (Checkpoint 8) must exist before this test begins, since it is now also one of firm_integrations' real (direct or transitive) FK dependents.");
        }

        // Confirm the Checkpoint 9 whole-wave table's pre-rollback
        // existence too (a SIXTH, independent dependency chain reaching
        // firm_integrations, transitively integration_providers, plus
        // integration_sync_runs/integration_sync_items/
        // integration_inbound_webhook_events).
        foreach (self::CP9_WHOLE_WAVE_MIGRATION_PATHS as $path) {
            $this->assertFileExists(base_path($path));
        }
        foreach (self::CP9_WHOLE_WAVE_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} (Checkpoint 9) must exist before this test begins, since it is now also one of firm_integrations' real (direct or transitive) FK dependents.");
        }

        // Confirm the Checkpoint 2 (FirmsVault Live Integrations,
        // Microsoft 365 provider) webhook-subscriptions whole-wave
        // table's pre-rollback existence too (a SEVENTH, independent
        // dependency chain reaching firm_integrations, transitively
        // integration_providers — the newest layer of all).
        foreach (self::CP2_WEBHOOK_SUBSCRIPTIONS_WHOLE_WAVE_MIGRATION_PATHS as $path) {
            $this->assertFileExists(base_path($path));
        }
        foreach (self::CP2_WEBHOOK_SUBSCRIPTIONS_WHOLE_WAVE_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} (Checkpoint 2 webhook subscriptions) must exist before this test begins, since it is now also one of firm_integrations' real (direct or transitive) FK dependents.");
        }

        // Confirm the Checkpoint 3 (FirmsVault Live Integrations, Google
        // Workspace provider) gmail-mailbox-routes whole-wave table's
        // pre-rollback existence too (an EIGHTH, independent dependency
        // chain reaching integration_providers directly — the newest
        // layer of all).
        foreach (self::CP3_GMAIL_MAILBOX_ROUTES_WHOLE_WAVE_MIGRATION_PATHS as $path) {
            $this->assertFileExists(base_path($path));
        }
        foreach (self::CP3_GMAIL_MAILBOX_ROUTES_WHOLE_WAVE_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} (Checkpoint 3 gmail mailbox routes) must exist before this test begins, since it is now also one of integration_providers' real (direct or transitive) FK dependents.");
        }

        // 1a-pre-pre-pre-pre. Roll back the Checkpoint 3 gmail-mailbox-
        // routes whole-wave dependency chain FIRST — before the Checkpoint
        // 2 webhook-subscriptions whole-wave block below, since this wave
        // is the newest layer of all (dated 2026_09_23, after Checkpoint
        // 2's own 2026_09_22) — see
        // CP3_GMAIL_MAILBOX_ROUTES_WHOLE_WAVE_MIGRATION_PATHS docblock.
        // Rolled back in exact reverse of its own creation order (a single
        // migration, so this is just its own down()).
        foreach (array_reverse(self::CP3_GMAIL_MAILBOX_ROUTES_WHOLE_WAVE_MIGRATION_PATHS) as $path) {
            $exit = Artisan::call('migrate:rollback', ['--path' => $path, '--force' => true]);
            $this->assertSame(0, $exit, "migrate:rollback of {$path} (Checkpoint 3 gmail mailbox routes whole-wave) failed: ".Artisan::output());
        }
        foreach (self::CP3_GMAIL_MAILBOX_ROUTES_WHOLE_WAVE_TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table), "{$table} (Checkpoint 3 gmail mailbox routes) must not survive its whole-wave rollback.");
        }

        // 1a-pre-pre-pre. Roll back the Checkpoint 2 webhook-subscriptions
        // whole-wave dependency chain FIRST — before the Checkpoint 9
        // whole-wave block below, since this wave is the newest layer of
        // all (dated 2026_09_22, after Checkpoint 9's 2026_09_08) — see
        // CP2_WEBHOOK_SUBSCRIPTIONS_WHOLE_WAVE_MIGRATION_PATHS docblock.
        // Rolled back in exact reverse of its own creation order.
        foreach (array_reverse(self::CP2_WEBHOOK_SUBSCRIPTIONS_WHOLE_WAVE_MIGRATION_PATHS) as $path) {
            $exit = Artisan::call('migrate:rollback', ['--path' => $path, '--force' => true]);
            $this->assertSame(0, $exit, "migrate:rollback of {$path} (Checkpoint 2 webhook subscriptions whole-wave) failed: ".Artisan::output());
        }
        foreach (self::CP2_WEBHOOK_SUBSCRIPTIONS_WHOLE_WAVE_TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table), "{$table} (Checkpoint 2 webhook subscriptions) must not survive its whole-wave rollback.");
        }

        // 1a-pre-pre-pre. Roll back Checkpoint 4's
        // provider_billable_call_reservations FK-dependent FIRST — before
        // the Checkpoint 9 whole-wave block below drops
        // integration_usage_records itself (see
        // CP4_WHOLE_WAVE_MIGRATION_PATHS docblock).
        foreach (array_reverse(self::CP4_WHOLE_WAVE_MIGRATION_PATHS) as $path) {
            $exit = Artisan::call('migrate:rollback', ['--path' => $path, '--force' => true]);
            $this->assertSame(0, $exit, "migrate:rollback of {$path} (Checkpoint 4 provider_billable_call_reservations) failed: ".Artisan::output());
        }
        foreach (self::CP4_WHOLE_WAVE_TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table), "{$table} (Checkpoint 4) must not survive its rollback.");
        }

        // 1a-pre-pre. Roll back the Checkpoint 9 whole-wave dependency
        // chain FIRST — before the Checkpoint 8 whole-wave block below,
        // since Checkpoint 9 is the newest layer (see
        // CP9_WHOLE_WAVE_MIGRATION_PATHS docblock). Rolled back in exact
        // reverse of its own creation order.
        foreach (array_reverse(self::CP9_WHOLE_WAVE_MIGRATION_PATHS) as $path) {
            $exit = Artisan::call('migrate:rollback', ['--path' => $path, '--force' => true]);
            $this->assertSame(0, $exit, "migrate:rollback of {$path} (Checkpoint 9 whole-wave) failed: ".Artisan::output());
        }
        foreach (self::CP9_WHOLE_WAVE_TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table), "{$table} (Checkpoint 9) must not survive its whole-wave rollback.");
        }

        // 1a-pre. Roll back the Checkpoint 8 whole-wave dependency chain
        // FIRST — before the Checkpoint 7 whole-wave block below, since
        // Checkpoint 8 is the newest layer (see
        // CP8_WHOLE_WAVE_MIGRATION_PATHS docblock). Rolled back in exact
        // reverse of its own creation order.
        foreach (array_reverse(self::CP8_WHOLE_WAVE_MIGRATION_PATHS) as $path) {
            $exit = Artisan::call('migrate:rollback', ['--path' => $path, '--force' => true]);
            $this->assertSame(0, $exit, "migrate:rollback of {$path} (Checkpoint 8 whole-wave) failed: ".Artisan::output());
        }
        foreach (self::CP8_WHOLE_WAVE_TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table), "{$table} (Checkpoint 8) must not survive its whole-wave rollback.");
        }

        // 1a. Roll back the Checkpoint 7 whole-wave dependency chain
        // FIRST — before the Checkpoint 6 whole-wave block below, since
        // Checkpoint 7 is the newest layer and its own trailing
        // add_triggering_webhook_event_id_to_integration_sync_runs_table
        // migration ALTERs the Checkpoint 6 integration_sync_runs table
        // directly and must be undone before that table is dropped by the
        // Checkpoint 6 whole-wave rollback (see CP7_WHOLE_WAVE_MIGRATION_PATHS
        // docblock). It also must be fully torn down before
        // integration_webhook_routing_index's direct FK into
        // integration_providers can be safely dropped. Internal FK order
        // matters within this wave itself, so it is rolled back as a
        // unit, in exact reverse of its own creation order.
        foreach (array_reverse(self::CP7_WHOLE_WAVE_MIGRATION_PATHS) as $path) {
            $exit = Artisan::call('migrate:rollback', ['--path' => $path, '--force' => true]);
            $this->assertSame(0, $exit, "migrate:rollback of {$path} (Checkpoint 7 whole-wave) failed: ".Artisan::output());
        }
        foreach (self::CP7_WHOLE_WAVE_TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table), "{$table} (Checkpoint 7) must not survive its whole-wave rollback.");
        }
        $this->assertFalse(
            Schema::hasColumn('integration_sync_runs', 'triggering_webhook_event_id'),
            'integration_sync_runs.triggering_webhook_event_id must not survive the Checkpoint 7 whole-wave rollback.'
        );

        // 1b. Roll back the Checkpoint 6 whole-wave dependency chain —
        // internal FK order matters within the wave itself (see
        // CP6_WHOLE_WAVE_MIGRATION_PATHS docblock), so it is rolled back
        // as a unit, in exact reverse of its own creation order, before
        // firm_integrations' other dependents or firm_integrations
        // itself.
        foreach (array_reverse(self::CP6_WHOLE_WAVE_MIGRATION_PATHS) as $path) {
            $exit = Artisan::call('migrate:rollback', ['--path' => $path, '--force' => true]);
            $this->assertSame(0, $exit, "migrate:rollback of {$path} (Checkpoint 6 whole-wave) failed: ".Artisan::output());
        }
        foreach (self::CP6_WHOLE_WAVE_TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table), "{$table} (Checkpoint 6) must not survive its whole-wave rollback.");
        }

        // 2. Roll back firm_integrations' real dependents first —
        // integration_credentials and integration_oauth_states (order
        // between the two does not matter to each other) — each in
        // FK-dependency / reverse-chronological order (its RLS-prep
        // migration, then its create-table migration) — since both hold
        // a real composite FK (cascadeOnDelete()) against
        // firm_integrations.
        $credentialsRlsRollbackExit = Artisan::call('migrate:rollback', [
            '--path' => $integrationCredentialsRlsFile,
            '--force' => true,
        ]);
        $this->assertSame(0, $credentialsRlsRollbackExit, 'migrate:rollback of integration_credentials RLS-prep migration failed: '.Artisan::output());

        $credentialsCreateRollbackExit = Artisan::call('migrate:rollback', [
            '--path' => $integrationCredentialsCreateFile,
            '--force' => true,
        ]);
        $this->assertSame(0, $credentialsCreateRollbackExit, 'migrate:rollback of integration_credentials create-table migration failed: '.Artisan::output());

        $this->assertFalse(
            Schema::hasTable('integration_credentials'),
            'integration_credentials must be fully rolled back before firm_integrations can be safely rolled back.'
        );

        $oauthStatesRlsRollbackExit = Artisan::call('migrate:rollback', [
            '--path' => $oauthStatesRlsFile,
            '--force' => true,
        ]);
        $this->assertSame(0, $oauthStatesRlsRollbackExit, 'migrate:rollback of integration_oauth_states RLS-prep migration failed: '.Artisan::output());

        $oauthStatesCreateRollbackExit = Artisan::call('migrate:rollback', [
            '--path' => $oauthStatesCreateFile,
            '--force' => true,
        ]);
        $this->assertSame(0, $oauthStatesCreateRollbackExit, 'migrate:rollback of integration_oauth_states create-table migration failed: '.Artisan::output());

        $this->assertFalse(
            Schema::hasTable('integration_oauth_states'),
            'integration_oauth_states must be fully rolled back before firm_integrations can be safely rolled back.'
        );

        // 3. Roll back integration_providers' one real dependent,
        // firm_integrations, next — in FK-dependency / reverse-
        // chronological order (its RLS-prep migration, then its
        // create-table migration) — since firm_integrations holds a real
        // FK (restrictOnDelete()) against integration_providers.
        $rlsRollbackExit = Artisan::call('migrate:rollback', [
            '--path' => $firmIntegrationsRlsFile,
            '--force' => true,
        ]);
        $this->assertSame(0, $rlsRollbackExit, 'migrate:rollback of firm_integrations RLS-prep migration failed: '.Artisan::output());

        $createRollbackExit = Artisan::call('migrate:rollback', [
            '--path' => $firmIntegrationsCreateFile,
            '--force' => true,
        ]);
        $this->assertSame(0, $createRollbackExit, 'migrate:rollback of firm_integrations create-table migration failed: '.Artisan::output());

        $this->assertFalse(
            Schema::hasTable('firm_integrations'),
            'firm_integrations must be fully rolled back before integration_providers can be safely rolled back.'
        );

        // 4. Roll back exactly this migration — targeted unambiguously
        // by --path, not a bare --step=1.
        $rollbackExit = Artisan::call('migrate:rollback', [
            '--path' => $migrationFile,
            '--force' => true,
        ]);
        $this->assertSame(0, $rollbackExit, 'migrate:rollback failed: '.Artisan::output());

        // 5. The table must be gone — verified both via the schema
        // builder and directly against the PostgreSQL catalog.
        $this->assertFalse(
            Schema::hasTable('integration_providers'),
            'migrate:rollback targeted at the Checkpoint 2 migration must drop integration_providers.'
        );
        $this->assertNull(
            DB::selectOne("select relname from pg_class where relname = 'integration_providers'"),
            'integration_providers must be fully absent from the PostgreSQL catalog after rollback, not merely hidden from the schema builder.'
        );
        $this->assertNull(
            DB::table('migrations')->where('migration', $migrationName)->first(),
            'The rolled-back migration must no longer be recorded in the migrations table.'
        );

        // 6. Reapply in forward order: integration_providers first, then
        // firm_integrations' two migrations (create-table, then its
        // RLS-prep migration), then integration_credentials' two
        // migrations (create-table, then its RLS-prep migration).
        $migrateExit = Artisan::call('migrate', [
            '--path' => $migrationFile,
            '--force' => true,
        ]);
        $this->assertSame(0, $migrateExit, 'migrate failed: '.Artisan::output());

        $createMigrateExit = Artisan::call('migrate', [
            '--path' => $firmIntegrationsCreateFile,
            '--force' => true,
        ]);
        $this->assertSame(0, $createMigrateExit, 'migrate of firm_integrations create-table migration failed: '.Artisan::output());

        $rlsMigrateExit = Artisan::call('migrate', [
            '--path' => $firmIntegrationsRlsFile,
            '--force' => true,
        ]);
        $this->assertSame(0, $rlsMigrateExit, 'migrate of firm_integrations RLS-prep migration failed: '.Artisan::output());

        $credentialsCreateMigrateExit = Artisan::call('migrate', [
            '--path' => $integrationCredentialsCreateFile,
            '--force' => true,
        ]);
        $this->assertSame(0, $credentialsCreateMigrateExit, 'migrate of integration_credentials create-table migration failed: '.Artisan::output());

        $credentialsRlsMigrateExit = Artisan::call('migrate', [
            '--path' => $integrationCredentialsRlsFile,
            '--force' => true,
        ]);
        $this->assertSame(0, $credentialsRlsMigrateExit, 'migrate of integration_credentials RLS-prep migration failed: '.Artisan::output());

        $oauthStatesCreateMigrateExit = Artisan::call('migrate', [
            '--path' => $oauthStatesCreateFile,
            '--force' => true,
        ]);
        $this->assertSame(0, $oauthStatesCreateMigrateExit, 'migrate of integration_oauth_states create-table migration failed: '.Artisan::output());

        $oauthStatesRlsMigrateExit = Artisan::call('migrate', [
            '--path' => $oauthStatesRlsFile,
            '--force' => true,
        ]);
        $this->assertSame(0, $oauthStatesRlsMigrateExit, 'migrate of integration_oauth_states RLS-prep migration failed: '.Artisan::output());

        // 6b. Reapply the Checkpoint 6 whole-wave dependency chain last,
        // in its own forward (creation) order.
        foreach (self::CP6_WHOLE_WAVE_MIGRATION_PATHS as $path) {
            $exit = Artisan::call('migrate', ['--path' => $path, '--force' => true]);
            $this->assertSame(0, $exit, "migrate of {$path} (Checkpoint 6 whole-wave) failed: ".Artisan::output());
        }
        foreach (self::CP6_WHOLE_WAVE_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} (Checkpoint 6) must be restored by the whole-wave reapplication.");
        }

        // 6c. Reapply the Checkpoint 7 whole-wave dependency chain LAST —
        // after the Checkpoint 6 whole-wave block, since its trailing
        // migration needs integration_sync_runs (just recreated above) to
        // already exist — in its own forward (creation) order.
        foreach (self::CP7_WHOLE_WAVE_MIGRATION_PATHS as $path) {
            $exit = Artisan::call('migrate', ['--path' => $path, '--force' => true]);
            $this->assertSame(0, $exit, "migrate of {$path} (Checkpoint 7 whole-wave) failed: ".Artisan::output());
        }
        foreach (self::CP7_WHOLE_WAVE_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} (Checkpoint 7) must be restored by the whole-wave reapplication.");
        }
        $this->assertTrue(
            Schema::hasColumn('integration_sync_runs', 'triggering_webhook_event_id'),
            'integration_sync_runs.triggering_webhook_event_id must be restored by the Checkpoint 7 whole-wave reapplication.'
        );

        // 6d. Reapply the Checkpoint 8 whole-wave dependency chain LAST —
        // after the Checkpoint 7 whole-wave block, since Checkpoint 8 is
        // the newest layer — in its own forward (creation) order.
        foreach (self::CP8_WHOLE_WAVE_MIGRATION_PATHS as $path) {
            $exit = Artisan::call('migrate', ['--path' => $path, '--force' => true]);
            $this->assertSame(0, $exit, "migrate of {$path} (Checkpoint 8 whole-wave) failed: ".Artisan::output());
        }
        foreach (self::CP8_WHOLE_WAVE_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} (Checkpoint 8) must be restored by the whole-wave reapplication.");
        }

        // 6e. Reapply the Checkpoint 9 whole-wave dependency chain LAST —
        // after the Checkpoint 8 whole-wave block, since Checkpoint 9 is
        // the newest layer, and after integration_sync_runs/
        // integration_sync_items/integration_inbound_webhook_events
        // already exist again — in its own forward (creation) order.
        foreach (self::CP9_WHOLE_WAVE_MIGRATION_PATHS as $path) {
            $exit = Artisan::call('migrate', ['--path' => $path, '--force' => true]);
            $this->assertSame(0, $exit, "migrate of {$path} (Checkpoint 9 whole-wave) failed: ".Artisan::output());
        }
        foreach (self::CP9_WHOLE_WAVE_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} (Checkpoint 9) must be restored by the whole-wave reapplication.");
        }

        // 6e-bis. Reapply Checkpoint 4's provider_billable_call_reservations
        // LAST of all — after integration_usage_records already exists
        // again (see CP4_WHOLE_WAVE_MIGRATION_PATHS
        // docblock).
        foreach (self::CP4_WHOLE_WAVE_MIGRATION_PATHS as $path) {
            $exit = Artisan::call('migrate', ['--path' => $path, '--force' => true]);
            $this->assertSame(0, $exit, "migrate of {$path} (Checkpoint 4 provider_billable_call_reservations) failed: ".Artisan::output());
        }
        foreach (self::CP4_WHOLE_WAVE_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} (Checkpoint 4) must be restored by reapplication.");
        }

        // 6f. Reapply the Checkpoint 2 webhook-subscriptions whole-wave
        // dependency chain LAST — after the Checkpoint 9 whole-wave block,
        // since this wave is the newest layer other than Checkpoint 3, and
        // after firm_integrations (just recreated above) already exists
        // again — in its own forward (creation) order.
        foreach (self::CP2_WEBHOOK_SUBSCRIPTIONS_WHOLE_WAVE_MIGRATION_PATHS as $path) {
            $exit = Artisan::call('migrate', ['--path' => $path, '--force' => true]);
            $this->assertSame(0, $exit, "migrate of {$path} (Checkpoint 2 webhook subscriptions whole-wave) failed: ".Artisan::output());
        }
        foreach (self::CP2_WEBHOOK_SUBSCRIPTIONS_WHOLE_WAVE_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} (Checkpoint 2 webhook subscriptions) must be restored by the whole-wave reapplication.");
        }

        // 6g. Reapply the Checkpoint 3 gmail-mailbox-routes whole-wave
        // dependency chain LAST of all — after the Checkpoint 2
        // webhook-subscriptions whole-wave block, since this wave is the
        // newest layer of all, and after integration_providers (just
        // recreated above) already exists again — in its own forward
        // (creation) order (a single migration, so this is just its own
        // up()).
        foreach (self::CP3_GMAIL_MAILBOX_ROUTES_WHOLE_WAVE_MIGRATION_PATHS as $path) {
            $exit = Artisan::call('migrate', ['--path' => $path, '--force' => true]);
            $this->assertSame(0, $exit, "migrate of {$path} (Checkpoint 3 gmail mailbox routes whole-wave) failed: ".Artisan::output());
        }
        foreach (self::CP3_GMAIL_MAILBOX_ROUTES_WHOLE_WAVE_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} (Checkpoint 3 gmail mailbox routes) must be restored by the whole-wave reapplication.");
        }

        // 7. Assert the exact prior state is restored — same columns,
        // same single seed row with the same values — not merely "a
        // table now exists."
        $this->assertTrue(Schema::hasTable('integration_providers'));

        $columns = Schema::getColumnListing('integration_providers');
        sort($columns);
        $expectedColumns = self::EXPECTED_COLUMNS;
        sort($expectedColumns);
        $this->assertSame(
            $expectedColumns,
            $columns,
            'Reapplying the migration must restore exactly the documented column set.'
        );

        // POST-CHECKPOINT-4-PLAID UPDATE: was assertSame(1, ...) — the
        // Microsoft365/GoogleWorkspace seed migrations are NOT part of
        // any whole-wave list this test rolls back/reapplies, so their
        // seed rows are wiped by integration_providers' own drop-and-
        // recreate above and never reappear. Checkpoint 4's own seed
        // migration (2026_09_24_500011_seed_plaid_module_catalog_entry.php)
        // IS part of CP4_WHOLE_WAVE_MIGRATION_PATHS, explicitly rolled
        // back and reapplied by this same test — so its 'plaid' row IS
        // genuinely re-seeded, making 2 (test + plaid) the correct
        // post-round-trip count, not 1.
        $this->assertSame(2, DB::table('integration_providers')->count());

        $after = DB::table('integration_providers')->where('code', 'test')->first();
        $this->assertNotNull($after);
        $this->assertSame($before->code, $after->code);
        $this->assertSame($before->display_name, $after->display_name);
        $this->assertSame($before->category, $after->category);
        $this->assertSame($before->auth_method, $after->auth_method);
        $this->assertSame($before->status, $after->status);
        $this->assertNull($after->module_code);
        $this->assertNull($after->degradation_type_key);

        $this->assertNotNull(
            DB::table('migrations')->where('migration', $migrationName)->first(),
            'The reapplied migration must be recorded as run again.'
        );

        // 8. No RLS ever gets silently (re)applied to this Global table
        // by the rollback/reapply cycle either.
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'integration_providers'");
        $this->assertNotNull($row);
        $this->assertFalse((bool) $row->relrowsecurity);

        // 9. Prove firm_integrations itself is correctly restored too, as
        // a natural side-effect of proving cross-table rollback safety —
        // not just that integration_providers round-trips.
        $this->assertTrue(
            Schema::hasTable('firm_integrations'),
            'firm_integrations must be fully restored after reapplying its two migrations.'
        );
        $this->assertTrue(Schema::hasColumn('firm_integrations', 'integration_provider_id'));

        $firmIntegrationsRlsAfter = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'firm_integrations'");
        $this->assertNotNull($firmIntegrationsRlsAfter);
        $this->assertTrue(
            (bool) $firmIntegrationsRlsAfter->relrowsecurity,
            'firm_integrations RLS must be re-enabled after reapplying its RLS-prep migration.'
        );
        $this->assertTrue(
            (bool) $firmIntegrationsRlsAfter->relforcerowsecurity,
            'firm_integrations FORCE RLS must be re-enabled after reapplying its RLS-prep migration.'
        );

        $firmIntegrationsPolicies = DB::select("select policyname from pg_policies where tablename = 'firm_integrations'");
        $this->assertCount(1, $firmIntegrationsPolicies);
        $this->assertSame('firm_integrations_tenant_isolation', $firmIntegrationsPolicies[0]->policyname);

        $this->assertNotNull(
            DB::table('migrations')->where('migration', $firmIntegrationsCreateName)->first(),
            'The reapplied firm_integrations create-table migration must be recorded as run again.'
        );
        $this->assertNotNull(
            DB::table('migrations')->where('migration', $firmIntegrationsRlsName)->first(),
            'The reapplied firm_integrations RLS-prep migration must be recorded as run again.'
        );

        // 10. Prove integration_credentials itself is correctly restored
        // too (Checkpoint 4 — one level further down the same FK chain),
        // as a natural side-effect proof, not merely that firm_integrations
        // round-trips.
        $this->assertTrue(
            Schema::hasTable('integration_credentials'),
            'integration_credentials must be fully restored after reapplying its two migrations.'
        );
        $this->assertTrue(Schema::hasColumn('integration_credentials', 'firm_integration_id'));

        $integrationCredentialsRlsAfter = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'integration_credentials'");
        $this->assertNotNull($integrationCredentialsRlsAfter);
        $this->assertTrue(
            (bool) $integrationCredentialsRlsAfter->relrowsecurity,
            'integration_credentials RLS must be re-enabled after reapplying its RLS-prep migration.'
        );
        $this->assertTrue(
            (bool) $integrationCredentialsRlsAfter->relforcerowsecurity,
            'integration_credentials FORCE RLS must be re-enabled after reapplying its RLS-prep migration.'
        );

        $integrationCredentialsPolicies = DB::select("select policyname from pg_policies where tablename = 'integration_credentials'");
        $this->assertCount(1, $integrationCredentialsPolicies);
        $this->assertSame('integration_credentials_tenant_isolation', $integrationCredentialsPolicies[0]->policyname);

        $this->assertNotNull(
            DB::table('migrations')->where('migration', $integrationCredentialsCreateName)->first(),
            'The reapplied integration_credentials create-table migration must be recorded as run again.'
        );
        $this->assertNotNull(
            DB::table('migrations')->where('migration', $integrationCredentialsRlsName)->first(),
            'The reapplied integration_credentials RLS-prep migration must be recorded as run again.'
        );

        // 11. Prove integration_oauth_states itself is correctly restored
        // too (Checkpoint 5 — a second, independent dependent at the same
        // level as integration_credentials), as a natural side-effect
        // proof, not merely that firm_integrations/integration_credentials
        // round-trip.
        $this->assertTrue(
            Schema::hasTable('integration_oauth_states'),
            'integration_oauth_states must be fully restored after reapplying its two migrations.'
        );
        $this->assertTrue(Schema::hasColumn('integration_oauth_states', 'firm_integration_id'));

        $oauthStatesRlsAfter = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'integration_oauth_states'");
        $this->assertNotNull($oauthStatesRlsAfter);
        $this->assertTrue(
            (bool) $oauthStatesRlsAfter->relrowsecurity,
            'integration_oauth_states RLS must be re-enabled after reapplying its RLS-prep migration.'
        );
        $this->assertTrue(
            (bool) $oauthStatesRlsAfter->relforcerowsecurity,
            'integration_oauth_states FORCE RLS must be re-enabled after reapplying its RLS-prep migration.'
        );

        // integration_oauth_states carries TWO policies (base tenant
        // isolation + the narrow self-lookup carve-out) — unlike
        // integration_credentials' single policy.
        $oauthStatesPolicies = DB::select("select policyname from pg_policies where tablename = 'integration_oauth_states' order by policyname");
        $this->assertCount(2, $oauthStatesPolicies);
        $this->assertSame(
            ['integration_oauth_states_self_lookup', 'integration_oauth_states_tenant_isolation'],
            array_map(fn ($p) => $p->policyname, $oauthStatesPolicies)
        );

        $this->assertNotNull(
            DB::table('migrations')->where('migration', $oauthStatesCreateName)->first(),
            'The reapplied integration_oauth_states create-table migration must be recorded as run again.'
        );
        $this->assertNotNull(
            DB::table('migrations')->where('migration', $oauthStatesRlsName)->first(),
            'The reapplied integration_oauth_states RLS-prep migration must be recorded as run again.'
        );
    }

    /**
     * Second, narrower proof of reversibility that calls the migration
     * file's own up()/down() methods directly, bypassing Artisan's
     * migrate/migrate:rollback commands and the `migrations` tracking
     * table entirely. This still stays safely inside RefreshDatabase's
     * outer per-test transaction — PostgreSQL supports transactional
     * DDL, so the DROP TABLE and CREATE TABLE/INSERT performed here are
     * just more writes inside that same outer transaction, undone by
     * its normal end-of-test rollback regardless of how this method
     * itself ends.
     */
    /**
     * NARROW, DISCLOSED UPDATE (post-Checkpoint-3): as with the test
     * above, this method originally called integration_providers'
     * migration down()/up() in true isolation. Checkpoint 3 (a later,
     * legitimate schema addition) introduced
     * firm_integrations.integration_provider_id as a real FK
     * (restrictOnDelete()) against integration_providers, so its down()
     * now fails with a Postgres FK-dependency error while
     * firm_integrations still exists. This test now also invokes
     * firm_integrations' two migration objects directly — tearing them
     * down first, in FK-dependency / reverse-chronological order (RLS-
     * prep down(), then create-table down()) before integration_providers'
     * own down(), and building them back up in forward order after
     * integration_providers' own up() — and asserts firm_integrations
     * ends up correctly restored too (table exists again, its own
     * RLS/FORCE state and policy are correct). This is a strengthening
     * of the test (it now proves cross-table rollback safety at the
     * migration-object level too), not a weakening.
     */
    /**
     * NARROW, DISCLOSED UPDATE (post-Checkpoint-4): Checkpoint 4 extended
     * the same FK chain one level further — integration_credentials'
     * real composite FK (cascadeOnDelete()) against firm_integrations
     * means firm_integrations' own down() now fails while
     * integration_credentials still exists. This test now also invokes
     * integration_credentials' two migration objects directly — tearing
     * them down FIRST, in FK-dependency / reverse-chronological order
     * (RLS-prep down(), then create-table down()) before firm_integrations'
     * own down() calls, and building them back up in forward order after
     * firm_integrations' own up() calls — and asserts integration_credentials
     * ends up correctly restored too, exactly as it already did for
     * firm_integrations.
     */
    /**
     * NARROW, DISCLOSED UPDATE (post-Checkpoint-5): Checkpoint 5
     * introduced a SECOND, independent real dependent of firm_integrations
     * at the same level as integration_credentials — integration_oauth_states
     * — carrying the identical composite-FK shape. This test now also
     * tears down/rebuilds integration_oauth_states' two migration objects
     * directly, alongside integration_credentials' (order between the two
     * does not matter to each other), and asserts integration_oauth_states
     * ends up correctly restored too, exactly as it already did for
     * integration_credentials.
     */
    /**
     * NARROW, DISCLOSED UPDATE (post-Checkpoint-6): Checkpoint 6 added a
     * THIRD independent dependency chain on firm_integrations — the
     * 6-table / 12-migration Checkpoint 6 wave (see
     * CP6_WHOLE_WAVE_MIGRATION_PATHS above). This test now also includes
     * and tears down/rebuilds that entire wave's migration objects
     * directly, in the same whole-wave-order-required manner as
     * IntegrationSyncRunsForceRlsActivationTest's own direct-call proof,
     * before/after firm_integrations' own down()/up() calls. Order
     * between this wave and the pre-existing integration_credentials /
     * integration_oauth_states blocks does not matter to each other.
     */
    public function test_migration_down_and_up_restores_exact_prior_state(): void
    {
        $this->assertTrue(Schema::hasTable('integration_providers'));

        $before = DB::table('integration_providers')->where('code', 'test')->first();
        $this->assertNotNull($before, 'Expected the seeded test-provider row to exist before rollback.');

        $this->assertTrue(
            Schema::hasTable('firm_integrations'),
            'firm_integrations (Checkpoint 3) must exist before this test begins, since it is now integration_providers\' one real FK dependent.'
        );
        $firmIntegrationsRlsBefore = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'firm_integrations'");
        $this->assertNotNull($firmIntegrationsRlsBefore);
        $this->assertTrue((bool) $firmIntegrationsRlsBefore->relrowsecurity);
        $this->assertTrue((bool) $firmIntegrationsRlsBefore->relforcerowsecurity);

        $this->assertTrue(
            Schema::hasTable('integration_credentials'),
            'integration_credentials (Checkpoint 4) must exist before this test begins, since it is now firm_integrations\' one real FK dependent.'
        );
        $integrationCredentialsRlsBefore = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'integration_credentials'");
        $this->assertNotNull($integrationCredentialsRlsBefore);
        $this->assertTrue((bool) $integrationCredentialsRlsBefore->relrowsecurity);
        $this->assertTrue((bool) $integrationCredentialsRlsBefore->relforcerowsecurity);

        $this->assertTrue(
            Schema::hasTable('integration_oauth_states'),
            'integration_oauth_states (Checkpoint 5) must exist before this test begins, since it is now also one of firm_integrations\' real FK dependents.'
        );
        $oauthStatesRlsBefore = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'integration_oauth_states'");
        $this->assertNotNull($oauthStatesRlsBefore);
        $this->assertTrue((bool) $oauthStatesRlsBefore->relrowsecurity);
        $this->assertTrue((bool) $oauthStatesRlsBefore->relforcerowsecurity);

        foreach (self::CP6_WHOLE_WAVE_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} (Checkpoint 6) must exist before this test begins, since it is now also one of firm_integrations' real (direct or transitive) FK dependents.");
        }
        foreach (self::CP7_WHOLE_WAVE_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} (Checkpoint 7) must exist before this test begins, since it is now also one of integration_providers' real (direct or transitive) FK dependents.");
        }
        $this->assertTrue(
            Schema::hasColumn('integration_sync_runs', 'triggering_webhook_event_id'),
            'integration_sync_runs.triggering_webhook_event_id (Checkpoint 7) must exist before this test begins.'
        );
        foreach (self::CP8_WHOLE_WAVE_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} (Checkpoint 8) must exist before this test begins, since it is now also one of firm_integrations' real (direct or transitive) FK dependents.");
        }
        foreach (self::CP9_WHOLE_WAVE_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} (Checkpoint 9) must exist before this test begins, since it is now also one of firm_integrations' real (direct or transitive) FK dependents.");
        }
        foreach (self::CP2_WEBHOOK_SUBSCRIPTIONS_WHOLE_WAVE_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} (Checkpoint 2 webhook subscriptions) must exist before this test begins, since it is now also one of firm_integrations' real (direct or transitive) FK dependents.");
        }
        foreach (self::CP3_GMAIL_MAILBOX_ROUTES_WHOLE_WAVE_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} (Checkpoint 3 gmail mailbox routes) must exist before this test begins, since it is now also one of integration_providers' real (direct or transitive) FK dependents.");
        }

        $providersMigration = include database_path('migrations/2026_09_01_010001_create_integration_providers_table.php');
        $firmIntegrationsRlsMigration = include database_path('migrations/2026_09_02_020002_prepare_row_level_security_and_force_rls_on_firm_integrations_table.php');
        $firmIntegrationsCreateMigration = include database_path('migrations/2026_09_02_020001_create_firm_integrations_table.php');
        $credentialsRlsMigration = include database_path('migrations/2026_09_03_030002_prepare_row_level_security_and_force_rls_on_integration_credentials_table.php');
        $credentialsCreateMigration = include database_path('migrations/2026_09_03_030001_create_integration_credentials_table.php');
        $oauthStatesRlsMigration = include database_path('migrations/2026_09_04_040002_prepare_row_level_security_and_force_rls_on_integration_oauth_states_table.php');
        $oauthStatesCreateMigration = include database_path('migrations/2026_09_04_040001_create_integration_oauth_states_table.php');

        // Checkpoint 6 whole-wave migration objects, in creation order.
        $cp6Migrations = array_map(
            static fn (string $path) => include base_path($path),
            self::CP6_WHOLE_WAVE_MIGRATION_PATHS,
        );

        // Checkpoint 7 whole-wave migration objects, in creation order.
        $cp7Migrations = array_map(
            static fn (string $path) => include base_path($path),
            self::CP7_WHOLE_WAVE_MIGRATION_PATHS,
        );

        // Checkpoint 8 whole-wave migration objects, in creation order.
        $cp8Migrations = array_map(
            static fn (string $path) => include base_path($path),
            self::CP8_WHOLE_WAVE_MIGRATION_PATHS,
        );

        // Checkpoint 9 whole-wave migration objects, in creation order.
        $cp9Migrations = array_map(
            static fn (string $path) => include base_path($path),
            self::CP9_WHOLE_WAVE_MIGRATION_PATHS,
        );

        // Checkpoint 4 (FirmsVault Live Integrations, "Plaid financial
        // evidence add-on") provider_billable_call_reservations migration
        // objects, in creation order — the newest layer of all, FK-
        // dependent on integration_usage_records (see
        // CP4_WHOLE_WAVE_MIGRATION_PATHS docblock).
        $cp4ProviderBillableReservationsMigrations = array_map(
            static fn (string $path) => include base_path($path),
            self::CP4_WHOLE_WAVE_MIGRATION_PATHS,
        );

        // Checkpoint 2 (FirmsVault Live Integrations, Microsoft 365
        // provider) webhook-subscriptions whole-wave migration objects, in
        // creation order.
        $cp2WebhookSubscriptionsMigrations = array_map(
            static fn (string $path) => include base_path($path),
            self::CP2_WEBHOOK_SUBSCRIPTIONS_WHOLE_WAVE_MIGRATION_PATHS,
        );

        // Checkpoint 3 (FirmsVault Live Integrations, Google Workspace
        // provider) gmail-mailbox-routes whole-wave migration objects, in
        // creation order — the newest layer of all.
        $cp3GmailMailboxRoutesMigrations = array_map(
            static fn (string $path) => include base_path($path),
            self::CP3_GMAIL_MAILBOX_ROUTES_WHOLE_WAVE_MIGRATION_PATHS,
        );

        // Tear down the Checkpoint 3 gmail-mailbox-routes whole-wave
        // dependency chain FIRST — before the Checkpoint 2
        // webhook-subscriptions whole-wave teardown below, since this
        // wave is the newest layer of all (see
        // CP3_GMAIL_MAILBOX_ROUTES_WHOLE_WAVE_MIGRATION_PATHS docblock).
        // Torn down as a unit, in exact reverse of its own creation order
        // (a single migration, so this is just its own down()).
        foreach (array_reverse($cp3GmailMailboxRoutesMigrations) as $migration) {
            $migration->down();
        }
        foreach (self::CP3_GMAIL_MAILBOX_ROUTES_WHOLE_WAVE_TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table), "{$table} (Checkpoint 3 gmail mailbox routes) must be fully dropped before the Checkpoint 2 webhook-subscriptions whole-wave teardown can succeed.");
        }

        // Tear down the Checkpoint 2 webhook-subscriptions whole-wave
        // dependency chain FIRST — before the Checkpoint 9 whole-wave
        // teardown below, since this wave is the newest layer other than
        // Checkpoint 3 (see
        // CP2_WEBHOOK_SUBSCRIPTIONS_WHOLE_WAVE_MIGRATION_PATHS docblock).
        // Torn down as a unit, in exact reverse of its own creation order.
        foreach (array_reverse($cp2WebhookSubscriptionsMigrations) as $migration) {
            $migration->down();
        }
        foreach (self::CP2_WEBHOOK_SUBSCRIPTIONS_WHOLE_WAVE_TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table), "{$table} (Checkpoint 2 webhook subscriptions) must be fully dropped before the Checkpoint 9 whole-wave teardown can succeed.");
        }

        // Tear down Checkpoint 4's provider_billable_call_reservations
        // FIRST — before the Checkpoint 9 whole-wave teardown below drops
        // integration_usage_records itself.
        foreach (array_reverse($cp4ProviderBillableReservationsMigrations) as $migration) {
            $migration->down();
        }
        foreach (self::CP4_WHOLE_WAVE_TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table), "{$table} (Checkpoint 4) must be fully dropped before the Checkpoint 9 whole-wave teardown can succeed.");
        }

        // Tear down the Checkpoint 9 whole-wave dependency chain FIRST —
        // before the Checkpoint 8 whole-wave teardown below, since
        // Checkpoint 9 is the newest layer (see
        // CP9_WHOLE_WAVE_MIGRATION_PATHS docblock). Torn down as a unit,
        // in exact reverse of its own creation order.
        foreach (array_reverse($cp9Migrations) as $migration) {
            $migration->down();
        }
        foreach (self::CP9_WHOLE_WAVE_TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table), "{$table} (Checkpoint 9) must be fully dropped before the Checkpoint 8 whole-wave teardown can succeed.");
        }

        // Tear down the Checkpoint 8 whole-wave dependency chain FIRST —
        // before the Checkpoint 7 whole-wave teardown below, since
        // Checkpoint 8 is the newest layer (see
        // CP8_WHOLE_WAVE_MIGRATION_PATHS docblock). Torn down as a unit,
        // in exact reverse of its own creation order.
        foreach (array_reverse($cp8Migrations) as $migration) {
            $migration->down();
        }
        foreach (self::CP8_WHOLE_WAVE_TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table), "{$table} (Checkpoint 8) must be fully dropped before the Checkpoint 7 whole-wave teardown can succeed.");
        }

        // Tear down the Checkpoint 7 whole-wave dependency chain FIRST —
        // before the Checkpoint 6 whole-wave teardown below, since
        // Checkpoint 7's own trailing migration ALTERs the Checkpoint 6
        // integration_sync_runs table directly and must be undone before
        // that table is dropped (see CP7_WHOLE_WAVE_MIGRATION_PATHS
        // docblock), and because integration_webhook_routing_index's
        // direct FK into integration_providers must also be gone before
        // integration_providers' own down() can succeed. Internal FK
        // order matters within this wave itself, so it is torn down as a
        // unit, in exact reverse of its own creation order.
        foreach (array_reverse($cp7Migrations) as $migration) {
            $migration->down();
        }
        foreach (self::CP7_WHOLE_WAVE_TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table), "{$table} (Checkpoint 7) must be fully dropped before the Checkpoint 6 whole-wave teardown can succeed.");
        }
        $this->assertFalse(
            Schema::hasColumn('integration_sync_runs', 'triggering_webhook_event_id'),
            'integration_sync_runs.triggering_webhook_event_id must not survive the Checkpoint 7 whole-wave teardown.'
        );

        // Tear down the Checkpoint 6 whole-wave dependency chain FIRST —
        // internal FK order matters within the wave itself, so it is
        // torn down as a unit, in exact reverse of its own creation
        // order, before firm_integrations' other dependents or
        // firm_integrations itself.
        foreach (array_reverse($cp6Migrations) as $migration) {
            $migration->down();
        }
        foreach (self::CP6_WHOLE_WAVE_TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table), "{$table} (Checkpoint 6) must be fully dropped before firm_integrations down() can succeed.");
        }

        // Tear down firm_integrations' real dependents FIRST —
        // integration_credentials and integration_oauth_states (order
        // between the two does not matter to each other) — each in
        // FK-dependency / reverse-chronological order (RLS-prep down(),
        // then create-table down()) — before firm_integrations' own
        // down() calls.
        $credentialsRlsMigration->down();
        $credentialsCreateMigration->down();

        $this->assertFalse(
            Schema::hasTable('integration_credentials'),
            'integration_credentials must be fully dropped before firm_integrations down() can succeed.'
        );

        $oauthStatesRlsMigration->down();
        $oauthStatesCreateMigration->down();

        $this->assertFalse(
            Schema::hasTable('integration_oauth_states'),
            'integration_oauth_states must be fully dropped before firm_integrations down() can succeed.'
        );

        // Tear down firm_integrations' one real dependency on
        // integration_providers next — in FK-dependency / reverse-
        // chronological order (RLS-prep down(), then create-table
        // down()) — before integration_providers' own down().
        $firmIntegrationsRlsMigration->down();
        $firmIntegrationsCreateMigration->down();

        $this->assertFalse(
            Schema::hasTable('firm_integrations'),
            'firm_integrations must be fully dropped before integration_providers down() can succeed.'
        );

        $providersMigration->down();

        $this->assertFalse(Schema::hasTable('integration_providers'), 'Table must be fully dropped after down().');

        $providersMigration->up();

        $this->assertTrue(Schema::hasTable('integration_providers'), 'Table must be fully restored after up().');

        // Rebuild firm_integrations in forward order: create-table up(),
        // then its RLS-prep migration's up().
        $firmIntegrationsCreateMigration->up();
        $firmIntegrationsRlsMigration->up();

        // Rebuild integration_credentials in forward order: create-table
        // up(), then its RLS-prep migration's up().
        $credentialsCreateMigration->up();
        $credentialsRlsMigration->up();

        $after = DB::table('integration_providers')->where('code', 'test')->first();
        $this->assertNotNull($after, 'Expected the seeded test-provider row to be restored after up().');
        $this->assertSame($before->code, $after->code);
        $this->assertSame($before->display_name, $after->display_name);
        $this->assertSame($before->category, $after->category);
        $this->assertSame($before->auth_method, $after->auth_method);
        $this->assertSame($before->status, $after->status);

        // Prove firm_integrations itself ends up correctly restored too,
        // as a natural side-effect of proving cross-table rollback safety
        // — not just that integration_providers round-trips.
        $this->assertTrue(Schema::hasTable('firm_integrations'), 'firm_integrations must be fully restored after up().');
        $this->assertTrue(Schema::hasColumn('firm_integrations', 'integration_provider_id'));

        $firmIntegrationsRlsAfter = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'firm_integrations'");
        $this->assertNotNull($firmIntegrationsRlsAfter);
        $this->assertTrue(
            (bool) $firmIntegrationsRlsAfter->relrowsecurity,
            'firm_integrations RLS must be re-enabled after reapplying its RLS-prep migration up().'
        );
        $this->assertTrue(
            (bool) $firmIntegrationsRlsAfter->relforcerowsecurity,
            'firm_integrations FORCE RLS must be re-enabled after reapplying its RLS-prep migration up().'
        );

        $firmIntegrationsPolicies = DB::select("select policyname from pg_policies where tablename = 'firm_integrations'");
        $this->assertCount(1, $firmIntegrationsPolicies);
        $this->assertSame('firm_integrations_tenant_isolation', $firmIntegrationsPolicies[0]->policyname);

        // Prove integration_credentials itself ends up correctly restored
        // too (Checkpoint 4 — one level further down the same FK chain),
        // as a natural side-effect proof, not merely that firm_integrations
        // round-trips.
        $this->assertTrue(Schema::hasTable('integration_credentials'), 'integration_credentials must be fully restored after up().');
        $this->assertTrue(Schema::hasColumn('integration_credentials', 'firm_integration_id'));

        $integrationCredentialsRlsAfter = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'integration_credentials'");
        $this->assertNotNull($integrationCredentialsRlsAfter);
        $this->assertTrue(
            (bool) $integrationCredentialsRlsAfter->relrowsecurity,
            'integration_credentials RLS must be re-enabled after reapplying its RLS-prep migration up().'
        );
        $this->assertTrue(
            (bool) $integrationCredentialsRlsAfter->relforcerowsecurity,
            'integration_credentials FORCE RLS must be re-enabled after reapplying its RLS-prep migration up().'
        );

        $integrationCredentialsPolicies = DB::select("select policyname from pg_policies where tablename = 'integration_credentials'");
        $this->assertCount(1, $integrationCredentialsPolicies);
        $this->assertSame('integration_credentials_tenant_isolation', $integrationCredentialsPolicies[0]->policyname);

        // Rebuild integration_oauth_states in forward order: create-table
        // up(), then its RLS-prep migration's up().
        $oauthStatesCreateMigration->up();
        $oauthStatesRlsMigration->up();

        // Prove integration_oauth_states itself ends up correctly
        // restored too (Checkpoint 5 — a second, independent dependent at
        // the same level as integration_credentials), as a natural
        // side-effect proof, not merely that firm_integrations/
        // integration_credentials round-trip.
        $this->assertTrue(Schema::hasTable('integration_oauth_states'), 'integration_oauth_states must be fully restored after up().');
        $this->assertTrue(Schema::hasColumn('integration_oauth_states', 'firm_integration_id'));

        $oauthStatesRlsAfter = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'integration_oauth_states'");
        $this->assertNotNull($oauthStatesRlsAfter);
        $this->assertTrue(
            (bool) $oauthStatesRlsAfter->relrowsecurity,
            'integration_oauth_states RLS must be re-enabled after reapplying its RLS-prep migration up().'
        );
        $this->assertTrue(
            (bool) $oauthStatesRlsAfter->relforcerowsecurity,
            'integration_oauth_states FORCE RLS must be re-enabled after reapplying its RLS-prep migration up().'
        );

        $oauthStatesPolicies = DB::select("select policyname from pg_policies where tablename = 'integration_oauth_states' order by policyname");
        $this->assertCount(2, $oauthStatesPolicies);
        $this->assertSame(
            ['integration_oauth_states_self_lookup', 'integration_oauth_states_tenant_isolation'],
            array_map(fn ($p) => $p->policyname, $oauthStatesPolicies)
        );

        // Rebuild the Checkpoint 6 whole-wave dependency chain last, in
        // its own forward (creation) order.
        foreach ($cp6Migrations as $migration) {
            $migration->up();
        }
        foreach (self::CP6_WHOLE_WAVE_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} (Checkpoint 6) must be fully restored after up().");
        }

        // Rebuild the Checkpoint 7 whole-wave dependency chain LAST — its
        // trailing migration needs integration_sync_runs (just recreated
        // above) to already exist — in its own forward (creation) order.
        foreach ($cp7Migrations as $migration) {
            $migration->up();
        }
        foreach (self::CP7_WHOLE_WAVE_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} (Checkpoint 7) must be fully restored after up().");
        }
        $this->assertTrue(
            Schema::hasColumn('integration_sync_runs', 'triggering_webhook_event_id'),
            'integration_sync_runs.triggering_webhook_event_id must be restored after the Checkpoint 7 whole-wave up().'
        );

        // Rebuild the Checkpoint 8 whole-wave dependency chain LAST —
        // after the Checkpoint 7 whole-wave block, since Checkpoint 8 is
        // the newest layer — in its own forward (creation) order.
        foreach ($cp8Migrations as $migration) {
            $migration->up();
        }
        foreach (self::CP8_WHOLE_WAVE_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} (Checkpoint 8) must be fully restored after up().");
        }

        // Rebuild the Checkpoint 9 whole-wave dependency chain LAST —
        // after the Checkpoint 8 whole-wave block, since Checkpoint 9 is
        // the newest layer, and after integration_sync_runs/
        // integration_sync_items/integration_inbound_webhook_events
        // already exist again — in its own forward (creation) order.
        foreach ($cp9Migrations as $migration) {
            $migration->up();
        }
        foreach (self::CP9_WHOLE_WAVE_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} (Checkpoint 9) must be fully restored after up().");
        }

        // Rebuild Checkpoint 4's provider_billable_call_reservations LAST
        // of all — after integration_usage_records already exists again.
        foreach ($cp4ProviderBillableReservationsMigrations as $migration) {
            $migration->up();
        }
        foreach (self::CP4_WHOLE_WAVE_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} (Checkpoint 4) must be fully restored after up().");
        }

        // Rebuild the Checkpoint 2 webhook-subscriptions whole-wave
        // dependency chain LAST — after the Checkpoint 9 whole-wave block,
        // since this wave is the newest layer other than Checkpoint 3, and
        // after firm_integrations (just recreated above) already exists
        // again — in its own forward (creation) order.
        foreach ($cp2WebhookSubscriptionsMigrations as $migration) {
            $migration->up();
        }
        foreach (self::CP2_WEBHOOK_SUBSCRIPTIONS_WHOLE_WAVE_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} (Checkpoint 2 webhook subscriptions) must be fully restored after up().");
        }

        // Rebuild the Checkpoint 3 gmail-mailbox-routes whole-wave
        // dependency chain LAST of all — after the Checkpoint 2
        // webhook-subscriptions whole-wave block, since this wave is the
        // newest layer of all, and after integration_providers (just
        // recreated above) already exists again — in its own forward
        // (creation) order (a single migration, so this is just its own
        // up()).
        foreach ($cp3GmailMailboxRoutesMigrations as $migration) {
            $migration->up();
        }
        foreach (self::CP3_GMAIL_MAILBOX_ROUTES_WHOLE_WAVE_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} (Checkpoint 3 gmail mailbox routes) must be fully restored after up().");
        }
    }

    // ------------------------------------------------------------
    // 4. Model behavior
    // ------------------------------------------------------------

    public function test_model_table_resolves_to_integration_providers(): void
    {
        $model = new IntegrationProvider;

        $this->assertSame('integration_providers', $model->getTable());
    }

    public function test_model_fillable_contains_exactly_the_expected_fields(): void
    {
        $model = new IntegrationProvider;

        $expected = [
            'code',
            'display_name',
            'category',
            'auth_method',
            'status',
            'module_code',
            'degradation_type_key',
            'required_oauth_scopes_json',
            'webhook_event_types_json',
        ];

        $fillable = $model->getFillable();

        sort($fillable);
        sort($expected);

        $this->assertSame($expected, $fillable);
        $this->assertNotContains('id', $fillable);
        $this->assertNotContains('created_at', $fillable);
        $this->assertNotContains('updated_at', $fillable);
    }

    public function test_json_columns_cast_to_array(): void
    {
        // NOTE: uses IntegrationProviderFactory::new() directly rather than
        // IntegrationProvider::factory() purely as a stylistic choice for
        // this test — both work correctly; see
        // test_model_factory_static_accessor_resolves_correctly_after_new_factory_override()
        // below, which specifically exercises the model's own factory()
        // accessor.
        $model = IntegrationProviderFactory::new()->create([
            'required_oauth_scopes_json' => ['scope.a', 'scope.b'],
            'webhook_event_types_json' => ['event.a'],
        ]);

        $fresh = IntegrationProvider::query()->findOrFail($model->id);

        $this->assertIsArray($fresh->required_oauth_scopes_json);
        $this->assertSame(['scope.a', 'scope.b'], $fresh->required_oauth_scopes_json);
        $this->assertIsArray($fresh->webhook_event_types_json);
        $this->assertSame(['event.a'], $fresh->webhook_event_types_json);
    }

    public function test_model_does_not_use_the_tenant_scoping_trait(): void
    {
        $traits = class_uses_recursive(IntegrationProvider::class);

        $this->assertArrayNotHasKey(
            BelongsToTenant::class,
            $traits,
            'IntegrationProvider is Global reference data — it must never use BelongsToTenant.'
        );
    }

    /**
     * DEFECT FIX VERIFICATION:
     * IntegrationProvider previously had no newFactory() override, so
     * Laravel's default Factory::resolveFactoryName() resolver — which
     * only special-cases the "App\Models\" prefix — mis-resolved
     * IntegrationProvider::factory() (the model lives in
     * App\Integrations\Models) to the nonexistent class
     * Database\Factories\Integrations\Models\IntegrationProviderFactory,
     * causing a fatal error for every caller of the standard accessor.
     *
     * A narrow production fix has since added a
     * `protected static function newFactory(): IntegrationProviderFactory`
     * override to app/Integrations/Models/IntegrationProvider.php that
     * returns IntegrationProviderFactory::new() directly. This test
     * exercises the model's own static factory() method (not
     * IntegrationProviderFactory::new() directly, which was only ever
     * the workaround used elsewhere in this file before the fix) to
     * prove IntegrationProvider::factory() now resolves correctly and
     * produces a genuine, persistable model instance without throwing.
     */
    public function test_model_factory_static_accessor_resolves_correctly_after_new_factory_override(): void
    {
        $factory = IntegrationProvider::factory();

        $this->assertInstanceOf(IntegrationProviderFactory::class, $factory);

        $provider = IntegrationProvider::factory()->create();

        $this->assertInstanceOf(IntegrationProvider::class, $provider);
        $this->assertTrue($provider->exists);
        $this->assertNotNull($provider->id);

        $fresh = IntegrationProvider::query()->findOrFail($provider->id);
        $this->assertSame($provider->code, $fresh->code);
    }

    public function test_model_has_no_global_scope_applied(): void
    {
        // A tenant-scoped model in this codebase applies its scoping via
        // BelongsToTenant, which registers a global scope. Confirming
        // zero global scopes here is a second, independent proof (beyond
        // the trait-usage check above) that no tenant-filtering behavior
        // has been silently attached some other way.
        $model = new IntegrationProvider;

        $this->assertSame([], $model->getGlobalScopes());
    }

    // ------------------------------------------------------------
    // 5. No accidental executable coupling with ProviderRegistry
    // ------------------------------------------------------------

    public function test_provider_registry_source_has_no_database_or_eloquent_coupling(): void
    {
        $reflection = new ReflectionClass(ProviderRegistry::class);
        $source = file_get_contents($reflection->getFileName());

        $this->assertNotFalse($source);

        $forbiddenPatterns = [
            'IntegrationProvider::',
            'IntegrationProvider(',
            '\\DB::',
            'DB::table',
            'DB::select',
            'DB::selectOne',
            '::query()',
            'Eloquent',
        ];

        foreach ($forbiddenPatterns as $pattern) {
            $this->assertStringNotContainsString(
                $pattern,
                $source,
                "ProviderRegistry must never query the database (found reference to '{$pattern}') — it resolves providers strictly via the code-defined config('integrations.providers') map."
            );
        }

        $this->assertStringNotContainsString(
            'use App\\Integrations\\Models\\IntegrationProvider',
            $source,
            'ProviderRegistry must not import the IntegrationProvider Eloquent model at all — the two are structurally decoupled.'
        );
    }

    // ------------------------------------------------------------
    // 6. Factory hygiene
    // ------------------------------------------------------------

    public function test_factory_generates_synthetic_codes_that_cannot_collide_with_real_or_seeded_codes(): void
    {
        $reservedCodes = [
            ProviderKey::Test->value,
            'google', 'microsoft', 'stripe', 'quickbooks', 'lawpay',
            'clio', 'plaid', 'zoom', 'dropbox', 'xero', 'docusign',
        ];

        $providers = IntegrationProviderFactory::new()->count(10)->create();

        foreach ($providers as $provider) {
            $this->assertNotContains(
                $provider->code,
                $reservedCodes,
                "Factory-generated code '{$provider->code}' must never collide with a real or seeded provider key."
            );
            $this->assertStringStartsWith(
                'test-fixture-',
                $provider->code,
                'Factory-generated codes must be obviously synthetic, not merely accidentally-unique.'
            );
        }

        // Uniqueness: no two factory-generated rows collide with each other.
        $this->assertSame(10, $providers->pluck('code')->unique()->count());
    }

    public function test_factory_definition_contains_no_secret_or_credential_shaped_field(): void
    {
        $definition = (new IntegrationProviderFactory)->definition();

        $suspiciousKeys = array_filter(
            array_keys($definition),
            static fn (string $key): bool => (bool) preg_match('/secret|token|password|credential|api_key/i', $key)
        );

        $this->assertSame(
            [],
            array_values($suspiciousKeys),
            'integration_providers has no secret/credential-shaped column, so the factory must never introduce one.'
        );
    }
}
