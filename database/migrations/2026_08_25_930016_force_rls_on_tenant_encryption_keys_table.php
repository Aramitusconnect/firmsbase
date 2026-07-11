<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Section 39A-3L, Checkpoint 16, Table Phase B — permanently activates
 * FORCE ROW LEVEL SECURITY for tenant_encryption_keys.
 *
 * All Phase A audit converged: firm_id is NOT NULL, direct ownership,
 * standard policy (tenant_encryption_keys_tenant_isolation — FOR ALL
 * USING firm_id = NULLIF(current_setting('app.current_firm_id', true),
 * '')::bigint, created by this repo's Phase 1 preparation migration) —
 * unchanged by this migration. No unrelated table's schema needed to
 * change. tenant_encryption_keys is referenced by FOREIGN KEY from
 * eight other tables (ai_approval_requests, documents, email_messages,
 * email_oauth_tokens, expense_receipts, firm_ai_provider_keys,
 * key_destruction_requests, webhook_secrets) — PostgreSQL foreign-key
 * constraint validation is exempt from RLS by design, so none of those
 * referencing tables' own writes are affected by this change.
 *
 * A production fix WAS needed: EncryptionKeyService's four methods
 * (provision, rotate, decryptActiveKey, destroy) had zero tenant-
 * context wiring — every read/write against tenant_encryption_keys ran
 * completely unwrapped. Each method's existing plain DB::transaction()
 * (an atomicity boundary only) is replaced with
 * runWithFirmContext($firm->id, ...), which already opens its own
 * internal transaction, so no method wraps in both. decryptActiveKey()
 * wraps only its DB lookup — Crypt::decryptString() touches no table,
 * so it deliberately stays outside the wrap.
 *
 * A second, adjacent gap was found and fixed in the same pass:
 * EmailBodyEncryptionService::encrypt()/decrypt() each run their own
 * direct TenantEncryptionKey query BEFORE calling
 * EncryptionKeyService::decryptActiveKey() — both were unwrapped too.
 * Each is now wrapped in its own standalone runWithFirmContext() call,
 * deliberately NOT nested around the decryptActiveKey() call that
 * follows it: decryptActiveKey() establishes and clears its own
 * context internally, and nesting would let its finally-block clear
 * context this method still needed for anything after it returned.
 * The two DB accesses in each method are sequential, not nested — the
 * same "second, non-nested wrap placed after" pattern established in
 * Section 39A-3L, Checkpoint 7's own docblock. Both currently only do
 * in-memory Encrypter work after the decryptActiveKey() call, so no
 * DB access happens past that point today — but the sequential design
 * is deliberate and safe even if that changes later, not incidental.
 *
 * TenantEncryptionKeyFactory's bare create() was also fixed with the
 * standard context-hold override pattern used by every FORCE-RLS
 * factory since 39A-3A. Unlike several prior checkpoints' factories,
 * this table has only ONE tenant-scoped foreign key (firm_id, no
 * secondary matter_id/etc.), so definition() itself needed no change —
 * Firm::factory() alone cannot produce a cross-firm mismatch here.
 *
 * provision() and rotate() currently have no live production caller
 * (confirmed by direct repository search) — real but currently-
 * dormant code paths, same "conditionally ready" classification this
 * mission has applied to comparable cases (e.g. firm_licenses). Wrapped
 * anyway since the fix is narrow and the methods are real, callable,
 * publicly-exposed service API.
 *
 * Known, explicitly NOT fixed in this batch (tracked separately, same
 * accepted residual pattern as every other table in this mission):
 * KeyDestructionExecutionService::execute() (the sole caller of
 * destroy()) itself establishes no tenant context around its own
 * key_destruction_requests reads/writes — out of this checkpoint's
 * scope, since key_destruction_requests is not yet FORCE RLS and is
 * not this checkpoint's own table.
 *
 * Covered by tests/Feature/Tenancy/RowLevelSecurityPreparationTest.php's
 * exception-list mechanism (this table IS a Phase 1 table, unlike
 * Checkpoints 14/15) — added to that list in this same commit, per
 * that file's own docblock lesson about a table going silently red for
 * a whole cycle if the exception list isn't updated together with the
 * FORCE migration itself. Not covered by Phase6RowLevelSecurityTest.php
 * (confirmed via direct search — that file's own table set doesn't
 * include this one).
 *
 * As with every prior batch in this arc, the down() migration restores
 * only the RLS-enabled-but-not-forced baseline for this one table — it
 * never drops the existing policy or disables RLS itself.
 */
return new class extends Migration
{
    private const TABLE = 'tenant_encryption_keys';

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
