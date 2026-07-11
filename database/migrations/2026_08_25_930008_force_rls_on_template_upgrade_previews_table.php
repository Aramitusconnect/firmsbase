<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Section 39A-3L, Checkpoint 8, Table Phase B — permanently activates
 * FORCE ROW LEVEL SECURITY for exactly one additional prepared table:
 * template_upgrade_previews.
 *
 * Four Phase A audits (rls-inventory-analyst, tenant-context-auditor,
 * security-reviewer, plus rls-policy-designer which declined twice on
 * a scope misunderstanding — its policy-shape confirmation was covered
 * instead by the other two agents directly querying pg_policies),
 * reconciled by the orchestrator: firm_id is NOT NULL, direct
 * ownership, standard policy (FOR ALL USING firm_id =
 * NULLIF(current_setting('app.current_firm_id', true), '')::bigint) —
 * unchanged by this migration. installed_template_pack_id,
 * from_version_id, and to_version_id were confirmed to stay consistent
 * with firm_id via the production and factory fixes shipped in this
 * same batch; no unrelated table's schema or policy needed to change.
 *
 * TemplateUpgradePreviewService's preview(), markReviewed(), and
 * discard() are each fixed in this same batch to add a whole-method
 * TenantContextService::runWithFirmContext() wrap — all three methods
 * previously had zero tenant-context wrapping, confirmed by three
 * independent Phase A audits, and confirmed no nested-wrap/decoy-wrap
 * risk exists (nothing in this service calls another already-self-
 * wrapping method).
 *
 * TemplateUpgradePreviewFactory's definition()/forFirm() are fixed in
 * this same batch to derive installed_template_pack_id from the SAME
 * InstalledTemplatePack the firm_id comes from (rather than two
 * independent random factory chains), and its create() override adds
 * the same context-hold pattern as TemplateUpgradeLogFactory (see that
 * file's Checkpoint 7 diff) so a bare
 * TemplateUpgradePreview::factory()->create() keeps working once
 * FORCE lands.
 *
 * As with every prior batch in this arc, the down() migration restores
 * only the RLS-enabled-but-not-forced baseline for this one table — it
 * never drops the existing policy or disables RLS itself.
 */
return new class extends Migration
{
    private const TABLE = 'template_upgrade_previews';

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
