<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Section 39A-3C — third batch of the staged "Phase 1 RLS Enforcement
 * Activation" gate (see 2026_07_04_500001_prepare_row_level_security_for_tenant_tables,
 * 2026_07_30_900001_force_rls_on_clients_table, and
 * 2026_07_31_900001_force_rls_on_firm_users_table for the full history).
 *
 * documents was chosen as the next table after clients and firm_users
 * following inspection, not by assumption:
 *   - Independent of the client-cascade risk that still makes matters,
 *     invoices, and payments an unknown quantity — their factories
 *     ALWAYS nest a Client::factory() call (even in their plain,
 *     non-forFirm() definition()), so their own INSERT behavior under
 *     FORCE RLS remains unproven.
 *   - documents' own factory has no such nesting (matter_id/client_id
 *     default to null unless explicitly set), so its 69-failure count
 *     from the original 52-table discovery run is real and fully
 *     attributable to its own INSERT lacking tenant context — an
 *     already-understood, fixable blast radius using the exact
 *     pattern proven for clients and firm_users.
 *   - High security value: documents holds uploaded case files,
 *     immigration/legal records, and other client-sensitive content —
 *     among the most sensitive tenant-owned data in the schema.
 *   - Every real production code path that writes/reads documents
 *     directly (ImportApplyService, ImportDuplicateDetectionService,
 *     FirmCommandCenterAggregationService, DocumentReplacementService,
 *     EmailAttachmentPromotionService, DocumentSecurityService) already
 *     has a cleanly-known firm_id or Firm available at the call site —
 *     no ambiguous-ownership guessing required.
 *
 * Scope is deliberately a single-table allowlist. The other 49
 * still-unforced prepared tables and the 43 still-uncovered
 * tenant-owned tables are untouched.
 */
return new class extends Migration
{
    private const TABLE = 'documents';

    public function up(): void
    {
        DB::statement('ALTER TABLE '.$this->quoteIdentifier(self::TABLE).' FORCE ROW LEVEL SECURITY');
    }

    /**
     * Rollback support: returns documents to the Section 39A baseline
     * (RLS enabled, policy present, but NOT forced) — never drops the
     * policy or disables RLS itself, since those are owned by the
     * Phase 4 preparation migration
     * (2026_07_07_800016_extend_row_level_security_to_phase_4_tenant_tables.php).
     */
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
