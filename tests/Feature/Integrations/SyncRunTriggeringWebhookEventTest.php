<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Integrations\Enums\SyncDirection;
use App\Integrations\Enums\SyncTriggerSource;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationInboundWebhookEvent;
use App\Integrations\Services\SyncRunService;
use App\Models\Firm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * SyncRunTriggeringWebhookEventTest — Checkpoint 7 §11:
 * `integration_sync_runs.triggering_webhook_event_id`, its composite
 * FK, and the explicit column-list
 * `ON DELETE SET NULL (triggering_webhook_event_id)` behavior (proactively
 * fixing the same bug class this codebase has already hit four times —
 * see the migration's own docblock).
 */
final class SyncRunTriggeringWebhookEventTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_column_exists_and_is_nullable(): void
    {
        $this->assertTrue(Schema::hasColumn('integration_sync_runs', 'triggering_webhook_event_id'));
    }

    public function test_the_column_is_nullable_and_null_by_default(): void
    {
        $firm = Firm::factory()->create();
        $connection = FirmIntegration::factory()->forFirm($firm)->create();

        $run = $this->runWithFirmContext(
            $firm,
            fn () => (new SyncRunService())->startRun($connection, 'contact', SyncDirection::Inbound, SyncTriggerSource::Connect),
        );

        $this->assertNull($run->triggering_webhook_event_id);
    }

    public function test_start_run_accepts_an_optional_triggering_webhook_event_id_and_persists_it(): void
    {
        $firm = Firm::factory()->create();
        $connection = FirmIntegration::factory()->forFirm($firm)->create();
        $event = $this->runWithFirmContext($firm, fn () => IntegrationInboundWebhookEvent::factory()->forFirmIntegration($connection)->create());

        $run = $this->runWithFirmContext(
            $firm,
            fn () => (new SyncRunService())->startRun(
                $connection,
                'contact',
                SyncDirection::Inbound,
                SyncTriggerSource::Webhook,
                null,
                null,
                $event->id,
            ),
        );

        $this->assertSame($event->id, $run->triggering_webhook_event_id);
    }

    public function test_the_composite_foreign_key_exists_in_the_catalog(): void
    {
        $row = DB::selectOne(
            "select conname from pg_constraint where conname = 'integration_sync_runs_triggering_webhook_event_fk' and conrelid = 'integration_sync_runs'::regclass"
        );

        $this->assertNotNull($row);
    }

    public function test_the_composite_foreign_key_rejects_a_triggering_webhook_event_id_belonging_to_a_different_firm(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $connectionA = FirmIntegration::factory()->forFirm($firmA)->create();
        $connectionB = FirmIntegration::factory()->forFirm($firmB)->create();
        $eventB = $this->runWithFirmContext($firmB, fn () => IntegrationInboundWebhookEvent::factory()->forFirmIntegration($connectionB)->create());

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->expectExceptionMessageMatches('/foreign key constraint/i');

        $this->runWithFirmContext($firmA, function () use ($firmA, $connectionA, $eventB) {
            (new SyncRunService())->startRun($connectionA, 'contact', SyncDirection::Inbound, SyncTriggerSource::Connect, null, null, $eventB->id);
        });
    }

    /**
     * The core bug-class regression guard (this checkpoint's docblock
     * explicitly names it the fifth occurrence): deleting the
     * referenced event must null ONLY triggering_webhook_event_id,
     * never firm_id, and never delete the whole sync_runs row.
     */
    public function test_deleting_the_referenced_event_nulls_only_the_triggering_webhook_event_id_column(): void
    {
        $firm = Firm::factory()->create();
        $connection = FirmIntegration::factory()->forFirm($firm)->create();
        $event = $this->runWithFirmContext($firm, fn () => IntegrationInboundWebhookEvent::factory()->forFirmIntegration($connection)->create());

        $run = $this->runWithFirmContext(
            $firm,
            fn () => (new SyncRunService())->startRun($connection, 'contact', SyncDirection::Inbound, SyncTriggerSource::Connect, null, null, $event->id),
        );

        DB::table('integration_inbound_webhook_events')->where('id', $event->id)->delete();

        $fresh = $this->runWithFirmContext($firm, fn () => DB::table('integration_sync_runs')->where('id', $run->id)->first());

        $this->assertNotNull($fresh, 'The sync_runs row itself must survive — only the pointer column is nulled.');
        $this->assertNull($fresh->triggering_webhook_event_id);
        $this->assertSame($firm->id, $fresh->firm_id, 'firm_id must be completely untouched.');
        $this->assertSame($connection->id, $fresh->firm_integration_id);
    }

    public function test_the_migration_uses_the_explicit_column_list_on_delete_set_null_syntax_not_the_fluent_shorthand(): void
    {
        $source = file_get_contents(base_path('database/migrations/2026_09_06_060005_add_triggering_webhook_event_id_to_integration_sync_runs_table.php'));
        $this->assertNotFalse($source);

        $this->assertStringContainsString('ON DELETE SET NULL (triggering_webhook_event_id)', $source);

        // Isolate the up()/down() method bodies (the executable code)
        // from the surrounding class docblock, which legitimately
        // quotes the fluent ->nullOnDelete() shorthand BY NAME in prose
        // to explain why it is deliberately not used — a whole-file
        // substring check would false-positive on that explanatory
        // text.
        $classBodyStart = strpos($source, 'return new class extends Migration');
        $this->assertNotFalse($classBodyStart, 'Could not locate the migration class body to isolate from its docblock.');
        $executableCode = substr($source, $classBodyStart);

        $this->assertStringNotContainsString(
            '->nullOnDelete()',
            $executableCode,
            'The fluent nullOnDelete() shorthand cannot express a column list and would null the entire composite tuple, including firm_id.'
        );
    }

    public function test_migration_rollback_and_reapplication_restores_the_column_and_index(): void
    {
        $path = 'database/migrations/2026_09_06_060005_add_triggering_webhook_event_id_to_integration_sync_runs_table.php';

        $migration = include base_path($path);
        $migration->down();

        $this->assertFalse(Schema::hasColumn('integration_sync_runs', 'triggering_webhook_event_id'));

        $migration->up();

        $this->assertTrue(Schema::hasColumn('integration_sync_runs', 'triggering_webhook_event_id'));

        $fk = DB::selectOne(
            "select conname from pg_constraint where conname = 'integration_sync_runs_triggering_webhook_event_fk' and conrelid = 'integration_sync_runs'::regclass"
        );
        $this->assertNotNull($fk);
    }
}
