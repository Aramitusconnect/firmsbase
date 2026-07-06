<?php

namespace App\Services;

use App\Enums\GovernanceRecordScope;

/**
 * AuditPreservationPolicyService — declares the protected long-term log
 * families (project rule: never silently delete legal, audit, trust,
 * payment, document-access, support-access, client-portal, AI, or
 * platform billing records). This service does not modify any existing
 * audit/log model — it is a read-only declaration + query surface that
 * firewall/regression tests assert against, and that admin tooling in a
 * later phase could read to render a compliance view.
 *
 * Approved decision #8: ClientPortalLog is declared here as a REQUIRED
 * FUTURE log family — no client-portal-log table was confirmed to exist
 * during Phase 17 research, and Phase 17 does not invent one (would be
 * a 14th table, forbidden by the implementation boundary). This gap is
 * represented explicitly rather than silently omitted.
 */
class AuditPreservationPolicyService
{
    /**
     * Maps each protected log family to the EXISTING model class that
     * backs it (null = no confirmed existing table — a declared gap).
     *
     * @return array<string, class-string|null>
     */
    public function protectedLogFamilies(): array
    {
        return [
            GovernanceRecordScope::SecurityLog->value => \App\Models\SecurityEvent::class,
            GovernanceRecordScope::PaymentLog->value => \App\Models\PaymentClassificationEvent::class,
            GovernanceRecordScope::TrustLog->value => \App\Models\TrustLedgerEntry::class,
            GovernanceRecordScope::DocumentAccessLog->value => \App\Models\PdfViewEvent::class,
            GovernanceRecordScope::SupportAccessLog->value => \App\Models\SupportAccessSession::class,
            GovernanceRecordScope::ClientPortalLog->value => null,
            GovernanceRecordScope::PlatformBillingLog->value => \App\Models\PlatformBillingEvent::class,
            GovernanceRecordScope::AiLog->value => \App\Models\AiUsageEvent::class,
            GovernanceRecordScope::ApiLog->value => null,
            GovernanceRecordScope::WebhookLog->value => \App\Models\WebhookEvent::class,
        ];
    }

    public function isLogFamilyRepresented(GovernanceRecordScope $family): bool
    {
        return $this->protectedLogFamilies()[$family->value] !== null;
    }

    /**
     * @return array<int, GovernanceRecordScope> log families with no
     *   confirmed existing table — required gaps for a future phase.
     */
    public function requiredFutureLogFamilies(): array
    {
        return array_values(array_filter(
            GovernanceRecordScope::cases(),
            fn (GovernanceRecordScope $family) => ! $this->isLogFamilyRepresented($family),
        ));
    }

    /**
     * The append-only-guarded model classes this policy protects.
     * Firewall/regression tests assert each one still throws on
     * update/delete — Phase 17 never modifies any of them.
     *
     * @return array<int, class-string>
     */
    public function appendOnlyProtectedModelClasses(): array
    {
        return array_values(array_filter($this->protectedLogFamilies()));
    }
}
