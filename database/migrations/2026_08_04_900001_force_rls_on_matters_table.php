<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Section 39A-3F — sixth batch of the staged "Phase 1 RLS Enforcement
 * Activation" gate. Permanently activates FORCE ROW LEVEL SECURITY for
 * exactly one additional prepared table: matters.
 *
 * This table carries genuinely higher risk than the five already
 * forced (clients, firm_users, documents, deadlines, tasks):
 * MatterFactory's definition() previously created its firm and its
 * client as two independent random Firm::factory()/Client::factory()
 * calls, so a bare Matter::factory()->create() produced a matter whose
 * client belonged to an unrelated firm. That mismatch is fixed in this
 * same batch (see MatterFactory's rewritten definition(), which now
 * generates one firm and ties both the matter and its nested client to
 * it) before this migration is safe to apply.
 *
 * Verified separately (via direct psql/tinker test against this
 * database) that PostgreSQL foreign-key constraint checks bypass row
 * level security entirely — inserting a child row (e.g. a
 * conflict_check_runs row) with a matter_id referencing a currently
 * invisible (no session context set) FORCE-RLS'd matters row succeeds
 * without error. This means forcing matters does NOT break child-table
 * inserts (tasks.matter_id, deadlines.matter_id, documents.matter_id,
 * document_requests.matter_id, matter_parties.matter_id, etc.) — only
 * direct reads/writes/deletes against the matters table itself are
 * affected, and those real call sites are fixed alongside this
 * migration (ConflictCheckService, FirmCommandCenterAggregationService,
 * ImportApplyService, ImportDuplicateDetectionService,
 * MatterOpeningService, ProductionPilotWorkflowService,
 * MatterReadinessService, and WebhookEventRecorderService's single
 * payload-build choke point).
 *
 * invoices and payments remain deferred: their factories still nest
 * Client::factory() directly inside definition() the same way matters'
 * factory used to, so their true insert-time blast radius stays
 * masked/unproven until that cascade is explicitly fixed in a
 * dedicated later batch (39A-3G/H).
 *
 * As with every prior batch in this arc, the down() migration restores
 * only the RLS-enabled-but-not-forced baseline for this one table — it
 * never drops the existing policy or disables RLS itself.
 */
return new class extends Migration
{
    private const TABLE = 'matters';

    public function up(): void
    {
        DB::statement('ALTER TABLE '.$this->quoteIdentifier(self::TABLE).' FORCE ROW LEVEL SECURITY');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE '.$this->quoteIdentifier(self::TABLE).' NO FORCE ROW LEVEL SECURITY');
    }

    private function quoteIdentifier(string $table): string
    {
        if (! preg_match('/^[a-z_][a-z0-9_]*$/', $table)) {
            throw new \RuntimeException("Refusing to activate FORCE RLS on an unsafe/unexpected identifier: {$table}");
        }

        return '"'.$table.'"';
    }
};
