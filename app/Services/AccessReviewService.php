<?php

namespace App\Services;

use App\Enums\AccessReviewItemDecision;
use App\Enums\AccessReviewScope;
use App\Enums\AccessReviewStatus;
use App\Models\AccessReview;
use App\Models\AccessReviewItem;
use App\Models\ApiKey;
use App\Models\Firm;
use App\Models\FirmAiProviderKey;
use App\Models\FirmUser;
use App\Models\PlatformAdmin;
use App\Models\SupportAccessRequest;
use App\Models\WebhookSubscription;
use App\ValueObjects\AccessReviewSummary;

/**
 * AccessReviewService — creates review campaigns and enumerates items
 * per scope from EXISTING models (no new access-grant tables). Approved
 * decision #10: recordDecision() is RECORD-ONLY — a Revoke/Modify
 * decision never itself revokes anything; it stays a manual or future
 * separately-scoped automated action against the relevant existing
 * model's own service (e.g. ApiKeyService::revoke()).
 */
class AccessReviewService
{
    public function initiate(
        AccessReviewScope $scope,
        PlatformAdmin $initiatedBy,
        ?Firm $firm = null,
        ?\DateTimeInterface $dueAt = null,
    ): AccessReview {
        $review = AccessReview::create([
            'firm_id' => $firm?->id,
            'scope' => $scope,
            'status' => AccessReviewStatus::InProgress,
            'initiated_by_platform_admin_id' => $initiatedBy->id,
            'initiated_at' => now(),
            'due_at' => $dueAt,
        ]);

        $this->enumerateItems($review);

        return $review->fresh();
    }

    public function enumerateItems(AccessReview $review): void
    {
        $subjects = match ($review->scope) {
            AccessReviewScope::PlatformAdmins => PlatformAdmin::query()->get(),
            // support_access_requests has permanent FORCE ROW LEVEL SECURITY
            // (this wave's own 2026_08_28_960004 migration) with a strict,
            // non-nullable firm_id policy — there is no NULL-context bypass,
            // so this list must be built by looping every firm and merging
            // per-firm results in PHP (see supportAccessRequesterIdsAcrossAllFirms())
            // rather than querying directly; do not "simplify" this back to
            // an unwrapped cross-firm query, it will silently return nothing.
            AccessReviewScope::SupportAgents => PlatformAdmin::query()
                ->whereIn('id', $this->supportAccessRequesterIdsAcrossAllFirms())
                ->get(),
            // firm_users has permanent FORCE ROW LEVEL SECURITY
            // (Section 39A-3B). $review->firm_id is nullable at the
            // model level (platform-scope reviews), but FirmAdmins/
            // EmployeeRoles reviews are always firm-scoped in
            // practice; when it is null here, the query already
            // returned nothing before FORCE existed (firm_id = null
            // never matches), so preserving that exact no-op behavior
            // is correct rather than guessing a firm.
            AccessReviewScope::FirmAdmins, AccessReviewScope::EmployeeRoles => $review->firm_id !== null
                ? (new TenantContextService())->runWithFirmContext(
                    $review->firm_id,
                    fn () => FirmUser::query()->where('firm_id', $review->firm_id)->get(),
                )
                : FirmUser::query()->where('firm_id', $review->firm_id)->get(),
            AccessReviewScope::ApiKeys => ApiKey::query()
                ->when($review->firm_id, fn ($q) => $q->where('firm_id', $review->firm_id))
                ->get(),
            AccessReviewScope::Webhooks => WebhookSubscription::query()
                ->when($review->firm_id, fn ($q) => $q->where('firm_id', $review->firm_id))
                ->get(),
            AccessReviewScope::AiTools => FirmAiProviderKey::query()
                ->when($review->firm_id, fn ($q) => $q->where('firm_id', $review->firm_id))
                ->get(),
        };

        foreach ($subjects as $subject) {
            AccessReviewItem::create([
                'access_review_id' => $review->id,
                'subject_type' => $subject::class,
                'subject_id' => $subject->id,
                'subject_snapshot_json' => ['id' => $subject->id],
                'decision' => AccessReviewItemDecision::Pending,
            ]);
        }
    }

    /**
     * support_access_requests carries permanent FORCE ROW LEVEL SECURITY
     * with a strict, non-nullable firm_id policy (no NULL-context bypass),
     * and requested_by values legitimately span every firm a platform admin
     * may have requested access to. The only correct way to read "distinct
     * requested_by across every firm" is therefore to iterate every firm
     * explicitly, wrapping each firm's read in its own runWithFirmContext(),
     * and merge the per-firm results in PHP — mirroring the established
     * HealthCheckService::runAllAndRecord() per-firm aggregation pattern for
     * platform-wide reads over an RLS-forced table. runWithoutFirmContext()
     * would NOT work here: it still yields zero rows against a strict
     * non-null firm_id policy, it does not bypass RLS.
     */
    private function supportAccessRequesterIdsAcrossAllFirms(): array
    {
        $tenantContext = new TenantContextService();
        $requesterIds = collect();

        foreach (Firm::query()->get() as $firm) {
            $requesterIds = $requesterIds->merge(
                $tenantContext->runWithFirmContext(
                    $firm,
                    fn () => SupportAccessRequest::query()
                        ->where('firm_id', $firm->id)
                        ->whereNotNull('requested_by')
                        ->distinct()
                        ->pluck('requested_by'),
                )
            );
        }

        return $requesterIds->unique()->values()->all();
    }

    public function recordDecision(
        AccessReviewItem $item,
        AccessReviewItemDecision $decision,
        PlatformAdmin $reviewer,
        ?string $notes = null,
    ): AccessReviewItem {
        $item->update([
            'decision' => $decision,
            'reviewed_by_platform_admin_id' => $reviewer->id,
            'reviewed_at' => now(),
            'notes' => $notes,
        ]);

        return $item->fresh();
    }

    public function summary(AccessReview $review): AccessReviewSummary
    {
        $items = $review->items;

        return new AccessReviewSummary(
            totalItems: $items->count(),
            pendingCount: $items->where('decision', AccessReviewItemDecision::Pending)->count(),
            retainedCount: $items->where('decision', AccessReviewItemDecision::Retain)->count(),
            revokedCount: $items->where('decision', AccessReviewItemDecision::Revoke)->count(),
            modifiedCount: $items->where('decision', AccessReviewItemDecision::Modify)->count(),
        );
    }

    public function complete(AccessReview $review): AccessReview
    {
        $summary = $this->summary($review);

        if (! $summary->isComplete()) {
            throw new \RuntimeException('An access review cannot be completed while items remain Pending.');
        }

        $review->update(['status' => AccessReviewStatus::Completed, 'completed_at' => now()]);

        return $review->fresh();
    }
}
