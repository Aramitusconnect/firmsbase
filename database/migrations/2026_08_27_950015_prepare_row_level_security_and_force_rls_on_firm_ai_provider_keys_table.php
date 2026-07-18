<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * firm_ai_provider_keys — like firm_ai_settings (see
 * database/migrations/2026_08_27_950003_prepare_row_level_security_and_force_rls_on_firm_ai_settings_table.php),
 * this table has NO pre-existing RLS policy — it is listed under
 * RowLevelSecurityCoverageMappingService::missingPreparedTables(), so
 * this migration does all three required steps in one batch: ENABLE
 * ROW LEVEL SECURITY, CREATE POLICY, and FORCE ROW LEVEL SECURITY,
 * never leaving RLS-enabled-with-no-policy as an intermediate state.
 *
 * Table selection rationale: firm_ai_provider_keys.firm_id is a direct,
 * NOT NULL foreign key to firms
 * (foreignId('firm_id')->constrained('firms')->cascadeOnDelete(), see
 * database/migrations/2026_07_23_900002_create_firm_ai_provider_keys_table.php).
 * Its only writer is AiProviderKeyService (generate()/rotate()), whose
 * entire bodies are each wrapped in their own outer
 * (new TenantContextService())->runWithFirmContext($firm, ...) call by
 * this same commit — rotate() calls generate() internally, which nests
 * safely (TenantContextService::runWithFirmContext() restores rather
 * than clears on exit, so the inner call does not tear down the
 * outer's context).
 *
 * Deferred, documented (not hidden) gap: this table has a SECOND
 * tenant-scoped foreign key, encryption_key_id -> tenant_encryption_keys.
 * Nothing in the schema or in this migration enforces that
 * encryption_key_id's own firm_id matches this row's firm_id — that is
 * a transitive cross-firm consistency question RLS on this table alone
 * cannot catch (RLS only constrains firm_ai_provider_keys.firm_id
 * itself, not a joined table's column). No PL/pgSQL trigger is added
 * here — implementing one was explicitly excluded from this
 * implementation's scope by the coordinator; today, encryption_key_id/
 * firm_id consistency is app-layer-derived only (AiProviderKeyService::generate()
 * always resolves encryption_key_id from the SAME $firm it is given,
 * and this commit's factory fix makes the default factory path do the
 * same), matching the matter_expenses precedent (see
 * database/migrations/2026_08_27_950012_prepare_row_level_security_and_force_rls_on_matter_expenses_table.php)
 * of documenting rather than silently repairing this class of gap.
 *
 * Policy shape: a single policy with an EXPLICIT WITH CHECK clause
 * (identical to the USING expression), matching the firm_ai_settings
 * checkpoint's own deliberate, reviewed choice.
 *
 * The table name is a single hardcoded string literal (never user
 * input), but is still validated against a strict identifier pattern
 * before being interpolated into SQL and is double-quoted, matching
 * every prior activation migration's own defensive pattern.
 */
return new class extends Migration
{
    private const TABLE = 'firm_ai_provider_keys';

    private const POLICY = 'firm_ai_provider_keys_tenant_isolation';

    public function up(): void
    {
        $table = $this->quoteIdentifier(self::TABLE);
        $policy = $this->quoteIdentifier(self::POLICY);

        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");

        DB::statement(<<<SQL
            CREATE POLICY {$policy}
            ON {$table}
            USING (
                firm_id = NULLIF(current_setting('app.current_firm_id', true), '')::bigint
            )
            WITH CHECK (
                firm_id = NULLIF(current_setting('app.current_firm_id', true), '')::bigint
            )
        SQL);

        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
    }

    /**
     * Full rollback: this migration introduced the policy itself (no
     * prior preparation migration existed for firm_ai_provider_keys),
     * so down() must remove all three effects it added: FORCE, the
     * policy, and RLS being enabled at all — restoring the table to
     * its true pre-this-migration (MISSING_PREPARED_TABLES) state.
     */
    public function down(): void
    {
        $table = $this->quoteIdentifier(self::TABLE);
        $policy = $this->quoteIdentifier(self::POLICY);

        DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
        DB::statement("DROP POLICY {$policy} ON {$table}");
        DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
    }

    private function quoteIdentifier(string $identifier): string
    {
        if (! preg_match('/^[a-z_][a-z0-9_]*$/', $identifier)) {
            throw new RuntimeException("Refusing to operate on an unsafe/unexpected identifier: {$identifier}");
        }

        return '"'.$identifier.'"';
    }
};
