<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Internal login/panel access wiring surfaced a genuine bootstrap gap:
 * firm_users has permanent FORCE ROW LEVEL SECURITY (Section 39A-3B),
 * and its policy is a single-column match —
 * firm_id = current_setting('app.current_firm_id') — which means an
 * authenticated user's OWN firm_users row is invisible to the app
 * until a matching firm context is already active. But the whole point
 * of a real login flow is to DISCOVER which firm(s) a user belongs to
 * in the first place — there is no firm context to activate yet at
 * that moment. Confirmed empirically: even a raw
 * DB::table('firm_users')->count() returns 0 with no context set, for
 * a real, existing row, regardless of which columns are filtered on —
 * this is DB-engine-level enforcement, not an Eloquent scoping gap.
 *
 * IMPORTANT — this migration does NOT touch the existing
 * firm_users_tenant_isolation policy at all. An earlier version of
 * this migration added the self-lookup clause directly to that
 * policy's single USING expression — but that policy has no separate
 * WITH CHECK clause, so Postgres defaults WITH CHECK to the same USING
 * expression, meaning the self-lookup OR-clause governed INSERT/UPDATE
 * too, not just SELECT. Confirmed empirically: a session with ONLY
 * app.current_user_id set (no firm context at all) could INSERT a
 * brand-new firm_users row claiming membership in an ARBITRARY firm,
 * and UPDATE an existing row's own firm_id to a different firm — a
 * real privilege-escalation bug, not a theoretical one.
 *
 * The fix: add a SEPARATE, ADDITIONAL policy scoped explicitly
 * `FOR SELECT` only. PostgreSQL combines multiple permissive policies
 * for the same command with OR, and a `FOR SELECT`-only policy is
 * never consulted for INSERT/UPDATE/DELETE — so this policy can only
 * ever widen what a session may READ, never what it may WRITE.
 * INSERT/UPDATE/DELETE on firm_users remain governed solely by the
 * original firm_users_tenant_isolation policy (firm_id match), which
 * is NULL/never-true with no firm context active — exactly the
 * fail-closed behavior every other RLS-forced table already has.
 *
 * What this does NOT do: it does not let a user see any OTHER user's
 * firm_users row (user_id must match exactly), it does not let a user
 * write ANY firm_users row without real firm context, it does not let
 * a user see any firm's data through any OTHER table (every other RLS
 * policy in the codebase is untouched), and it grants no more than
 * what the user could already see anyway once real firm context is
 * established (their own membership row). It only unlocks the one
 * bootstrap SELECT needed to discover which firm to activate that
 * context for.
 */
return new class extends Migration
{
    private const TABLE = 'firm_users';

    private const SELF_LOOKUP_POLICY = 'firm_users_self_lookup';

    public function up(): void
    {
        DB::statement(<<<SQL
            CREATE POLICY {$this->quoteIdentifier(self::SELF_LOOKUP_POLICY)}
            ON {$this->quoteIdentifier(self::TABLE)}
            FOR SELECT
            USING (
                user_id = NULLIF(current_setting('app.current_user_id', true), '')::bigint
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
            throw new \RuntimeException("Refusing to operate on an unsafe/unexpected identifier: {$identifier}");
        }

        return '"'.$identifier.'"';
    }
};
