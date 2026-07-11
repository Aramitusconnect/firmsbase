<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Section 39A-3L, Checkpoint 10, Table Phase B — permanently activates
 * FORCE ROW LEVEL SECURITY for exactly one additional prepared table:
 * document_requests.
 *
 * Three Phase A audits converged on a LARGER-than-usual scope for this
 * table (similar to Checkpoint 4's expanded-scope precedent): firm_id
 * is NOT NULL, direct ownership, standard policy
 * (document_requests_tenant_isolation — FOR ALL USING firm_id =
 * NULLIF(current_setting('app.current_firm_id', true), '')::bigint) —
 * unchanged by this migration. No unrelated table's schema or policy
 * needed to change. document_request_items and document_chase_events
 * carry no firm_id of their own (scoped transitively through their
 * parent, per the Phase 4 RLS-preparation migration's own docblock),
 * so forcing document_requests directly breaks every consumer that
 * lazy-loads $item->documentRequest (or queries document_requests via
 * a whereHas from an unrelated model) without an active tenant
 * context — DocumentRequestService's 7 single-item mutators,
 * DocumentChaseService's checkAndLog()/logEvent()/escalate()/pause()/
 * resume(), ReadinessScorecardRegistry's documents_approved component,
 * and MobilePortalReadinessService's documentChecklistAvailable() are
 * all fixed in this same batch (see each file's own diff/docblock).
 *
 * Known, explicitly NOT fixed in this batch (tracked separately):
 * document_requests.client_id/matter_id firm-ownership is not
 * validated at the app layer — DocumentRequestService::create() never
 * checks $client->firm_id === $firm->id or $matter?->firm_id ===
 * $firm->id before insert. FORCE RLS does not catch this (RLS only
 * checks document_requests.firm_id itself, never a related row's
 * firm_id), so a cross-firm client/matter reference remains possible
 * today. A separate follow-up task already tracks this data-integrity
 * gap.
 *
 * Two more pre-existing, unrelated issues were found in passing during
 * this checkpoint's audit and are also explicitly NOT fixed here,
 * tracked separately: (1) DocumentReplacementService::replaceWith()
 * updates $original->documentRequestItem's status directly but never
 * calls DocumentRequestService's parent-status recompute, so a
 * document_requests row's status can go stale after a replacement —
 * a pre-existing business-logic bug, not caused by RLS/FORCE. (2)
 * TaskDependencyService::addDependency() (an unrelated table, tasks)
 * calls refreshBlockedStatus() from inside its own active
 * runWithFirmContext() wrap, and refreshBlockedStatus() itself opens a
 * second, nested runWithFirmContext() call — a genuine "decoy wrap"
 * bug, currently harmless only because nothing after that inner call
 * needs DB access.
 *
 * DocumentRequestFactory's create() override adds the same context-
 * hold pattern as DocumentChaseRuleFactory/SeatAllocationFactory (see
 * those files' prior Checkpoint diffs) so a bare
 * DocumentRequest::factory()->create() keeps working once FORCE lands.
 * definition() itself is also fixed in this batch — firm_id and
 * client_id were previously two independent random Factory chains
 * (the same bug class as Checkpoints 5/7/8/9), now derived from one
 * shared Client.
 *
 * As with every prior batch in this arc, the down() migration restores
 * only the RLS-enabled-but-not-forced baseline for this one table — it
 * never drops the existing policy or disables RLS itself.
 */
return new class extends Migration
{
    private const TABLE = 'document_requests';

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
