<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Section 39A-3K — Batched FORCE RLS Rollout 02 (2 of 5). Permanently
 * activates FORCE ROW LEVEL SECURITY for document_chase_rules.
 *
 * The one uncontexted read reported in Phase 1
 * (DocumentChaseSchedulerService::applicableRule(),
 * app/Services/DocumentChaseSchedulerService.php) was traced to every
 * caller and every caller of DocumentChaseSchedulerService/
 * DocumentChaseService itself: no controller, Filament page, API
 * route, job, or console command reaches it anywhere in this codebase
 * today (there is no app/Console/Kernel.php, no app/Console/Commands
 * directory, routes/console.php only defines the default "inspire"
 * command, and bootstrap/app.php registers no scheduler at all). The
 * only real call sites are tests/Feature/DocumentChase/
 * DocumentChaseSchedulerServiceTest.php and
 * DocumentChaseServiceTest.php. This read path is therefore genuinely
 * unreachable in production today, so no runWithFirmContext() wiring
 * was added to DocumentChaseSchedulerService for this batch — see the
 * batch report for the full trace.
 *
 * DocumentChaseRuleFactory was given the same context-hold create()
 * override every prior FORCE-RLS factory uses (see the batch report)
 * so a bare DocumentChaseRule::factory()->create() keeps working under
 * FORCE. escalate_to_user_id/created_by reference the non-tenant users
 * table, not a tenant-owned parent, so there was no ownership-
 * consistency bug to fix here.
 *
 * As established in every prior FORCE batch since Section 39A-3F,
 * PostgreSQL foreign-key constraint checks bypass row level security
 * entirely, so forcing this table does not affect firms/users
 * inserts/updates themselves.
 *
 * The down() migration restores only the RLS-enabled-but-not-forced
 * baseline for this one table — it never drops the existing policy or
 * disables RLS itself.
 */
return new class extends Migration
{
    private const TABLE = 'document_chase_rules';

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
