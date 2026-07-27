<?php

namespace App\Services;

use App\Enums\EntitlementSource;
use App\Models\Firm;
use App\Models\FirmEntitlement;
use App\Models\PlatformAdmin;
use App\Models\User;

/**
 * EntitlementOverrideService — the ONLY place a firm_override or
 * admin_override entitlement row is written. A thin, validating wrapper
 * over the EXISTING EntitlementService::setForSource() — approved
 * decision: do not create a firm_entitlement_overrides table; the
 * existing firm_entitlements per-source design (with its
 * firm_entitlement_events audit trail) already is the standard override
 * machinery. This service exists only to enforce override-specific
 * rules (a reason is mandatory; only FirmOverride/AdminOverride sources
 * are accepted here) before delegating.
 */
class EntitlementOverrideService
{
    private const AUDIT_CATEGORY = 'entitlement_override';

    public function __construct(
        private EntitlementService $entitlementService,
        private readonly PlatformAdminAuditEventRecorder $auditRecorder = new PlatformAdminAuditEventRecorder,
    ) {}

    public function setOverride(
        Firm $firm,
        string $moduleCode,
        EntitlementSource $source,
        bool $enabled,
        string $reason,
        User $actor,
        ?\DateTimeInterface $endsAt = null,
    ): FirmEntitlement {
        $this->assertValidOverride($source, $reason);

        return $this->entitlementService->setForSource(
            firm: $firm,
            moduleCode: $moduleCode,
            source: $source,
            enabled: $enabled,
            actor: $actor,
            reason: $reason,
            endsAt: $endsAt,
        );
    }

    /**
     * Phase 4 (FirmsVault Platform Admin Control Center, "Configuration"
     * category) addition — the "Set Override" action behind
     * EntitlementOverrideResource ("Entitlement Overrides", the honest
     * relabeling of "Feature Flags" against the real per-firm
     * FirmEntitlement/EntitlementSource system). setOverride() above
     * requires a `User $actor` (the firm-panel user model) — there is
     * no variant accepting a PlatformAdmin, and
     * EntitlementService::setForSource()'s own `created_by` column is a
     * real FK to the `users` table, not `platform_admins`
     * (firm_entitlements.created_by), so a PlatformAdmin's id cannot be
     * written there without either a schema change or a genuine
     * data-integrity violation (an FK pointing at a row that does not
     * exist in `users`).
     *
     * Resolution (the same actor-type-gap pattern used throughout this
     * phase, e.g. AiPolicySetting.updated_by staying null for
     * admin-initiated writes): this method forwards to
     * setForSource(actor: null) — leaving firm_entitlements.created_by
     * null and firm_entitlement_events.actor_type 'System' for this
     * write, an honest signal ("no User actor exists for this write"),
     * never a fabricated/misattributed User id — and separately records
     * a correctly-attributed `security_events` row via
     * PlatformAdminAuditEventRecorder::record() (firm-scoped: unlike
     * PlatformInvoiceService's use of the null-firm_id
     * recordPlatformEvent() variant, FirmEntitlement carries a real,
     * single, non-nullable firm_id via BelongsToTenant, so the
     * firm-scoped record() is the architecturally correct choice here,
     * mirroring SupportAccessRequestService::expire()'s identical
     * reasoning). The real PlatformAdmin attribution for this write
     * therefore lives in security_events, not in
     * firm_entitlement_events — documented here explicitly rather than
     * silently split across two tables with no cross-reference: the
     * security_events row's metadata includes the resulting
     * firm_entitlement_id so the two can be correlated.
     */
    public function setOverrideAsPlatformAdmin(
        Firm $firm,
        string $moduleCode,
        EntitlementSource $source,
        bool $enabled,
        string $reason,
        PlatformAdmin $actor,
        ?\DateTimeInterface $endsAt = null,
    ): FirmEntitlement {
        $this->assertValidOverride($source, $reason);

        $entitlement = $this->entitlementService->setForSource(
            firm: $firm,
            moduleCode: $moduleCode,
            source: $source,
            enabled: $enabled,
            actor: null,
            reason: $reason,
            endsAt: $endsAt,
        );

        $this->auditRecorder->record(
            $firm,
            $actor,
            'entitlement_override_set',
            self::AUDIT_CATEGORY,
            [
                'firm_entitlement_id' => $entitlement->id,
                'firm_entitlement_uuid' => $entitlement->uuid,
                'module_code' => $moduleCode,
                'source' => $source->value,
                'enabled' => $enabled,
                'reason' => $reason,
                'ends_at' => $endsAt?->format(\DateTimeInterface::ATOM),
            ],
        );

        return $entitlement;
    }

    private function assertValidOverride(EntitlementSource $source, string $reason): void
    {
        if (! in_array($source, [EntitlementSource::FirmOverride, EntitlementSource::AdminOverride], true)) {
            throw new \InvalidArgumentException(
                'EntitlementOverrideService only accepts FirmOverride or AdminOverride sources.'
            );
        }

        if (trim($reason) === '') {
            throw new \InvalidArgumentException('An override reason is required.');
        }
    }
}
