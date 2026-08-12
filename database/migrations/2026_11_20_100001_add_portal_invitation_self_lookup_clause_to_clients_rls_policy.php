<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Mission 3A (MyAttorney Launch-Flow Closure) — a SECOND, SEPARATE,
 * ADDITIONAL `FOR SELECT`-only self-lookup policy on `clients`,
 * alongside the existing `clients_self_lookup` policy (which resolves
 * by numeric id, POST-authentication, from an already-resolved
 * ClientPortalUser.client_id). This one resolves by
 * `portal_invitation_token` — the ONLY identifier a genuinely
 * unauthenticated visitor holding a fresh invitation link has. Byte-
 * for-byte the same shape and reasoning as
 * 2026_09_24_180006_add_self_lookup_clause_to_clients_rls_policy.php
 * and 2026_11_12_100005_add_self_lookup_clause_to_marketplace_intakes_rls_policy.php
 * — a new session setting, `app.current_client_portal_invitation_token`,
 * set STRICTLY from the token embedded in the visitor's own signed URL
 * (see TenantContextService::withClientPortalInvitationSelfLookupContext()),
 * never from any other request input.
 *
 * The `portal_invitation_token IS NOT NULL` guard means this policy
 * matches NOTHING once a token has been consumed (acceptInvitation()
 * nulls it) or was never issued — a reused/unknown token resolves to
 * zero rows, identical in shape to MarketplaceIntake's own uuid-based
 * single-use pattern, with no way to distinguish "wrong token" from
 * "already used" from the response alone (anti-enumeration).
 *
 * Postgres combines multiple permissive policies for the same command
 * with OR, and a `FOR SELECT`-only policy is never consulted for
 * INSERT/UPDATE/DELETE — this can only ever widen what a session may
 * READ, never what it may WRITE. Write access remains governed solely
 * by `clients_tenant_isolation` (firm_id match via real firm context),
 * exactly like every other self-lookup policy in this codebase.
 */
return new class extends Migration
{
    private const TABLE = 'clients';

    private const SELF_LOOKUP_POLICY = 'clients_portal_invitation_self_lookup';

    public function up(): void
    {
        DB::statement(<<<SQL
            CREATE POLICY {$this->quoteIdentifier(self::SELF_LOOKUP_POLICY)}
            ON {$this->quoteIdentifier(self::TABLE)}
            FOR SELECT
            USING (
                portal_invitation_token IS NOT NULL
                AND portal_invitation_token = NULLIF(current_setting('app.current_client_portal_invitation_token', true), '')
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
