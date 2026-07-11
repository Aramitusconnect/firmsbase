<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Section 39A-3K — Batched FORCE RLS Rollout 02 (4 of 5). Permanently
 * activates FORCE ROW LEVEL SECURITY for calendar_events.
 *
 * calendar_events has two tenant-owned parent relationships:
 * matter_id (a real FK, nullable) and the polymorphic subject_type/
 * subject_id (no FK, points at Deadline/Task in production today, both
 * BelongsToTenant). CalendarEventService previously trusted whatever
 * $firm the caller passed with no cross-check against either parent's
 * own firm_id; it now fails closed via a narrow assertBelongsToFirm()
 * guard on both createFor() and createStandalone() rather than
 * silently writing a cross-firm-mismatched row — see
 * app/Services/CalendarEventService.php's own docblock.
 *
 * CalendarEventService is not self-wrapped in runWithFirmContext(): its
 * one production call site, DeadlineService::create(), already wraps
 * the whole operation (deadline create + linked calendar event create +
 * the trailing $deadline->fresh() read) in runWithFirmContext($firm,
 * ...); nesting a second wrap inside CalendarEventService would clear
 * that outer context before DeadlineService's own fresh() read runs.
 * createStandalone() has no production caller today — a future caller
 * must establish context itself, the same way DeadlineService does for
 * createFor().
 *
 * CalendarEventFactory was given the same context-hold create()
 * override every prior FORCE-RLS factory uses, PLUS a root-cause fix:
 * its bare definition() already only ever produces matter_id=null/
 * subject=null (so the default path was already safe), but its
 * forSubject() state previously set subject_type/subject_id without
 * ever deriving firm_id from the subject, which could produce a
 * calendar event whose firm disagreed with its own subject. forSubject()
 * now takes the real subject model (not a bare type/id pair) and
 * derives firm_id from it; a new forMatter() state does the same for
 * matter_id. Neither state had any production or test caller before
 * this batch (confirmed by direct repository search), so this is a
 * pure fix with no behavior-breaking signature change in practice.
 *
 * As established in every prior FORCE batch since Section 39A-3F,
 * PostgreSQL foreign-key constraint checks bypass row level security
 * entirely, so forcing this table does not affect matters/firms
 * inserts/updates themselves.
 *
 * The down() migration restores only the RLS-enabled-but-not-forced
 * baseline for this one table — it never drops the existing policy or
 * disables RLS itself.
 */
return new class extends Migration
{
    private const TABLE = 'calendar_events';

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
