<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Mission 3, checkpoint 1 — the RLS bootstrap gap for a genuinely
 * anonymous prospect resuming their own intake, resolved the exact
 * same way payment_requests_self_lookup resolves it for payment
 * requests (see that migration's own docblock for the full reasoning,
 * byte-for-byte reused here). A visitor holds nothing but
 * marketplace_intakes.uuid (from their resumable intake link) — there
 * is no firm context to activate yet, since discovering the firm IS
 * what this lookup is for.
 *
 * A SEPARATE, ADDITIONAL `FOR SELECT`-only policy widens what may be
 * READ, never what may be WRITTEN — PostgreSQL combines multiple
 * permissive policies for the same command with OR, and a FOR SELECT
 * policy is never consulted for INSERT/UPDATE/DELETE, so
 * marketplace_intakes_tenant_isolation (from the create-table
 * migration) remains the sole gate on every write regardless of this
 * policy.
 *
 * uuid = current_setting('app.current_marketplace_intake_uuid')
 * matches at most one row by construction (marketplace_intakes.uuid
 * carries its own UNIQUE constraint) — this policy can never disclose
 * any OTHER firm's intake, any listing, or any other table.
 *
 * See TenantContextService::withMarketplaceIntakeSelfLookupContext().
 */
return new class extends Migration
{
    private const TABLE = 'marketplace_intakes';

    private const SELF_LOOKUP_POLICY = 'marketplace_intakes_self_lookup';

    public function up(): void
    {
        DB::statement(<<<SQL
            CREATE POLICY {$this->quoteIdentifier(self::SELF_LOOKUP_POLICY)}
            ON {$this->quoteIdentifier(self::TABLE)}
            FOR SELECT
            USING (
                uuid = NULLIF(current_setting('app.current_marketplace_intake_uuid', true), '')::uuid
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
