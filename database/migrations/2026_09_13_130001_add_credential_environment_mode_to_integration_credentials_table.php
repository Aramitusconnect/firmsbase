<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Checkpoint 1 (FirmsVault Live Integrations) addition —
 * checkpoint1-combined-design.md §2.2/§2.3, security-review-required
 * per checkpoint1-security-review.md Finding 3.
 *
 * Adds `credential_environment_mode` (nullable string, `CHECK
 * (credential_environment_mode IN ('sandbox','live'))`) to
 * `integration_credentials`. This is the REQUIRED, tamper-evident
 * design — NOT the open, freely-overwritable `masked_display_metadata`
 * JSON column the original design draft offered as a "primary" option:
 * that column has no protection at all (`replace()` accepts a fresh,
 * arbitrary caller-supplied `$metadata` array on every call with zero
 * key allowlisting), so storing a security-gating value there would let
 * an unrelated future call silently drop or flip it. A dedicated,
 * first-class, DB-CHECK-constrained column mirrors how `status`/
 * `credential_type` are already first-class typed columns on this same
 * table specifically because they gate security decisions elsewhere in
 * `IntegrationCredentialService`.
 *
 * Nullable and populated ONLY through the new, explicit, typed
 * `?string $environmentMode = null` parameter on
 * `IntegrationCredentialService::store()`/`rotate()` — never through
 * the open `$metadata` array. Nullable because no real ProviderKey case
 * exists yet beyond `Test` (which is never present in
 * `config('integrations.provider_environments')` at all), so every
 * credential this checkpoint's own tests create is legitimately
 * untagged; `IntegrationCredentialService::decryptForOperation()`'s new
 * mode-consistency check tolerates a null value (and a provider with no
 * configured environment at all) gracefully rather than throwing for
 * every existing TestProvider-backed credential.
 *
 * Additive-only migration over an existing, already-FORCE-RLS'd table
 * (`database/migrations/2026_09_03_030002_prepare_row_level_security_and_force_rls_on_integration_credentials_table.php`)
 * — no RLS policy change needed, this column carries no isolation
 * semantics of its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_credentials', function ($table) {
            $table->string('credential_environment_mode')->nullable()->after('status');
        });

        // MIGRATION-SAFETY CORRECTION (mirrors
        // 2026_09_20_140001_add_cursor_value_encryption_key_id_to_integration_sync_cursors_table.php):
        // a plain `ADD CONSTRAINT ... CHECK (...)` validates every existing
        // row immediately under an ACCESS EXCLUSIVE lock and hard-fails the
        // whole migration if any pre-existing row violates it. `NOT VALID` +
        // a separate `VALIDATE CONSTRAINT` is the standard safe-migration
        // split: the constraint still applies to every row going forward
        // from the instant `ADD CONSTRAINT` commits (no window where a new
        // violating write could sneak in), takes the much weaker SHARE
        // UPDATE EXCLUSIVE lock for the historical scan, and the final
        // enforced invariant is byte-for-byte identical to the original
        // single-statement form — this is a migration-safety-only
        // correction, not a behavior change.
        DB::statement(
            'ALTER TABLE integration_credentials '.
            'ADD CONSTRAINT integration_credentials_environment_mode_check '.
            "CHECK (credential_environment_mode IS NULL OR credential_environment_mode IN ('sandbox', 'live')) ".
            'NOT VALID'
        );

        DB::statement('ALTER TABLE integration_credentials VALIDATE CONSTRAINT integration_credentials_environment_mode_check');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE integration_credentials DROP CONSTRAINT IF EXISTS integration_credentials_environment_mode_check');

        Schema::table('integration_credentials', function ($table) {
            $table->dropColumn('credential_environment_mode');
        });
    }
};
