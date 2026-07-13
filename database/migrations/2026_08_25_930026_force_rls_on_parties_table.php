<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Section 39A-3L, Checkpoint 26 — permanently activates FORCE ROW LEVEL
 * SECURITY for parties.
 *
 * parties is tenant-owned (firm_id NOT NULL, carried directly on the row
 * via a foreignId('firm_id')->constrained('firms')->cascadeOnDelete()
 * column — see database/migrations/2026_07_05_600014_create_parties_
 * table.php), RLS-enabled with a standard existing policy created by
 * this repo's Phase 2 preparation migration (2026_07_05_600024_extend_
 * row_level_security_to_phase_2_tenant_tables.php) — parties_tenant_
 * isolation, USING firm_id = NULLIF(current_setting('app.current_firm_
 * id', true), '')::bigint, no separate WITH CHECK — unchanged by this
 * migration. parties has no nullable/other tenant foreign key of its
 * own (unlike contacts' client_id) — just firm_id — so there is no
 * transitive cross-firm foreign-key surface on this table at all. No
 * unrelated table's schema needed to change; contacts (the sibling
 * table addressed by the same prerequisite remediation) was already
 * forced separately (Checkpoint 25, committed ahead of this one) and is
 * untouched here.
 *
 * This checkpoint's application-code prerequisite (per-firm-iterate
 * fixes to ConflictCheckService::searchParties()/the Party half of
 * searchMatterParties() including its ->with('party') eager-load
 * replacement with an in-PHP name map, ImportApplyService's
 * Party::create() arm, ImportDuplicateDetectionService::detectParty(),
 * and the PartyFactory context-hold create() override) was already
 * completed and committed ahead of this migration (Section 39A-3L Phase
 * B5, "contacts/parties FORCE RLS prerequisite remediation"). This
 * migration's own author independently re-verified every production
 * call site before writing it:
 *
 *   - grep -rln "Party::|->parties()|->party\b" app/ returns:
 *     MatterParty.php, Matter.php, Firm.php, SignatureRequestRecipient.
 *     php, Party.php (relation declarations only — hasMany/belongsTo —
 *     never invoked as a standalone query outside the tenant-scoped
 *     Eloquent global scope already governed by BelongsToTenant;
 *     SignatureRequestRecipient's belongsTo(Party::class) has zero live
 *     callers anywhere in app/ — confirmed via grep -rn "->party\b"
 *     app/ excluding app/Models/, zero hits), EntityFieldCatalogMapping
 *     Service.php (a read-only governance/mapping catalog whose
 *     ->parties() is its own private method building a coverage report
 *     row, never a query against the parties table), ImportApplyService.
 *     php, ConflictCheckService.php, and ImportDuplicateDetectionService.
 *     php.
 *   - ConflictCheckService::searchParties() and searchMatterParties()'s
 *     Party half both already iterate $firmIds explicitly under their
 *     own runWithFirmContext($firmId, ...) call per firm, matching
 *     searchContacts()'s established pattern — no single whereIn(
 *     'firm_id', $firmIds) query remains for parties. The final
 *     composed MatterParty query in searchMatterParties() deliberately
 *     omits ->with('party') (an eager load there would run after every
 *     runWithFirmContext() call has already cleared its context in its
 *     own finally block, so it would see zero rows under RLS); party
 *     names are instead resolved from the already-fetched, already-
 *     context-wrapped $parties collection via an in-PHP
 *     [$partyId => $partyName] map — confirmed directly in the current
 *     source, not merely asserted by the prerequisite batch.
 *   - ImportApplyService's ImportEntityType::Party arm already wraps
 *     its Party::create() call in runWithFirmContext($firm, ...).
 *   - ImportDuplicateDetectionService::detectParty() already wraps its
 *     entire body (including the Party::query() read) in
 *     runWithFirmContext($firmId, ...).
 *   - PartyFactory::create() already carries the established context-
 *     hold override (setDatabaseTenantContextForFirmId(), matching
 *     ContactFactory/ClientFactory's direct template); definition()
 *     has no nested tenant-owned foreign key at all (parties carries
 *     only firm_id, no client_id-equivalent column), so the bare/
 *     default creation path cannot produce a cross-firm mismatch.
 *
 * No further production, factory, or policy change was required for
 * this checkpoint — the prerequisite batch was complete and correct.
 *
 * Known gap NOT fixed in this batch (stated plainly, not hidden):
 *   - None specific to parties beyond the standard accepted "RLS only
 *     checks this row's own firm_id" boundary already documented for
 *     every prior checkpoint. Unlike contacts' client_id, parties has
 *     no other tenant foreign key on the row at all, so there is no
 *     transitive cross-firm foreign-key surface to flag here.
 *   - This is the second and final of the two contacts/parties
 *     checkpoints authorized by the Phase B5 prerequisite design;
 *     contacts was already forced separately (Checkpoint 25).
 *
 * As with every prior batch in this arc, the down() migration restores
 * only the RLS-enabled-but-not-forced baseline for this one table — it
 * never drops the existing policy or disables RLS itself.
 */
return new class extends Migration
{
    private const TABLE = 'parties';

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
