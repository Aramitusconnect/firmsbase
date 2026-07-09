<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Section 39A-3E — fifth batch of the staged "Phase 1 RLS Enforcement
 * Activation" gate. Permanently activates FORCE ROW LEVEL SECURITY for
 * exactly one additional prepared table: tasks.
 *
 * Selected over the other remaining prepared tables because inspection
 * confirmed: TaskFactory's definition() is cascade-free (firm_id via
 * Firm::factory(), matter_id/client_id default null — no nested
 * tenant-owned factory calls), there is no reverse-cascade risk from
 * another factory creating a Task as an undeclared side effect, and the
 * real production call-site surface is small and fully enumerable
 * (TaskService, TaskDependencyService, MatterReadinessService via
 * ReadinessScorecardRegistry, FirmCommandCenterAggregationService).
 *
 * matters, invoices, and payments remain deferred: their factories
 * still nest a Client::factory() call directly inside definition(),
 * which means their true insert-time blast radius stays masked/unproven
 * until that cascade is explicitly fixed in a dedicated later batch
 * (39A-3F/G/H).
 *
 * As with every prior batch in this arc, the down() migration restores
 * only the RLS-enabled-but-not-forced baseline for this one table — it
 * never drops the existing policy or disables RLS itself.
 */
return new class extends Migration
{
    private const TABLE = 'tasks';

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
