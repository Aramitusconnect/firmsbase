<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Non-payment completion program, e-signature signer-facing flow — the
 * RLS bootstrap gap for the genuinely public signer page, resolved the
 * exact same way payment_requests_self_lookup/clients_self_lookup/
 * marketplace_intakes_self_lookup already resolve it for their own
 * tables (see those migrations' own docblocks for the full reasoning,
 * byte-for-byte reused here — most directly
 * 2026_11_01_100003_add_self_lookup_clause_to_payment_requests_rls_policy.php).
 *
 * A signer holds nothing but signature_request_recipients.uuid (from a
 * link embedded in an email) plus a raw bearer token they present as a
 * `?token=` query parameter — there is no firm context to activate yet,
 * since discovering the firm IS what this lookup is for. This exactly
 * matches the "forward-looking design constraint" flagged (but left
 * unresolved — no such route existed yet) in this table's own base RLS
 * migration, 2026_08_27_950036_prepare_row_level_security_and_force_rls_
 * on_signature_request_recipients_table.php: "A future public/signed-
 * link recipient-facing route ... will have no authenticated User at
 * all ... Whoever builds that future controller MUST explicitly derive
 * firm_id from the recipient's own signatureRequest ... and establish
 * context themselves before invoking any SignatureRecipientWorkflowService
 * method." This migration is the missing half of that: it grants ONLY
 * "find the one signature_request_recipients row with this exact
 * uuid" — the caller (SignatureRecipientController) is still
 * responsible for the second half: verifying the caller-supplied raw
 * token against the resolved row's own access_token_hash via
 * hash_equals() BEFORE trusting the row for anything, and for every
 * subsequent read/write going through a normal
 * TenantContextService::runWithFirmContext($recipient->firm_id, ...)
 * once firm_id is known — this self-lookup clause deliberately proves
 * nothing about the token, only about the uuid.
 *
 * A SEPARATE, ADDITIONAL `FOR SELECT`-only policy widens what may be
 * READ, never what may be WRITTEN — PostgreSQL combines multiple
 * permissive policies for the same command with OR, and a FOR SELECT
 * policy is never consulted for INSERT/UPDATE/DELETE, so
 * signature_request_recipients_tenant_isolation (from
 * 2026_08_27_950036) remains the sole gate on every write regardless of
 * this policy — a signer's own consent()/sign()/decline()/view() calls
 * still only succeed once SignatureRecipientWorkflowService establishes
 * REAL app.current_firm_id context (via runWithFirmContext(), keyed off
 * the now-resolved $recipient->firm_id), exactly like every other
 * caller of those methods.
 *
 * uuid = current_setting('app.current_signature_recipient_uuid') matches
 * at most one row by construction (signature_request_recipients.uuid
 * carries its own UNIQUE constraint via HasPublicUuid) — this policy
 * can never disclose any OTHER recipient row, any listing, or any other
 * table besides this exact row.
 */
return new class extends Migration
{
    private const TABLE = 'signature_request_recipients';

    private const SELF_LOOKUP_POLICY = 'signature_request_recipients_self_lookup';

    public function up(): void
    {
        DB::statement(<<<SQL
            CREATE POLICY {$this->quoteIdentifier(self::SELF_LOOKUP_POLICY)}
            ON {$this->quoteIdentifier(self::TABLE)}
            FOR SELECT
            USING (
                uuid = NULLIF(current_setting('app.current_signature_recipient_uuid', true), '')::uuid
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement("DROP POLICY IF EXISTS {$this->quoteIdentifier(self::SELF_LOOKUP_POLICY)} ON {$this->quoteIdentifier(self::TABLE)}");
    }

    private function quoteIdentifier(string $identifier): string
    {
        if (! preg_match('/^[a-z_][a-z0-9_]*$/', $identifier)) {
            throw new RuntimeException("Refusing to operate on an unsafe/unexpected identifier: {$identifier}");
        }

        return '"'.$identifier.'"';
    }
};
