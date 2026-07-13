<?php

namespace App\Services;

use App\Enums\CustomerHealthRiskLevel;
use App\Models\CustomerSuccessHealthScore;
use App\Models\Firm;
use App\Models\ProductAnalyticsEvent;

/**
 * CustomerSuccessHealthScoreService — the only writer of
 * customer_success_health_scores. compute() produces a new snapshot
 * row every time it is called (mirrors UsageRollupService), it never
 * mutates a prior snapshot. Every count here is a safe aggregate
 * number derived from existing relations/services — no document
 * content, matter content, or message bodies are read or stored.
 *
 * last_login_at is derived from the most recent ClientPortalLogin
 * ProductAnalyticsEvent for the firm. No firm-staff login tracking
 * (e.g. a users.last_login_at column) exists anywhere in this project
 * as of Phase 7, so this field reflects client portal login recency
 * only when such an event has been recorded; it is null otherwise.
 * open_tickets_count is always null — no support ticketing table
 * exists in this project yet; a later phase that adds one should wire
 * its count in here rather than this service inventing one.
 *
 * Section 39A-3L, Checkpoint 22 — payment_plans is now FORCE-RLS
 * protected; $firm->paymentPlans()->count() is wrapped in its own
 * tight runWithFirmContext() call below. The sibling active-users/
 * matters/clients/documents/invoices/payments counts in this same
 * method remain unwrapped — a pre-existing gap from when those tables
 * were force-activated in earlier checkpoints, left untouched here as
 * out of this checkpoint's narrow payment_plans scope (compute() has
 * no production caller today, only tests/governance mapping
 * references, which is why this has not yet surfaced as a live bug).
 */
class CustomerSuccessHealthScoreService
{
    public function __construct(
        private readonly QueueHealthService $queueHealthService,
    ) {
    }

    public function compute(Firm $firm): CustomerSuccessHealthScore
    {
        $activeUsersCount = $firm->firmUsers()->where('status', 'active')->count();
        $mattersCount = $firm->matters()->count();
        $clientsCount = $firm->clients()->count();
        $documentsCount = $firm->documents()->count();
        $invoicesCount = $firm->invoices()->count();
        $paymentPlansCount = (new TenantContextService())->runWithFirmContext($firm, fn () => $firm->paymentPlans()->count());
        $paymentsCount = $firm->payments()->count();

        $aiUsageCount = ProductAnalyticsEvent::query()
            ->where('firm_id', $firm->id)
            ->where('event_type', \App\Enums\ProductAnalyticsEventType::AiUsed->value)
            ->count();

        $lastLoginAt = ProductAnalyticsEvent::query()
            ->where('firm_id', $firm->id)
            ->where('event_type', \App\Enums\ProductAnalyticsEventType::ClientPortalLogin->value)
            ->max('occurred_at');

        $failedJobsCount = $this->queueHealthService->failedJobsCount();

        $onboardingProgressPercent = $this->onboardingProgressPercent($firm);

        [$score, $riskLevel, $riskFlags] = $this->scoreAndRiskFlags(
            $activeUsersCount,
            $onboardingProgressPercent,
            $failedJobsCount,
        );

        return CustomerSuccessHealthScore::create([
            'firm_id' => $firm->id,
            'computed_at' => now(),
            'score' => $score,
            'risk_level' => $riskLevel,
            'onboarding_progress_percent' => $onboardingProgressPercent,
            'last_login_at' => $lastLoginAt,
            'active_users_count' => $activeUsersCount,
            'matters_count' => $mattersCount,
            'clients_count' => $clientsCount,
            'documents_count' => $documentsCount,
            'invoices_count' => $invoicesCount,
            'payment_plans_count' => $paymentPlansCount,
            'payments_count' => $paymentsCount,
            'ai_usage_count' => $aiUsageCount,
            'storage_bytes' => null,
            'failed_jobs_count' => $failedJobsCount,
            'open_tickets_count' => null,
            'subscription_status' => $firm->billingAccount?->status?->value,
            'risk_flags' => $riskFlags,
        ]);
    }

    private function onboardingProgressPercent(Firm $firm): ?int
    {
        $project = $firm->implementationProject;

        if ($project === null) {
            return null;
        }

        $tasks = $project->tasks()->where('is_required', true)->get();

        if ($tasks->isEmpty()) {
            return null;
        }

        $completed = $tasks->whereIn('status', [
            \App\Enums\ImplementationTaskStatus::Completed,
            \App\Enums\ImplementationTaskStatus::Skipped,
        ])->count();

        return (int) round(($completed / $tasks->count()) * 100);
    }

    /**
     * @return array{0: int, 1: CustomerHealthRiskLevel, 2: array<int, string>}
     */
    private function scoreAndRiskFlags(int $activeUsersCount, ?int $onboardingProgressPercent, int $failedJobsCount): array
    {
        $score = 100;
        $flags = [];

        if ($activeUsersCount === 0) {
            $score -= 40;
            $flags[] = 'no_active_users';
        }

        if ($onboardingProgressPercent !== null && $onboardingProgressPercent < 50) {
            $score -= 20;
            $flags[] = 'onboarding_incomplete';
        }

        if ($failedJobsCount > 50) {
            $score -= 15;
            $flags[] = 'elevated_failed_jobs';
        }

        $score = max(0, min(100, $score));

        $riskLevel = match (true) {
            $score < 40 => CustomerHealthRiskLevel::Critical,
            $score < 70 => CustomerHealthRiskLevel::AtRisk,
            default => CustomerHealthRiskLevel::Healthy,
        };

        return [$score, $riskLevel, $flags];
    }
}
