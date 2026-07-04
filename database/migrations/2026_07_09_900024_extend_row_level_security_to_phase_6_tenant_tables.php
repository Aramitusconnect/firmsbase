<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Extends the SAME row-level-security PREPARATION pattern established
 * by Phase 1's prepare_row_level_security_for_tenant_tables migration
 * (ENABLE ROW LEVEL SECURITY + a firm_id-matching policy, deliberately
 * WITHOUT FORCE ROW LEVEL SECURITY — see that migration's docblock for
 * the full rationale and the follow-up activation gate that owns
 * turning enforcement on) to exactly the 3 new Phase 6 tables whose
 * firm_id is NOT NULL and is genuinely the tenant ownership boundary:
 * seat_allocations, template_upgrade_previews, template_upgrade_logs.
 *
 * Deliberately excluded (approved decision — do not apply firm-tenant
 * RLS to platform-level/account-level/global reference tables):
 *   - plans, plan_modules, plan_limits — global reference data, no
 *     firm_id at all.
 *   - org_licenses, seat_pools — organization-owned, no firm_id.
 *   - license_events — mixed ownership (covers both firm_licenses and
 *     org_licenses via a polymorphic key); a single firm_id-keyed
 *     policy cannot safely cover it.
 *   - platform_subscriptions, platform_subscription_items,
 *     platform_invoices, platform_payments, platform_refunds,
 *     platform_payment_attempts, platform_billing_events — billing
 *     account-scoped, not firm-scoped.
 *   - platform_invoice_lines, usage_rollups — carry a NULLABLE firm_id
 *     for per-firm attribution only; the real ownership boundary is
 *     billing_account_id, which can legitimately span multiple member
 *     firms under an organization. A firm-keyed RLS policy here would
 *     incorrectly hide cross-firm attribution rows from a
 *     billing-account-level viewer, so applying it would not be safe.
 */
return new class extends Migration
{
    private array $tables = [
        'seat_allocations',
        'template_upgrade_previews',
        'template_upgrade_logs',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");

            DB::statement(<<<SQL
                CREATE POLICY {$this->policyName($table)}
                ON {$table}
                USING (
                    firm_id = NULLIF(current_setting('app.current_firm_id', true), '')::bigint
                )
            SQL);
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            DB::statement("DROP POLICY IF EXISTS {$this->policyName($table)} ON {$table}");
            DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        }
    }

    private function policyName(string $table): string
    {
        return "{$table}_tenant_isolation";
    }
};
