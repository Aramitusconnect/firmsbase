<?php

namespace App\Services;

use App\Enums\CustomerHealthRiskLevel;
use App\Models\CustomerSuccessHealthScore;
use App\Models\Firm;
use App\Models\Organization;
use App\ValueObjects\CustomerSuccessSnapshot;
use App\ValueObjects\OrganizationSuccessRollup;

/**
 * CustomerSuccessConsoleService — the read-only backend for the
 * Customer Success Console. Produces safe summary/aggregate data only
 * (CustomerSuccessSnapshot/OrganizationSuccessRollup); never exposes
 * document content. No UI/controller/route exists for this in Phase 7
 * — this is backend-only, per the approved scope.
 *
 * Section 39A-5, Checkpoint 1 — customer_success_health_scores is now
 * FORCE-RLS protected. snapshotFor() now self-wraps its read in
 * runWithFirmContext($firm, ...) so the query succeeds under the new
 * policy. The explicit ->where('firm_id', $firm->id)` filter is
 * deliberately kept even though the database policy now also enforces
 * it — RLS is the real enforcement boundary, the application filter
 * remains as defense in depth, exactly as before this checkpoint.
 */
class CustomerSuccessConsoleService
{
    public function snapshotFor(Firm $firm): ?CustomerSuccessSnapshot
    {
        $latest = (new TenantContextService)->runWithFirmContext($firm, fn () => CustomerSuccessHealthScore::query()
            ->where('firm_id', $firm->id)
            ->orderByDesc('computed_at')
            ->first());

        if ($latest === null) {
            return null;
        }

        return $this->toSnapshot($latest);
    }

    public function organizationRollup(Organization $organization): OrganizationSuccessRollup
    {
        $firms = $organization->firms()->get();

        $snapshots = $firms
            ->map(fn (Firm $firm) => $this->snapshotFor($firm))
            ->filter()
            ->values();

        $memberFirmCount = $firms->count();
        $averageScore = $snapshots->isEmpty() ? 0.0 : round($snapshots->avg(fn (CustomerSuccessSnapshot $s) => $s->score), 2);
        $atRiskFirmCount = $snapshots->filter(fn (CustomerSuccessSnapshot $s) => $s->riskLevel === CustomerHealthRiskLevel::AtRisk)->count();
        $criticalFirmCount = $snapshots->filter(fn (CustomerSuccessSnapshot $s) => $s->riskLevel === CustomerHealthRiskLevel::Critical)->count();

        return new OrganizationSuccessRollup(
            organizationId: $organization->id,
            memberFirmCount: $memberFirmCount,
            averageScore: $averageScore,
            atRiskFirmCount: $atRiskFirmCount,
            criticalFirmCount: $criticalFirmCount,
            memberFirmSnapshots: $snapshots->all(),
        );
    }

    private function toSnapshot(CustomerSuccessHealthScore $score): CustomerSuccessSnapshot
    {
        return new CustomerSuccessSnapshot(
            firmId: $score->firm_id,
            score: $score->score,
            riskLevel: $score->risk_level,
            onboardingProgressPercent: $score->onboarding_progress_percent,
            lastLoginAt: $score->last_login_at?->toIso8601String(),
            activeUsersCount: $score->active_users_count,
            mattersCount: $score->matters_count,
            clientsCount: $score->clients_count,
            documentsCount: $score->documents_count,
            invoicesCount: $score->invoices_count,
            paymentPlansCount: $score->payment_plans_count,
            paymentsCount: $score->payments_count,
            aiUsageCount: $score->ai_usage_count,
            storageBytes: $score->storage_bytes,
            failedJobsCount: $score->failed_jobs_count,
            openTicketsCount: $score->open_tickets_count,
            subscriptionStatus: $score->subscription_status,
            riskFlags: $score->risk_flags ?? [],
        );
    }
}
