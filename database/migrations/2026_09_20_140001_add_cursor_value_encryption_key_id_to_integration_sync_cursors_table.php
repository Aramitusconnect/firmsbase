<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Checkpoint 2 (FirmsVault Live Integrations, Microsoft 365 provider —
 * checkpoint2-design-sync-webhooks.md §1.2; checkpoint2-combined-design.md
 * §2 P-13; checkpoint2-security-review.md Finding 5) addition to
 * `integration_sync_cursors`: closes the "cursor_value is stored
 * PLAINTEXT" gap flagged against the mission's "tenant-scoped ENCRYPTED
 * cursor persistence" requirement (§7.3). `cursor_value` itself is
 * unchanged (still `text`, nullable) — this migration is purely
 * additive.
 *
 * `cursor_value_encryption_key_id` mirrors
 * `integration_credentials.encryption_key_id`'s exact shape
 * (2026_09_03_030001_create_integration_credentials_table.php:90 —
 * `$table->foreignId('encryption_key_id')->constrained('tenant_encryption_keys')->restrictOnDelete()`)
 * except nullable: a freshly-created cursor row
 * (SyncCursorService::firstOrCreate()) has `cursor_value = NULL` and
 * therefore no key id yet either, and an invalidated cursor
 * (SyncCursorService::invalidate()) resets both back to NULL together
 * (security review Finding 5 — see that method's own updated docblock).
 *
 * CHECK constraint `cursor_value IS NOT NULL <=> cursor_value_encryption_key_id
 * IS NOT NULL` follows the exact raw `DB::statement()` `ALTER TABLE ...
 * ADD CONSTRAINT ... CHECK (...)` convention already established by
 * `integration_conflicts`' own dual-approval CHECK constraints
 * (2026_09_05_054001_create_integration_conflicts_table.php) — this
 * table's Laravel version has no fluent CHECK-constraint schema-builder
 * method, so every CHECK constraint in this codebase is added the same
 * raw-SQL way.
 *
 * Required correction from the security review (Finding 5, P1): this
 * migration MUST land in the same change as
 * `SyncCursorService::advance()`'s and `SyncCursorService::invalidate()`'s
 * updates — `invalidate()` already sets `cursor_value = NULL` and must
 * ALSO set `cursor_value_encryption_key_id = NULL` in that same
 * statement, or the CHECK constraint below makes `invalidate()` throw a
 * raw QueryException on its very first real call (the Microsoft `410
 * Gone` self-healing path this checkpoint exists to support).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_sync_cursors', function (Blueprint $table) {
            $table->foreignId('cursor_value_encryption_key_id')
                ->nullable()
                ->after('cursor_value')
                ->constrained('tenant_encryption_keys')
                ->restrictOnDelete();
        });

        // CHECKPOINT 7 MIGRATION-SAFETY CORRECTION: a plain `ADD
        // CONSTRAINT ... CHECK (...)` validates every existing row
        // immediately under an ACCESS EXCLUSIVE lock and hard-fails the
        // whole migration if any pre-existing row violates it. This
        // table predates this mission (2026_09_05_053001) and is
        // actively written by SyncCursorService::advance() — a
        // long-lived environment where the (production-gated-off) test
        // provider was exercised between the two dates could carry a
        // pre-existing `cursor_value IS NOT NULL` row with no key id yet.
        // `NOT VALID` + a separate `VALIDATE CONSTRAINT` is the standard
        // safe-migration split: the constraint still applies to every
        // row going forward from the instant `ADD CONSTRAINT` commits
        // (no window where a new violating write could sneak in), takes
        // the much weaker SHARE UPDATE EXCLUSIVE lock for the historical
        // scan, and the final enforced invariant is byte-for-byte
        // identical to the original single-statement form — this is a
        // migration-safety-only correction, not a behavior change.
        DB::statement(<<<'SQL'
            ALTER TABLE integration_sync_cursors ADD CONSTRAINT integration_sync_cursors_value_key_id_pair CHECK (
                (cursor_value IS NOT NULL) = (cursor_value_encryption_key_id IS NOT NULL)
            ) NOT VALID
        SQL);

        DB::statement('ALTER TABLE integration_sync_cursors VALIDATE CONSTRAINT integration_sync_cursors_value_key_id_pair');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE integration_sync_cursors DROP CONSTRAINT IF EXISTS integration_sync_cursors_value_key_id_pair');

        Schema::table('integration_sync_cursors', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cursor_value_encryption_key_id');
        });
    }
};
