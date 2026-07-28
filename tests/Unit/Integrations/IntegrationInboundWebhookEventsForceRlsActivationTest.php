<?php

declare(strict_types=1);

namespace Tests\Unit\Integrations;

use App\Integrations\Enums\WebhookInboundEventStatus;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationInboundWebhookEvent;
use App\Integrations\Models\IntegrationWebhookReceipt;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Firm;
use App\Services\TenantContextService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * IntegrationInboundWebhookEventsForceRlsActivationTest — Checkpoint 7
 * (reviews/checkpoint-07/frozen-design-post-security-review.md §10.2).
 * Mirrors IntegrationCredentialsForceRlsActivationTest/
 * IntegrationOauthStatesForceRlsActivationTest's exact structural
 * conventions: direct pg_class/pg_policies/pg_policy catalog queries,
 * runWithFirmContext() for every tenant-scoped read/write, paired
 * Artisan-call / direct-include migration rollback proofs.
 */
class IntegrationInboundWebhookEventsForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    private const TABLE_MIGRATION_PATH = 'database/migrations/2026_09_06_060003_create_integration_inbound_webhook_events_table.php';

    private const TABLE_MIGRATION_NAME = '2026_09_06_060003_create_integration_inbound_webhook_events_table';

    private const RLS_MIGRATION_PATH = 'database/migrations/2026_09_06_060004_prepare_row_level_security_and_force_rls_on_integration_inbound_webhook_events_table.php';

    private const RLS_MIGRATION_NAME = '2026_09_06_060004_prepare_row_level_security_and_force_rls_on_integration_inbound_webhook_events_table';

    /**
     * `integration_sync_runs.triggering_webhook_event_id`'s composite FK
     * (migration 060005) references this table — it must be rolled back
     * BEFORE this table's own migrations can be dropped, and reapplied
     * AFTER they're restored (frozen design §11's required ordering).
     */
    private const DEPENDENT_MIGRATION_PATH = 'database/migrations/2026_09_06_060005_add_triggering_webhook_event_id_to_integration_sync_runs_table.php';

    /**
     * POST-CHECKPOINT-9 UPDATE: Checkpoint 9's
     * `2026_09_08_080001_create_integration_usage_records_table` migration
     * adds a real composite FK (firm_id, inbound_webhook_event_id) ->
     * integration_inbound_webhook_events(firm_id, id) (ON DELETE SET
     * NULL) — so integration_usage_records must now ALSO be rolled back
     * before this table's own migrations, in addition to
     * DEPENDENT_MIGRATION_PATH above, or dropping this table fails with
     * "cannot drop table ... because other objects depend on it".
     * Reapplied LAST, after this table's own migrations are restored.
     * Rolled back in exact reverse of its own creation order (RLS-prep
     * down(), then create-table down()).
     *
     * @var list<string>
     */
    private const CP9_USAGE_RECORDS_MIGRATION_PATHS = [
        'database/migrations/2026_09_08_080001_create_integration_usage_records_table.php',
        'database/migrations/2026_09_08_080002_prepare_row_level_security_and_force_rls_on_integration_usage_records_table.php',
    ];

    /**
     * POST-CHECKPOINT-4-PLAID UPDATE: Checkpoint 4's
     * `2026_09_24_500002_create_provider_billable_call_reservations_table`
     * migration adds a real (bare, single-column) FK `usage_record_id` ->
     * integration_usage_records(id) (nullOnDelete()) — a bare FK still
     * blocks dropping the referenced table in PostgreSQL exactly like the
     * Checkpoint 9 composite one does, so provider_billable_call_reservations
     * must now be rolled back BEFORE integration_usage_records itself (see
     * CP9_USAGE_RECORDS_MIGRATION_PATHS above), or dropping THAT table
     * fails with "cannot drop table ... because other objects depend on
     * it". Reapplied LAST of all, after integration_usage_records is
     * restored.
     *
     * @var list<string>
     */
    private const CP4_PROVIDER_BILLABLE_RESERVATIONS_MIGRATION_PATHS = [
        'database/migrations/2026_09_24_500002_create_provider_billable_call_reservations_table.php',
        'database/migrations/2026_09_24_500003_prepare_row_level_security_and_force_rls_on_provider_billable_call_reservations_table.php',
    ];

    private const POLICY_NAME = 'integration_inbound_webhook_events_tenant_isolation';

    private const EXPECTED_COLUMNS = [
        'id', 'uuid', 'firm_id', 'firm_integration_id', 'receipt_id',
        'provider_key', 'provider_event_id', 'receipt_body_hash', 'event_type',
        'payload_reference_json', 'payload_hash', 'status', 'lock_token', 'locked_at',
        'processing_attempts', 'failure_code', 'failure_detail', 'triggering_sync_run_id',
        'received_at', 'started_processing_at', 'processed_at', 'terminal_at',
        'retention_deadline', 'created_at', 'updated_at',
    ];

    // ------------------------------------------------------------
    // 1. Schema correctness
    // ------------------------------------------------------------

    public function test_integration_inbound_webhook_events_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('integration_inbound_webhook_events'));
    }

    public function test_integration_inbound_webhook_events_has_exactly_the_expected_columns(): void
    {
        $columns = Schema::getColumnListing('integration_inbound_webhook_events');
        sort($columns);
        $expected = self::EXPECTED_COLUMNS;
        sort($expected);

        $this->assertSame($expected, $columns);
    }

    public function test_composite_foreign_key_on_firm_id_and_firm_integration_id_exists(): void
    {
        $constraints = DB::select(
            "select conname, array_length(conkey, 1) as col_count, confrelid::regclass::text as foreign_table
             from pg_constraint
             where conrelid = 'integration_inbound_webhook_events'::regclass and contype = 'f'
             order by conname"
        );

        $composite = array_values(array_filter($constraints, fn ($row) => (int) $row->col_count === 2));

        $this->assertCount(1, $composite);
        $this->assertSame('firm_integrations', $composite[0]->foreign_table);
    }

    public function test_composite_foreign_key_rejects_a_firm_integration_id_belonging_to_a_different_firm(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $connectionB = FirmIntegration::factory()->forFirm($firmB)->create();
        $receipt = IntegrationWebhookReceipt::factory()->create();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/foreign key constraint/i');

        $this->runWithFirmContext($firmA, function () use ($firmA, $connectionB, $receipt) {
            DB::table('integration_inbound_webhook_events')->insert($this->rawRowAttributes($firmA, $connectionB, $receipt));
        });
    }

    public function test_receipt_id_foreign_key_is_bare_and_nulls_on_receipt_deletion_without_touching_firm_id(): void
    {
        $firm = Firm::factory()->create();
        $connection = FirmIntegration::factory()->forFirm($firm)->create();
        $receipt = IntegrationWebhookReceipt::factory()->create();

        $event = $this->runWithFirmContext($firm, fn () => IntegrationInboundWebhookEvent::factory()
            ->forFirmIntegration($connection)
            ->forReceipt($receipt)
            ->create());

        DB::table('integration_webhook_receipts')->where('id', $receipt->id)->delete();

        $fresh = $this->runWithFirmContext($firm, fn () => DB::table('integration_inbound_webhook_events')->where('id', $event->id)->first());

        $this->assertNull($fresh->receipt_id, 'Deleting the receipt must null ONLY receipt_id.');
        $this->assertSame($firm->id, $fresh->firm_id, 'firm_id must be completely untouched by the receipt deletion.');
        $this->assertSame($connection->id, $fresh->firm_integration_id);
    }

    public function test_unique_receipt_id_prevents_a_second_event_row_from_the_same_receipt(): void
    {
        $firm = Firm::factory()->create();
        $connection = FirmIntegration::factory()->forFirm($firm)->create();
        $receipt = IntegrationWebhookReceipt::factory()->create();

        $this->runWithFirmContext($firm, fn () => IntegrationInboundWebhookEvent::factory()->forFirmIntegration($connection)->forReceipt($receipt)->create());

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/unique constraint|duplicate key/i');

        $this->runWithFirmContext($firm, fn () => IntegrationInboundWebhookEvent::factory()->forFirmIntegration($connection)->forReceipt($receipt)->create());
    }

    public function test_unique_firm_integration_id_provider_key_provider_event_id_prevents_a_duplicate(): void
    {
        $firm = Firm::factory()->create();
        $connection = FirmIntegration::factory()->forFirm($firm)->create();

        $this->runWithFirmContext($firm, fn () => IntegrationInboundWebhookEvent::factory()
            ->forFirmIntegration($connection)
            ->create(['provider_key' => 'test', 'provider_event_id' => 'evt-dup-1']));

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/unique constraint|duplicate key/i');

        $this->runWithFirmContext($firm, fn () => IntegrationInboundWebhookEvent::factory()
            ->forFirmIntegration($connection)
            ->create(['provider_key' => 'test', 'provider_event_id' => 'evt-dup-1']));
    }

    public function test_processed_status_requires_a_non_null_processed_at_timestamp(): void
    {
        $firm = Firm::factory()->create();
        $connection = FirmIntegration::factory()->forFirm($firm)->create();
        $event = $this->runWithFirmContext($firm, fn () => IntegrationInboundWebhookEvent::factory()->forFirmIntegration($connection)->create());

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/check constraint|violates/i');

        $this->runWithFirmContext($firm, fn () => DB::table('integration_inbound_webhook_events')
            ->where('id', $event->id)
            ->update(['status' => WebhookInboundEventStatus::Processed->value, 'processed_at' => null, 'terminal_at' => now()]));
    }

    public function test_terminal_statuses_require_a_non_null_terminal_at_timestamp(): void
    {
        $firm = Firm::factory()->create();
        $connection = FirmIntegration::factory()->forFirm($firm)->create();
        $event = $this->runWithFirmContext($firm, fn () => IntegrationInboundWebhookEvent::factory()->forFirmIntegration($connection)->create());

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/check constraint|violates/i');

        $this->runWithFirmContext($firm, fn () => DB::table('integration_inbound_webhook_events')
            ->where('id', $event->id)
            ->update(['status' => WebhookInboundEventStatus::Failed->value, 'failure_code' => 'x', 'terminal_at' => null]));
    }

    public function test_failed_status_requires_a_non_null_failure_code(): void
    {
        $firm = Firm::factory()->create();
        $connection = FirmIntegration::factory()->forFirm($firm)->create();
        $event = $this->runWithFirmContext($firm, fn () => IntegrationInboundWebhookEvent::factory()->forFirmIntegration($connection)->create());

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/check constraint|violates/i');

        $this->runWithFirmContext($firm, fn () => DB::table('integration_inbound_webhook_events')
            ->where('id', $event->id)
            ->update(['status' => WebhookInboundEventStatus::Failed->value, 'failure_code' => null, 'terminal_at' => now()]));
    }

    public function test_handed_off_status_requires_both_lock_token_and_locked_at(): void
    {
        $firm = Firm::factory()->create();
        $connection = FirmIntegration::factory()->forFirm($firm)->create();
        $event = $this->runWithFirmContext($firm, fn () => IntegrationInboundWebhookEvent::factory()->forFirmIntegration($connection)->create());

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/check constraint|violates/i');

        $this->runWithFirmContext($firm, fn () => DB::table('integration_inbound_webhook_events')
            ->where('id', $event->id)
            ->update(['status' => WebhookInboundEventStatus::HandedOff->value, 'lock_token' => null, 'locked_at' => null]));
    }

    // ------------------------------------------------------------
    // 2. RLS proof via live PostgreSQL catalog queries
    // ------------------------------------------------------------

    public function test_integration_inbound_webhook_events_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'integration_inbound_webhook_events'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_integration_inbound_webhook_events_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'integration_inbound_webhook_events'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relforcerowsecurity);
    }

    public function test_the_runtime_database_role_has_no_bypassrls(): void
    {
        $row = DB::selectOne('select rolbypassrls from pg_roles where rolname = current_user');

        $this->assertNotNull($row);
        $this->assertFalse((bool) $row->rolbypassrls);
    }

    public function test_integration_inbound_webhook_events_has_exactly_one_row_level_security_policy(): void
    {
        $rows = DB::select("select policyname from pg_policies where tablename = 'integration_inbound_webhook_events'");

        $this->assertCount(1, $rows);
        $this->assertSame(self::POLICY_NAME, $rows[0]->policyname);
    }

    public function test_the_policy_has_both_using_and_with_check_matching_the_canonical_predicate(): void
    {
        $row = DB::selectOne(
            "select pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy where polrelid = 'integration_inbound_webhook_events'::regclass and polname = ?",
            [self::POLICY_NAME]
        );

        $this->assertNotNull($row);

        $expected = "(firm_id = (NULLIF(current_setting('app.current_firm_id'::text, true), ''::text))::bigint)";

        $this->assertSame($expected, $row->using_expr);
        $this->assertSame($expected, $row->with_check_expr);
    }

    // ------------------------------------------------------------
    // 3. Cross-firm tenant isolation
    // ------------------------------------------------------------

    public function test_missing_tenant_context_cannot_read_integration_inbound_webhook_events(): void
    {
        $firm = Firm::factory()->create();
        $connection = FirmIntegration::factory()->forFirm($firm)->create();
        $this->runWithFirmContext($firm, fn () => IntegrationInboundWebhookEvent::factory()->forFirmIntegration($connection)->create());

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->assertSame(0, DB::table('integration_inbound_webhook_events')->count());
    }

    public function test_missing_tenant_context_cannot_insert_integration_inbound_webhook_events(): void
    {
        $firm = Firm::factory()->create();
        $connection = FirmIntegration::factory()->forFirm($firm)->create();
        $receipt = IntegrationWebhookReceipt::factory()->create();

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('integration_inbound_webhook_events')->insert($this->rawRowAttributes($firm, $connection, $receipt));
    }

    public function test_firm_a_context_can_read_its_own_inbound_webhook_event(): void
    {
        $firm = Firm::factory()->create();
        $connection = FirmIntegration::factory()->forFirm($firm)->create();
        $event = $this->runWithFirmContext($firm, fn () => IntegrationInboundWebhookEvent::factory()->forFirmIntegration($connection)->create());

        $visibleIds = $this->runWithFirmContext($firm, fn () => DB::table('integration_inbound_webhook_events')->pluck('id')->all());

        $this->assertSame([$event->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_bs_inbound_webhook_event(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $connectionA = FirmIntegration::factory()->forFirm($firmA)->create();
        $connectionB = FirmIntegration::factory()->forFirm($firmB)->create();
        $this->runWithFirmContext($firmA, fn () => IntegrationInboundWebhookEvent::factory()->forFirmIntegration($connectionA)->create());
        $eventB = $this->runWithFirmContext($firmB, fn () => IntegrationInboundWebhookEvent::factory()->forFirmIntegration($connectionB)->create());

        $visibleIds = $this->runWithFirmContext($firmA, fn () => DB::table('integration_inbound_webhook_events')->pluck('id')->all());

        $this->assertNotContains($eventB->id, $visibleIds);
    }

    public function test_firm_a_cannot_update_firm_bs_inbound_webhook_event(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $connectionB = FirmIntegration::factory()->forFirm($firmB)->create();
        $eventB = $this->runWithFirmContext($firmB, fn () => IntegrationInboundWebhookEvent::factory()->forFirmIntegration($connectionB)->create());

        $affected = $this->runWithFirmContext($firmA, fn () => DB::table('integration_inbound_webhook_events')->where('id', $eventB->id)->update(['event_type' => 'hacked']));

        $this->assertSame(0, $affected);

        $reReadAsFirmB = $this->runWithFirmContext($firmB, fn () => DB::table('integration_inbound_webhook_events')->where('id', $eventB->id)->value('event_type'));
        $this->assertNotSame('hacked', $reReadAsFirmB);
    }

    public function test_firm_a_cannot_delete_firm_bs_inbound_webhook_event(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $connectionB = FirmIntegration::factory()->forFirm($firmB)->create();
        $eventB = $this->runWithFirmContext($firmB, fn () => IntegrationInboundWebhookEvent::factory()->forFirmIntegration($connectionB)->create());

        $affected = $this->runWithFirmContext($firmA, fn () => DB::table('integration_inbound_webhook_events')->where('id', $eventB->id)->delete());

        $this->assertSame(0, $affected);

        $reReadAsFirmB = $this->runWithFirmContext($firmB, fn () => DB::table('integration_inbound_webhook_events')->where('id', $eventB->id)->first());
        $this->assertNotNull($reReadAsFirmB);
    }

    public function test_firm_a_cannot_insert_an_inbound_webhook_event_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $connectionB = FirmIntegration::factory()->forFirm($firmB)->create();
        $receipt = IntegrationWebhookReceipt::factory()->create();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB, $connectionB, $receipt) {
            DB::table('integration_inbound_webhook_events')->insert($this->rawRowAttributes($firmB, $connectionB, $receipt));
        });
    }

    public function test_ownership_cannot_be_reassigned_across_firms(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $connectionA = FirmIntegration::factory()->forFirm($firmA)->create();
        $event = $this->runWithFirmContext($firmA, fn () => IntegrationInboundWebhookEvent::factory()->forFirmIntegration($connectionA)->create());

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/row-level security policy|foreign key constraint/i');

        $this->runWithFirmContext($firmA, function () use ($event, $firmB) {
            DB::table('integration_inbound_webhook_events')->where('id', $event->id)->update(['firm_id' => $firmB->id]);
        });
    }

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();
        $connection = FirmIntegration::factory()->forFirm($firm)->create();

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->runWithFirmContext($firm, fn () => IntegrationInboundWebhookEvent::factory()->forFirmIntegration($connection)->create());

        $this->assertNoDatabaseTenantContext();
    }

    public function test_tenant_context_clears_after_exception(): void
    {
        $firm = Firm::factory()->create();

        try {
            $this->runWithFirmContext($firm, function () {
                throw new RuntimeException('simulated failure inside firm context');
            });
        } catch (RuntimeException $e) {
            // expected
        }

        $this->assertNoDatabaseTenantContext();
    }

    // ------------------------------------------------------------
    // 4. Migration rollback and reapplication
    // ------------------------------------------------------------

    public function test_migration_rollback_and_reapplication_restores_exact_prior_state(): void
    {
        $this->assertFileExists(base_path(self::TABLE_MIGRATION_PATH));
        $this->assertFileExists(base_path(self::RLS_MIGRATION_PATH));
        $this->assertTrue(Schema::hasTable('integration_inbound_webhook_events'));

        // The sync_runs composite FK (migration 060005) depends on this
        // table — it must roll back FIRST (frozen design §11 reverse
        // ordering), or dropping this table fails with "dependent
        // objects still exist".
        $dependentRollbackExit = Artisan::call('migrate:rollback', ['--path' => self::DEPENDENT_MIGRATION_PATH, '--force' => true]);
        $this->assertSame(0, $dependentRollbackExit, Artisan::output());

        // Checkpoint 9's integration_usage_records also FK-references this
        // table (see CP9_USAGE_RECORDS_MIGRATION_PATHS docblock above) —
        // it must roll back FIRST too, before this table's own migrations
        // below.
        // Checkpoint 4's provider_billable_call_reservations FK-references
        // integration_usage_records (see
        // CP4_PROVIDER_BILLABLE_RESERVATIONS_MIGRATION_PATHS docblock above) —
        // it must be rolled back first, before integration_usage_records itself.
        foreach (array_reverse(self::CP4_PROVIDER_BILLABLE_RESERVATIONS_MIGRATION_PATHS) as $path) {
            $exit = Artisan::call('migrate:rollback', ['--path' => $path, '--force' => true]);
            $this->assertSame(0, $exit, "migrate:rollback of {$path} (Checkpoint 4 provider_billable_call_reservations) failed: ".Artisan::output());
        }
        $this->assertFalse(Schema::hasTable('provider_billable_call_reservations'), 'provider_billable_call_reservations must not survive its own rollback.');

        foreach (array_reverse(self::CP9_USAGE_RECORDS_MIGRATION_PATHS) as $path) {
            $exit = Artisan::call('migrate:rollback', ['--path' => $path, '--force' => true]);
            $this->assertSame(0, $exit, "migrate:rollback of {$path} (Checkpoint 9 integration_usage_records) failed: ".Artisan::output());
        }
        $this->assertFalse(Schema::hasTable('integration_usage_records'), 'integration_usage_records must not survive its own rollback.');

        $rlsRollbackExit = Artisan::call('migrate:rollback', ['--path' => self::RLS_MIGRATION_PATH, '--force' => true]);
        $this->assertSame(0, $rlsRollbackExit, Artisan::output());

        $rowAfterRlsRollback = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'integration_inbound_webhook_events'");
        $this->assertFalse((bool) $rowAfterRlsRollback->relrowsecurity);
        $this->assertFalse((bool) $rowAfterRlsRollback->relforcerowsecurity);

        $policyAfterRollback = DB::selectOne(
            "select 1 from pg_policy where polrelid = 'integration_inbound_webhook_events'::regclass and polname = ?",
            [self::POLICY_NAME]
        );
        $this->assertNull($policyAfterRollback);

        $tableRollbackExit = Artisan::call('migrate:rollback', ['--path' => self::TABLE_MIGRATION_PATH, '--force' => true]);
        $this->assertSame(0, $tableRollbackExit, Artisan::output());

        $this->assertFalse(Schema::hasTable('integration_inbound_webhook_events'));

        $tableMigrateExit = Artisan::call('migrate', ['--path' => self::TABLE_MIGRATION_PATH, '--force' => true]);
        $this->assertSame(0, $tableMigrateExit, Artisan::output());

        $rlsMigrateExit = Artisan::call('migrate', ['--path' => self::RLS_MIGRATION_PATH, '--force' => true]);
        $this->assertSame(0, $rlsMigrateExit, Artisan::output());

        $dependentMigrateExit = Artisan::call('migrate', ['--path' => self::DEPENDENT_MIGRATION_PATH, '--force' => true]);
        $this->assertSame(0, $dependentMigrateExit, Artisan::output());

        // Reapply Checkpoint 9's integration_usage_records LAST — after
        // this table already exists again — in forward (creation) order.
        foreach (self::CP9_USAGE_RECORDS_MIGRATION_PATHS as $path) {
            $exit = Artisan::call('migrate', ['--path' => $path, '--force' => true]);
            $this->assertSame(0, $exit, "migrate of {$path} (Checkpoint 9 integration_usage_records) failed: ".Artisan::output());
        }
        $this->assertTrue(Schema::hasTable('integration_usage_records'), 'integration_usage_records must be restored by its own reapplication.');

        // Reapply Checkpoint 4's provider_billable_call_reservations LAST —
        // after integration_usage_records already exists again.
        foreach (self::CP4_PROVIDER_BILLABLE_RESERVATIONS_MIGRATION_PATHS as $path) {
            $exit = Artisan::call('migrate', ['--path' => $path, '--force' => true]);
            $this->assertSame(0, $exit, "migrate of {$path} (Checkpoint 4 provider_billable_call_reservations) failed: ".Artisan::output());
        }
        $this->assertTrue(Schema::hasTable('provider_billable_call_reservations'), 'provider_billable_call_reservations must be restored by its own reapplication.');

        $this->assertTrue(Schema::hasTable('integration_inbound_webhook_events'));

        $rowAfterReapply = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'integration_inbound_webhook_events'");
        $this->assertTrue((bool) $rowAfterReapply->relrowsecurity);
        $this->assertTrue((bool) $rowAfterReapply->relforcerowsecurity);

        $policiesAfterReapply = DB::select("select policyname from pg_policies where tablename = 'integration_inbound_webhook_events'");
        $this->assertCount(1, $policiesAfterReapply);
        $this->assertSame(self::POLICY_NAME, $policiesAfterReapply[0]->policyname);
    }

    public function test_migration_down_and_up_restores_exact_prior_state_via_direct_calls(): void
    {
        $this->assertTrue(Schema::hasTable('integration_inbound_webhook_events'));

        $dependentMigration = include base_path(self::DEPENDENT_MIGRATION_PATH);
        $rlsMigration = include base_path(self::RLS_MIGRATION_PATH);
        $tableMigration = include base_path(self::TABLE_MIGRATION_PATH);

        // Checkpoint 9's integration_usage_records also FK-references this
        // table (see CP9_USAGE_RECORDS_MIGRATION_PATHS docblock above).
        $usageRecordsMigrations = array_map(
            static fn (string $path) => include base_path($path),
            self::CP9_USAGE_RECORDS_MIGRATION_PATHS,
        );
        $providerBillableReservationsMigrations = array_map(
            static fn (string $path) => include base_path($path),
            self::CP4_PROVIDER_BILLABLE_RESERVATIONS_MIGRATION_PATHS,
        );
        // Checkpoint 4's provider_billable_call_reservations FK-references
        // integration_usage_records — it must be rolled back first, before
        // integration_usage_records itself.
        foreach (array_reverse($providerBillableReservationsMigrations) as $migration) {
            $migration->down();
        }
        $this->assertFalse(Schema::hasTable('provider_billable_call_reservations'));

        // Dependent migration (sync_runs FK) rolls back FIRST, then
        // integration_usage_records, then this table's own migrations.
        $dependentMigration->down();
        foreach (array_reverse($usageRecordsMigrations) as $migration) {
            $migration->down();
        }
        $this->assertFalse(Schema::hasTable('integration_usage_records'));

        $rlsMigration->down();
        $tableMigration->down();

        $this->assertFalse(Schema::hasTable('integration_inbound_webhook_events'));

        $tableMigration->up();
        $rlsMigration->up();

        // Rebuild Checkpoint 9's integration_usage_records LAST — after
        // this table already exists again.
        foreach ($usageRecordsMigrations as $migration) {
            $migration->up();
        }
        $this->assertTrue(Schema::hasTable('integration_usage_records'));

        // Reapply Checkpoint 4's provider_billable_call_reservations LAST —
        // after integration_usage_records already exists again.
        foreach ($providerBillableReservationsMigrations as $migration) {
            $migration->up();
        }
        $this->assertTrue(Schema::hasTable('provider_billable_call_reservations'));
        $dependentMigration->up();

        $this->assertTrue(Schema::hasTable('integration_inbound_webhook_events'));

        $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'integration_inbound_webhook_events'");
        $this->assertTrue((bool) $row->relrowsecurity);
        $this->assertTrue((bool) $row->relforcerowsecurity);

        $policies = DB::select("select policyname from pg_policies where tablename = 'integration_inbound_webhook_events'");
        $this->assertCount(1, $policies);
        $this->assertSame(self::POLICY_NAME, $policies[0]->policyname);
    }

    // ------------------------------------------------------------
    // 5. Model conventions
    // ------------------------------------------------------------

    public function test_model_table_resolves_to_integration_inbound_webhook_events(): void
    {
        $model = new IntegrationInboundWebhookEvent;

        $this->assertSame('integration_inbound_webhook_events', $model->getTable());
    }

    public function test_model_uses_belongs_to_tenant_trait(): void
    {
        $traits = class_uses_recursive(IntegrationInboundWebhookEvent::class);

        $this->assertArrayHasKey(BelongsToTenant::class, $traits);
    }

    public function test_model_has_the_tenant_global_scope_applied(): void
    {
        $model = new IntegrationInboundWebhookEvent;

        $this->assertArrayHasKey('tenant', $model->getGlobalScopes());
    }

    public function test_factory_produces_valid_non_colliding_rows(): void
    {
        $firm = Firm::factory()->create();
        $connection = FirmIntegration::factory()->forFirm($firm)->create();

        $events = $this->runWithFirmContext($firm, fn () => IntegrationInboundWebhookEvent::factory()->forFirmIntegration($connection)->count(3)->create());

        $this->assertSame(3, $events->pluck('id')->unique()->count());
        $this->assertSame(3, $events->pluck('provider_event_id')->unique()->count());
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function rawRowAttributes(Firm $firm, FirmIntegration $connection, IntegrationWebhookReceipt $receipt): array
    {
        return [
            'uuid' => (string) Str::uuid7(),
            'firm_id' => $firm->id,
            'firm_integration_id' => $connection->id,
            'receipt_id' => $receipt->id,
            'provider_key' => 'test',
            'provider_event_id' => (string) Str::uuid(),
            'receipt_body_hash' => $receipt->body_hash,
            'event_type' => 'test.resource.created',
            'payload_reference_json' => '{}',
            'payload_hash' => null,
            'status' => WebhookInboundEventStatus::Verified->value,
            'processing_attempts' => 0,
            'received_at' => now(),
            'retention_deadline' => now()->addDays(400),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
