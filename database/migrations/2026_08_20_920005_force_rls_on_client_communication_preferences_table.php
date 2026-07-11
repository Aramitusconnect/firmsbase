<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Section 39A-3K — Batched FORCE RLS Rollout 02 (5 of 5). Permanently
 * activates FORCE ROW LEVEL SECURITY for client_communication_preferences.
 *
 * ClientCommunicationPreferenceFactory's forClient()/withClient()
 * states were read and verified directly (not assumed from the prior
 * audit) to already derive firm_id from the client consistently
 * (forClient() sets firm_id => $client->firm_id alongside client_id;
 * withClient() creates its own client first and reads firm_id back off
 * of it) — no cross-firm mismatch was found, so no factory
 * ownership-consistency fix was needed here. It was given the same
 * context-hold create() override every prior FORCE-RLS factory uses so
 * a bare ClientCommunicationPreference::factory()->create() keeps
 * working under FORCE.
 *
 * The two production read call sites found in prior audits
 * (NotificationEligibilityService::check() and
 * PaymentPlanDunningService::checkAndLog()) were traced to every one of
 * their own callers: NotificationEligibilityService::check() is only
 * reached via NotificationDispatchService::dispatch() and
 * DocumentChaseService::checkAndLog(), and PaymentPlanDunningService::
 * checkAndLog() has no caller of its own — none of these have any
 * production caller (controller, Filament page, job, or command)
 * anywhere in this codebase today; every real call site found is a
 * test. This read path is therefore genuinely unreachable in
 * production today, so no runWithFirmContext() wiring was added to
 * either service for this batch — see the batch report for the full
 * trace and for what rls-test-verifier must add in Phase 3A (a focused
 * test that re-queries the client after creation and proves its
 * firm_id matches the preference row's firm_id).
 *
 * As established in every prior FORCE batch since Section 39A-3F,
 * PostgreSQL foreign-key constraint checks bypass row level security
 * entirely, so forcing this table does not affect firms/clients
 * inserts/updates themselves.
 *
 * The down() migration restores only the RLS-enabled-but-not-forced
 * baseline for this one table — it never drops the existing policy or
 * disables RLS itself.
 */
return new class extends Migration
{
    private const TABLE = 'client_communication_preferences';

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
