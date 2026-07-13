<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Section 39A-3L, Checkpoint 6, Table Phase B — permanently activates
 * FORCE ROW LEVEL SECURITY for exactly one additional prepared table:
 * installed_template_packs.
 *
 * Four independent audits, reconciled by rls-coordinator: firm_id is
 * NOT NULL, direct ownership, standard policy (FOR ALL USING firm_id =
 * current_setting(...)::bigint) — unchanged by this migration. Both
 * non-firm FKs (template_pack_id, template_pack_version_id) are
 * confirmed genuinely global/exempt catalog tables (template_packs,
 * template_pack_versions — neither has firm_id), so no unrelated
 * table's schema or policy needed to change.
 *
 * TemplatePackInstallationService's three public methods
 * (install(), markUpgradeAvailable(), disable()) are each fixed in
 * this same batch to wrap their entire body in
 * TenantContextService::runWithFirmContext() (see that file's diff).
 * This closes an empirically-reproduced silent-failure bug:
 * markUpgradeAvailable()/disable() called tap($model)->update([...])
 * unwrapped, and Eloquent's update() always returns true regardless of
 * actual affected-row count — under FORCE with no context, the
 * UPDATE's WHERE clause silently matches zero rows per the RLS policy,
 * Postgres reports no error, and the in-memory model looks updated
 * while the real row is untouched.
 *
 * InstalledTemplatePackFactory's create() override (context-hold
 * pattern matching FirmEntitlementFactory/FirmActivationEventFactory)
 * is added in this same batch so a bare
 * InstalledTemplatePack::factory()->create() — and the
 * ->forFirm($firm)->create() form already used by
 * TemplateUpgradeLogFactory/TemplateUpgradePreviewFactory's tests —
 * keeps working once FORCE lands (see that file's diff).
 *
 * As with every prior batch in this arc, the down() migration restores
 * only the RLS-enabled-but-not-forced baseline for this one table — it
 * never drops the existing policy or disables RLS itself.
 */
return new class extends Migration
{
    private const TABLE = 'installed_template_packs';

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
