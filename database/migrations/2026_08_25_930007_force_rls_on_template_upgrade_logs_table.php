<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Section 39A-3L, Checkpoint 7, Table Phase B — permanently activates
 * FORCE ROW LEVEL SECURITY for exactly one additional prepared table:
 * template_upgrade_logs.
 *
 * Four independent audits, reconciled by rls-coordinator: firm_id is
 * NOT NULL, direct ownership, standard policy (FOR ALL USING firm_id =
 * NULLIF(current_setting('app.current_firm_id', true), '')::bigint) —
 * unchanged by this migration. installed_template_pack_id,
 * from_version_id, to_version_id, and rollback_of_id were all
 * confirmed to stay consistent with firm_id via the production and
 * factory fixes shipped in this same batch; no unrelated table's
 * schema or policy needed to change.
 *
 * TemplateUpgradeLogService's apply() and rollback() are each fixed in
 * this same batch to add a SECOND, SEPARATE, SEQUENTIAL
 * TenantContextService::runWithFirmContext() wrap placed AFTER
 * TemplatePackInstallationService::install() returns, covering only
 * the direct template_upgrade_logs/$preview->update() writes — install()
 * already self-wraps its own body as of Checkpoint 6, so it is
 * deliberately NOT wrapped again here (see that file's diff).
 *
 * TemplateUpgradeLogFactory's definition()/forFirm() are fixed in this
 * same batch to derive installed_template_pack_id from the SAME
 * InstalledTemplatePack the firm_id comes from (rather than two
 * independent random factory chains), and its create() override adds
 * the same context-hold pattern as InstalledTemplatePackFactory (see
 * that file's diff) so a bare TemplateUpgradeLog::factory()->create()
 * keeps working once FORCE lands.
 *
 * As with every prior batch in this arc, the down() migration restores
 * only the RLS-enabled-but-not-forced baseline for this one table — it
 * never drops the existing policy or disables RLS itself.
 */
return new class extends Migration
{
    private const TABLE = 'template_upgrade_logs';

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
