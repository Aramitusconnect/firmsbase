<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Section 39A-3L, Checkpoint 11, Table Phase B — permanently activates
 * FORCE ROW LEVEL SECURITY for exactly one additional prepared table:
 * communication_consents.
 *
 * All three Phase A audits (rls-inventory-analyst, tenant-context-
 * auditor, security-reviewer — rls-policy-designer not used per an
 * established operational decision, since the policy already exists)
 * converged: firm_id is NOT NULL, direct ownership, standard policy
 * (communication_consents_tenant_isolation — FOR ALL USING firm_id =
 * NULLIF(current_setting('app.current_firm_id', true), '')::bigint,
 * created by the Phase 1 preparation migration) — unchanged by this
 * migration. No unrelated table's schema needed to change.
 * ConsentService::capture()/revoke() (the sole production write path
 * for this table) are fixed in this same batch to wrap their bodies in
 * runWithFirmContext(), and ClientPortalService::invite()'s
 * isGranted() precondition read is moved inside its existing
 * runWithFirmContext() wrap so the read and the write share one
 * continuous context (see each file's own diff).
 *
 * Known, explicitly NOT fixed in this batch (tracked separately):
 * communication_consents.client_id firm-ownership is not validated at
 * the app layer — ConsentService::capture() never checks that the
 * given client_id actually belongs to the given firm before insert.
 * FORCE RLS does not catch this (RLS only checks
 * communication_consents.firm_id itself, never a related row's
 * firm_id), so a cross-firm client_id reference remains possible
 * today. A separate follow-up task already tracks this data-integrity
 * gap.
 *
 * Also explicitly NOT addressed here, and explicitly deferred to
 * Checkpoint 12 (that table's own checkpoint): the sibling table
 * communication_consent_events (and CommunicationConsentEventFactory)
 * has a related, currently-latent cross-firm mismatch — its bare
 * definition() resolves communication_consent_id and firm_id via two
 * independent factory chains, and it is missing its own context-hold
 * create() override. It remains prepared-but-not-forced; forcing it is
 * out of scope for this checkpoint.
 *
 * As with every prior batch in this arc, the down() migration restores
 * only the RLS-enabled-but-not-forced baseline for this one table — it
 * never drops the existing policy or disables RLS itself.
 */
return new class extends Migration
{
    private const TABLE = 'communication_consents';

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
