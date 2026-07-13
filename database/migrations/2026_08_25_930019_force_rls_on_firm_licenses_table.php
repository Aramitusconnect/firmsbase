<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Section 39A-3L, Checkpoint 19 — permanently activates FORCE ROW LEVEL
 * SECURITY for firm_licenses.
 *
 * firm_licenses is tenant-owned (firm_id NOT NULL, direct ownership,
 * standard existing policy created by this repo's Phase 1 preparation
 * migration — unchanged by this migration). Multiple licenses per firm
 * is a supported state (no unique constraint on firm_id alone), unlike
 * firm_settings' singleton shape. No unrelated table's schema needed to
 * change.
 *
 * Three production fixes were required. All three affected services
 * (FirmLicenseCommercialService, LicenseFileValidationService,
 * LegalDataAccessPolicyService) currently have no live route/job/console
 * caller, but each has dedicated, actively-maintained test coverage with
 * real assertions, so each is live application logic that would
 * empirically break the moment firm_licenses is FORCE-protected:
 *
 *   - FirmLicenseCommercialService::assignPlan()/changeStatus() wrote to
 *     an existing firm_licenses row with no ambient tenant context.
 *     Once forced, an unwrapped UPDATE against a FORCE-protected table
 *     silently affects zero rows rather than raising, so $license would
 *     appear updated in PHP memory while the database row never
 *     changed — and the LicenseEvent audit row would still be written,
 *     producing a phantom audit trail that no longer matches reality.
 *     Fixed with tightly-scoped runWithFirmContext() wraps around each
 *     actual write/read (not one whole-method wrap): assignPlan() calls
 *     EntitlementPlanSyncService::syncOrgInheritedEntitlements()/
 *     syncPlanEntitlements(), which call EntitlementService::
 *     setForSource(), which already self-wraps its own whole body (an
 *     established, intentional, already-tested pattern whose own
 *     docblock requires callers NOT to wrap it). Wrapping assignPlan()'s
 *     entire body in one outer context would let that inner self-wrap's
 *     finally clear the outer wrap's context the instant it returns,
 *     silently breaking the wrap for the remaining code — the same
 *     "decoy wrap" bug this arc has fixed before. Instead, assignPlan()
 *     uses three independent tight wraps (update, then fresh(), with
 *     the self-wrapping sync call and the unprotected LicenseEvent
 *     write left outside any wrap in between); changeStatus() has no
 *     self-wrapping child call and uses two tight wraps the same way.
 *
 *   - LicenseFileValidationService::validate() read
 *     $licenseFile->firmLicense (a BelongsTo into firm_licenses) three
 *     times with no ambient tenant context. Once forced, that relation
 *     would silently resolve to null, causing changeStatus() to be
 *     skipped entirely — GracePeriod/Restricted transitions would stop
 *     firing. Fixed by resolving $licenseFile->firmLicense once, under
 *     a tight context wrap keyed on $licenseFile->firm_id (LicenseFile
 *     itself is not RLS-protected and always readable), caching it in a
 *     local variable, and reusing that variable at all three call
 *     sites. changeStatus() itself is already self-contained after the
 *     fix above, so validate() does not need to additionally wrap its
 *     calls into changeStatus().
 *
 *   - LegalDataAccessPolicyService::currentStatus() read $firm->licenses
 *     with no ambient tenant context — a fail-OPEN data-access-control
 *     gap: once forced, a Suspended/PastDue/Restricted firm's license
 *     would silently resolve to "no license," and canRead()/canWrite()/
 *     canExport() would all report unrestricted full access instead of
 *     the correct, more restrictive policy. Fixed by wrapping the
 *     relation load + pluck in one runWithFirmContext() call; this
 *     method calls no other tenant-context-sensitive service, so a
 *     single self-contained wrap is safe (no nesting risk).
 *
 * FirmLicenseFactory's bare create() path was also fixed with the same
 * context-hold pattern used by every FORCE-RLS factory since 39A-3A, so
 * a bare FirmLicense::factory()->create() continues to work correctly
 * whether or not the caller already has an ambient tenant context
 * active. definition()/forFirm() themselves needed no change: firm_id
 * is resolved through a single authoritative Firm (via Firm::factory()
 * or the firm passed to forFirm()) — there is no second, independently-
 * resolved tenant-owned relation for a bare create() to mismatch
 * against.
 *
 * Known, explicitly NOT fixed in this batch: OrgLicense::firmLicenses()
 * (a hasMany with no firm filter) is declared but never called anywhere
 * in the codebase today — no production code iterates multiple firms'
 * FirmLicense rows in one operation. This is a dormant landmine only if
 * a future multi-firm license-sync feature is ever built against it; it
 * is not a reachable fail-open gap as of this checkpoint, and is
 * recorded here rather than fixed, matching this arc's established
 * honesty pattern for dormant, uncalled readers.
 *
 * As with every prior batch in this arc, the down() migration restores
 * only the RLS-enabled-but-not-forced baseline for this one table — it
 * never drops the existing policy or disables RLS itself.
 */
return new class extends Migration
{
    private const TABLE = 'firm_licenses';

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
