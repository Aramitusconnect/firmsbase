<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Section 39A-3L, Checkpoint 25 — permanently activates FORCE ROW LEVEL
 * SECURITY for contacts.
 *
 * contacts is tenant-owned (firm_id NOT NULL, carried directly on the
 * row via a foreignId('firm_id')->constrained('firms')->cascadeOnDelete()
 * column — see database/migrations/2026_07_05_600013_create_contacts_
 * table.php), RLS-enabled with a standard existing policy created by
 * this repo's Phase 2 preparation migration (2026_07_05_600024_extend_
 * row_level_security_to_phase_2_tenant_tables.php) — contacts_tenant_
 * isolation, USING firm_id = NULLIF(current_setting('app.current_firm_
 * id', true), '')::bigint, no separate WITH CHECK — unchanged by this
 * migration. client_id is a nullable foreign key (a contact can exist
 * independent of any client — see Contact model docblock). No unrelated
 * table's schema needed to change; parties (the sibling table addressed
 * by the same prerequisite remediation) is explicitly out of scope for
 * this checkpoint (Checkpoint 26, separate task) and its FORCE state is
 * untouched here.
 *
 * This checkpoint's application-code prerequisite (per-firm-iterate
 * fixes to ConflictCheckService::searchContacts()/searchMatterParties(),
 * ImportApplyService's Contact::create() arm, ImportDuplicateDetection
 * Service::detectContact(), and the ContactFactory context-hold create()
 * override) was already completed and committed ahead of this migration
 * (Section 39A-3L Phase B5, "contacts/parties FORCE RLS prerequisite
 * remediation"). This migration's own author independently re-verified
 * every production call site before writing it:
 *
 *   - grep -rln "Contact::|->contacts()" app/ returns: Firm.php and
 *     Client.php (hasMany(Contact::class) relation declarations only,
 *     never invoked as a standalone query outside the tenant-scoped
 *     Eloquent global scope already governed by BelongsToTenant),
 *     SignatureRequestRecipient.php (a belongsTo(Contact::class)
 *     relation declaration with zero live callers anywhere in app/ —
 *     confirmed via grep -rn "->contact\b" app/ excluding app/Models/,
 *     zero hits), EntityFieldCatalogMappingService.php (a read-only
 *     governance/mapping catalog that only references the Contact
 *     class name as metadata for a coverage report, never queries the
 *     table), ImportApplyService.php, ConflictCheckService.php, and
 *     ImportDuplicateDetectionService.php.
 *   - ConflictCheckService::searchContacts() (contacts) and
 *     searchMatterParties()'s Party half both already iterate $firmIds
 *     explicitly under their own runWithFirmContext($firmId, ...) call
 *     per firm, matching searchClients()'s established pattern — no
 *     single whereIn('firm_id', $firmIds) query remains for contacts.
 *   - ImportApplyService's ImportEntityType::Contact arm already wraps
 *     its Contact::create() call in runWithFirmContext($firm, ...).
 *   - ImportDuplicateDetectionService::detectContact() already wraps
 *     its entire body (including the Contact::query() read) in
 *     runWithFirmContext($firmId, ...).
 *   - ContactFactory::create() already carries the established context-
 *     hold override (setDatabaseTenantContextForFirmId(), matching
 *     ClientFactory's direct template); definition()'s client_id
 *     defaults to null, so the bare/default creation path cannot
 *     produce a cross-firm mismatch.
 *
 * No further production, factory, or policy change was required for
 * this checkpoint — the prerequisite batch was complete and correct.
 *
 * Known gap NOT fixed in this batch (stated plainly, not hidden):
 *   - No database-layer validation that a contacts row's client_id
 *     actually belongs to the same firm as its own firm_id column —
 *     the same accepted "RLS only checks this row's own firm_id"
 *     boundary as every prior checkpoint. Every production writer
 *     above always derives firm_id directly from an explicitly-passed
 *     Firm, so this gap has no known live trigger today; it is a
 *     database-layer constraint gap, not a demonstrated bug.
 *   - parties, contacts' sibling table under the same prerequisite
 *     remediation, remains RLS-enabled-but-not-forced; it is addressed
 *     by a separate checkpoint (Checkpoint 26), not this migration.
 *
 * As with every prior batch in this arc, the down() migration restores
 * only the RLS-enabled-but-not-forced baseline for this one table — it
 * never drops the existing policy or disables RLS itself.
 */
return new class extends Migration
{
    private const TABLE = 'contacts';

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
