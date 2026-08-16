<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * provider_evidence_artifacts — new table, no pre-existing policy;
 * ENABLE + CREATE POLICY + FORCE in one batch.
 *
 * An ordinary tenant-isolation policy, because this is an ordinary
 * tenant table: `firm_id` is NOT NULL and every artifact here is
 * already attributed to a firm. Unresolved provider ingress never
 * reaches this table at all — it stays in the Global/EXEMPT
 * `integration_webhook_receipts`. See the create migration's docblock
 * for why the earlier nullable-firm design was both unimplementable
 * under FORCE RLS and unnecessary.
 *
 * This policy must never gain an `OR firm_id IS NULL` clause: that
 * would let every firm read every unattributed artifact, turning this
 * table into precisely the cross-tenant gateway v1.4 §39 forbids.
 */
return new class extends Migration
{
    private const TABLE = 'provider_evidence_artifacts';

    private const POLICY = 'provider_evidence_artifacts_tenant_isolation';

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
