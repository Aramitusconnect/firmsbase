<?php

namespace App\Services;

use App\Enums\LicenseStatus;
use App\Models\Firm;

/**
 * LegalDataAccessPolicyService — governs read/export access to a
 * firm's legal data based on Phase 1's EXISTING LicenseStatus (no new
 * Phase 5 status field — approved decision). "Past due or suspended
 * firms must not be abruptly locked out of legal data; read/export
 * access must follow governed policy" (PDF, Phase 5 Controls and
 * Rules). This service defines that policy; it does not gate anything
 * automatically (no middleware/route is added — out of phase per the
 * forbidden-items list) — callers (a future export endpoint, a Phase 6
 * billing-enforcement layer, etc.) consult canRead()/canExport()
 * before acting.
 *
 * Uses the firm's MOST RESTRICTIVE current license (multiple licenses
 * are theoretically possible per Phase 1's schema) so a single
 * suspended/expired license cannot be masked by another healthy one —
 * fail toward the safer, more restrictive read (never toward
 * write access).
 */
class LegalDataAccessPolicyService
{
    /**
     * Statuses that permit full read+write access to legal data.
     */
    private const FULL_ACCESS = [
        LicenseStatus::Trial,
        LicenseStatus::Active,
        LicenseStatus::GracePeriod,
        LicenseStatus::Manual,
        LicenseStatus::Lifetime,
    ];

    /**
     * Statuses that still permit read access but block new writes —
     * "must not be abruptly locked out" is enforced by keeping read
     * access intact here rather than falling through to no access.
     */
    private const READ_ONLY = [
        LicenseStatus::PastDue,
        LicenseStatus::Restricted,
        LicenseStatus::ReadOnly,
    ];

    /**
     * Statuses where interactive read access is withdrawn but a
     * GOVERNED export remains available — data is never destroyed or
     * hidden outright (PDF: "Suspension must not destroy or hide legal
     * data").
     */
    private const EXPORT_ONLY = [
        LicenseStatus::Suspended,
        LicenseStatus::ExportOnly,
        LicenseStatus::Cancelled,
        LicenseStatus::Expired,
    ];

    public function currentStatus(Firm $firm): ?LicenseStatus
    {
        // Section 39A-3L, Checkpoint 19 - firm_licenses is FORCE-RLS
        // protected as of this checkpoint. This relation load used to
        // run with no ambient tenant context; once forced, it would
        // silently resolve to an empty collection rather than raising,
        // making canRead()/canWrite()/canExport() report unrestricted
        // full access for a Suspended/PastDue/Restricted firm - a
        // fail-OPEN data-access-control gap. This method calls no other
        // tenant-context-sensitive service, so a single self-contained
        // wrap is safe (no nesting risk).
        $statuses = (new TenantContextService())->runWithFirmContext($firm, function () use ($firm) {
            $firm->loadMissing('licenses');

            return $firm->licenses->pluck('license_status');
        });

        if ($statuses->isEmpty()) {
            return null;
        }

        foreach (self::EXPORT_ONLY as $restrictive) {
            if ($statuses->contains($restrictive)) {
                return $restrictive;
            }
        }

        foreach (self::READ_ONLY as $restrictive) {
            if ($statuses->contains($restrictive)) {
                return $restrictive;
            }
        }

        return $statuses->first();
    }

    public function canRead(Firm $firm): bool
    {
        $status = $this->currentStatus($firm);

        if ($status === null) {
            return true; // no license record yet (e.g. brand-new firm) — not a restriction case
        }

        return in_array($status, self::FULL_ACCESS, true) || in_array($status, self::READ_ONLY, true);
    }

    public function canWrite(Firm $firm): bool
    {
        $status = $this->currentStatus($firm);

        if ($status === null) {
            return true;
        }

        return in_array($status, self::FULL_ACCESS, true);
    }

    /**
     * Export remains available even when interactive read access does
     * not — "read/export access must follow governed policy" (PDF).
     * Governance itself (who may trigger an export, approval, audit
     * logging of the export) is implemented by the caller; this method
     * only answers "is export permitted at all for this firm's current
     * status."
     */
    public function canExport(Firm $firm): bool
    {
        $status = $this->currentStatus($firm);

        if ($status === null) {
            return true;
        }

        return in_array($status, self::FULL_ACCESS, true)
            || in_array($status, self::READ_ONLY, true)
            || in_array($status, self::EXPORT_ONLY, true);
    }
}
