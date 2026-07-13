<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Section 39A-3L, Checkpoint 4, Table Phase B — permanently activates
 * FORCE ROW LEVEL SECURITY for exactly one additional prepared table:
 * firm_entitlements.
 *
 * Four independent audits, reconciled by rls-coordinator: firm_id is
 * NOT NULL, direct ownership, standard policy (FOR ALL USING firm_id =
 * current_setting(...)::bigint) — unchanged by this migration.
 * module_code -> module_catalog is confirmed genuinely global/non-
 * tenant (no firm_id column, no BelongsToTenant, RLS never enabled on
 * it) — no policy redesign needed and module_catalog is untouched.
 *
 * EntitlementService (the only writer via setForSource() and the only
 * read chokepoint via resolve(), confirmed by grep across the codebase)
 * is wrapped in this same batch (see that file's diff): setForSource()
 * and resolve() are each given their own whole-method
 * runWithFirmContext() wrap. Fixing resolve() itself transparently
 * fixes entitlement checks for every downstream consumer service
 * (AiEntitlementPolicyService, WebhookEntitlementPolicyService,
 * FeatureGateService, ApiAccessPolicyService,
 * AccountingEntitlementPolicyService, TrustEligibilityService, and
 * others) without touching any of those unrelated files.
 * FirmEntitlementFactory is given the standard context-hold create()
 * override so bare FirmEntitlement::factory()->create() calls keep
 * working once FORCE lands. FirmEntitlementEventFactory's confirmed
 * cross-firm mismatch bug is explicitly out of scope for this
 * checkpoint (Checkpoint 5 owns firm_entitlement_events).
 *
 * As with every prior batch in this arc, the down() migration restores
 * only the RLS-enabled-but-not-forced baseline for this one table — it
 * never drops the existing policy or disables RLS itself.
 */
return new class extends Migration
{
    private const TABLE = 'firm_entitlements';

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
