<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Section 39A-3L, Checkpoint 20 — permanently activates FORCE ROW LEVEL
 * SECURITY for time_tracking_sessions.
 *
 * time_tracking_sessions is tenant-owned (firm_id NOT NULL, direct
 * ownership, standard existing policy created by this repo's Phase 3
 * preparation migration — unchanged by this migration). No unrelated
 * table's schema needed to change.
 *
 * Central finding — the duplicate-billing risk this checkpoint closes:
 * time_tracking_sessions (this checkpoint) and time_entries (still
 * RLS-enabled-but-not-forced, a deliberate, separate future checkpoint)
 * are asymmetrically FORCE-protected the moment this migration runs.
 * TimeTrackingService::stop() writes to both: it UPDATEs the existing
 * TimeTrackingSession row to Stopped and then INSERTs one TimeEntry
 * from the accumulated total. Before this checkpoint's accompanying
 * service fix, stop() ran with no ambient tenant context. Once
 * time_tracking_sessions is forced, an unwrapped UPDATE against it
 * silently affects zero rows (FORCE RLS's USING/WITH CHECK filters it
 * out — not an error), while the immediately-following
 * TimeEntry::create() against the still-unforced time_entries table
 * succeeds unconditionally (table owner bypasses RLS on a non-forced
 * table). Net effect without the fix: a valid, committed TimeEntry is
 * created while the session silently remains un-stopped in the
 * database — a second stop() call later (the session re-fetched fresh,
 * still showing its prior, un-updated status) would create a SECOND
 * TimeEntry for overlapping seconds, i.e. silent duplicate billing in a
 * legal billing system. This was latent (no live route/job/console
 * caller as of this checkpoint) but actively exercised by
 * TimeTrackingServiceTest.php with real assertions, meeting this arc's
 * established "live application logic" bar.
 *
 * The fix (app/Services/TimeTrackingService.php) wraps all four methods
 * — start(), pause(), resume(), stop() — in runWithFirmContext(), since
 * none of the four had any tenant context before. stop() keeps
 * DB::transaction() as the OUTER call with runWithFirmContext() nested
 * inside it (never the reverse, matching TenantContextService's own
 * "invoked from inside an existing transaction is a savepoint" design
 * and this arc's established convention from Checkpoints 17-19).
 * pause()/resume() key their context off $session->firm_id, a plain
 * in-memory scalar already loaded on the model — not a relation load —
 * so no read-before-context-established trap exists. This closes the
 * duplicate-billing risk regardless of the time_entries asymmetry,
 * since the session UPDATE itself now correctly succeeds under
 * context, independent of when time_entries gets its own future FORCE
 * checkpoint.
 *
 * TimeTrackingSessionFactory's bare create() path was also fixed with
 * the same context-hold pattern used by every FORCE-RLS factory since
 * 39A-3A, so a bare TimeTrackingSession::factory()->create() continues
 * to work correctly whether or not the caller already has an ambient
 * tenant context active.
 *
 * Known, explicitly NOT fixed in this batch (dormant, no live caller
 * today, same "dormant landmine" pattern recorded in prior checkpoints):
 *
 *   - TimeTrackingService::start() accepts ?Matter $matter and
 *     ?Client $client with no validation that either belongs to the
 *     same $firm being passed in. Nothing in the codebase calls
 *     start() with attacker-influenced matter/client input today; this
 *     is recorded for whenever start() gets a real production caller,
 *     not actioned here.
 *   - TimeTrackingSessionFactory::definition() resolves user_id via an
 *     independent User::factory() rather than deriving it from the
 *     same firm as firm_id — a data-integrity (not RLS) concern that
 *     matches an already-accepted pattern elsewhere in this codebase's
 *     factories, deferred rather than silently "repaired" here.
 *
 * As with every prior batch in this arc, the down() migration restores
 * only the RLS-enabled-but-not-forced baseline for this one table — it
 * never drops the existing policy or disables RLS itself.
 */
return new class extends Migration
{
    private const TABLE = 'time_tracking_sessions';

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
