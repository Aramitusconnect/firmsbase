<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Section 39A-3J — continuing the staged "Phase 1 RLS Enforcement
 * Activation" gate beyond Section 39A-3I's conflict_check_runs.
 * Permanently activates FORCE ROW LEVEL SECURITY for one of this
 * batch's four approved prepared tables: lead_sources.
 *
 * lead_sources is a small firm-scoped lookup table (code/name/
 * is_active) with no nested tenant-owned foreign keys of its own, so
 * there is no cross-firm-mismatch class of bug possible in its
 * factory beyond the bare firm_id itself — the only change required
 * alongside this migration is LeadSourceFactory's context-hold
 * create() retrofit (Section 39A-3A/39A-3F pattern) so a bare
 * LeadSource::factory()->create() can still insert once FORCE is
 * active.
 *
 * As with every prior batch in this arc, the down() migration
 * restores only the RLS-enabled-but-not-forced baseline for this one
 * table — it never drops the existing policy or disables RLS itself.
 */
return new class extends Migration
{
    private const TABLE = 'lead_sources';

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
