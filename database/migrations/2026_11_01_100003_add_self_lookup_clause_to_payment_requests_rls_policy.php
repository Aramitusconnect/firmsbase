<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Payment Link / QR Routing phase — the RLS bootstrap gap for the
 * genuinely public payment page, resolved the exact same way
 * firm_users_self_lookup and clients_self_lookup already resolve it
 * for their own tables (see those two migrations' own docblocks for
 * the full reasoning, byte-for-byte reused here).
 *
 * A visitor holds nothing but payment_requests.uuid (from a signed
 * URL/QR code) — there is no firm context to activate yet, since
 * discovering the firm IS what this lookup is for. A SEPARATE,
 * ADDITIONAL `FOR SELECT`-only policy widens what may be READ, never
 * what may be WRITTEN — PostgreSQL combines multiple permissive
 * policies for the same command with OR, and a FOR SELECT policy is
 * never consulted for INSERT/UPDATE/DELETE, so
 * payment_requests_tenant_isolation (from the create-table migration)
 * remains the sole gate on every write regardless of this policy.
 *
 * uuid = current_setting('app.current_payment_request_uuid') matches
 * at most one row by construction (payment_requests.uuid carries its
 * own UNIQUE constraint) — this policy can never disclose any OTHER
 * firm's payment request, any listing, or any table besides this
 * exact row.
 */
return new class extends Migration
{
    private const TABLE = 'payment_requests';

    private const SELF_LOOKUP_POLICY = 'payment_requests_self_lookup';

    public function up(): void
    {
        DB::statement(<<<SQL
            CREATE POLICY {$this->quoteIdentifier(self::SELF_LOOKUP_POLICY)}
            ON {$this->quoteIdentifier(self::TABLE)}
            FOR SELECT
            USING (
                uuid = NULLIF(current_setting('app.current_payment_request_uuid', true), '')::uuid
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
