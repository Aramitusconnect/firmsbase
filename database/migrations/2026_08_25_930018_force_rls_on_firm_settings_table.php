<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Section 39A-3L, Checkpoint 18 — permanently activates FORCE ROW LEVEL
 * SECURITY for firm_settings.
 *
 * firm_settings is a per-firm singleton (one row per firm, enforced by a
 * unique constraint on firm_id, not by RLS): firm_id is NOT NULL, direct
 * ownership, standard existing policy (created by this repo's Phase 1
 * preparation migration) — unchanged by this migration. No unrelated
 * table's schema needed to change.
 *
 * Two production fixes were required, both because they had unwrapped
 * reads of firm_settings that would otherwise fail OPEN once FORCE is
 * applied (an unwrapped SELECT against a FORCE-protected table returns
 * zero rows, not an error, so a policy check silently defaults to "not
 * required"/"not found" instead of raising):
 *
 *   - User::canAccessPanel() ran its entire 2FA decision (both
 *     FirmUser2faPolicyService::isRequiredForFirmUser() and
 *     ::isCompliant(), the latter of which internally re-calls
 *     isRequiredForFirmUser()) with no ambient tenant context active at
 *     that point in the auth flow. Once forced, firm->firmSettings would
 *     resolve to null, isRequiredForFirm() would return false, and a
 *     firm configured with firm_user_2fa_mode = Required would silently
 *     stop enforcing 2FA. Fixed by wrapping the whole decision (both
 *     calls, one closure) in TenantContextService::runWithFirmContext().
 *
 *   - TrustTransferRequestService::apply() called
 *     PaymentClassificationService::classify() unwrapped, sandwiched
 *     between two separate runWithFirmContext() blocks (the first wrap's
 *     finally clears context before classify() runs; a new wrap only
 *     starts at the following recordDecision() call). classify() reads
 *     firm_settings.payment_mode; once forced, a firm configured with
 *     payment_mode = Blocked would silently have that block skipped on
 *     the trust-transfer-to-invoice path. Fixed by moving classify()
 *     inside the following wrap (merged with recordDecision(), since its
 *     result is only ever used there).
 *
 * FirmSettingsFactory's bare create() path was also fixed with the same
 * context-hold pattern used by every FORCE-RLS factory since 39A-3A, so
 * a bare FirmSettings::factory()->create() continues to work correctly
 * whether or not the caller already has an ambient tenant context
 * active. definition()/forFirm() themselves needed no change: firm_id is
 * the table's only tenant-linkage column and already resolves through a
 * single authoritative Firm (via Firm::factory() or the firm passed to
 * forFirm()) — there is no second, independently-resolved tenant-owned
 * relation for a bare create() to mismatch against.
 *
 * Known, explicitly NOT fixed in this batch (dormant risk, tracked for
 * whenever each gets a real production caller): AiModeResolutionService,
 * LegalSpecialistBoundaryPolicyService::assertTrustIoltaNeverEnabledFor(),
 * and TrustJurisdictionReadinessService::checklistFor() all read
 * firm_settings unwrapped, by design deferring tenant-context
 * responsibility to their (currently nonexistent) caller. No production
 * code path calls any of them today, so none is a reachable fail-open
 * gap as of this checkpoint — but the first real caller wired up for any
 * of them MUST supply its own tenant context wrap, the same way this
 * checkpoint's two fixes did.
 *
 * As with every prior batch in this arc, the down() migration restores
 * only the RLS-enabled-but-not-forced baseline for this one table — it
 * never drops the existing policy or disables RLS itself.
 */
return new class extends Migration
{
    private const TABLE = 'firm_settings';

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
