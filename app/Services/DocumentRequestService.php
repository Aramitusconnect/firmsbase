<?php

namespace App\Services;

use App\Enums\DocumentRequestItemStatus;
use App\Enums\DocumentRequestStatus;
use App\Models\Client;
use App\Models\DocumentRequest;
use App\Models\DocumentRequestItem;
use App\Models\Firm;
use App\Models\Matter;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * DocumentRequestService — the only place a DocumentRequest/its items
 * are created, and the only place DocumentRequest::status is
 * recomputed (always derived from its items, never hand-set
 * independently — mirrors PaymentPlan's schedule-only design in
 * Phase 3).
 */
class DocumentRequestService
{
    /**
     * @param  array<int, array{label:string, is_required?:bool}>  $items
     */
    public function create(
        Firm $firm,
        Client $client,
        array $items,
        ?Matter $matter = null,
        string $title = 'Document request',
        ?string $instructions = null,
        ?\DateTimeInterface $dueAt = null,
        ?User $createdBy = null,
    ): DocumentRequest {
        if (empty($items)) {
            throw new \InvalidArgumentException('At least one requested item is required.');
        }

        return DB::transaction(function () use ($firm, $client, $matter, $items, $title, $instructions, $dueAt, $createdBy) {
            $request = DocumentRequest::create([
                'firm_id' => $firm->id,
                'matter_id' => $matter?->id,
                'client_id' => $client->id,
                'status' => DocumentRequestStatus::Open,
                'title' => $title,
                'instructions' => $instructions,
                'due_at' => $dueAt,
                'created_by' => $createdBy?->id,
            ]);

            foreach ($items as $item) {
                DocumentRequestItem::create([
                    'document_request_id' => $request->id,
                    'label' => $item['label'],
                    'status' => DocumentRequestItemStatus::Requested,
                    'is_required' => $item['is_required'] ?? true,
                ]);
            }

            return $request->fresh('items');
        });
    }

    public function markViewed(DocumentRequestItem $item): DocumentRequestItem
    {
        if ($item->status !== DocumentRequestItemStatus::Requested) {
            return $item;
        }

        $item->update(['status' => DocumentRequestItemStatus::Viewed, 'viewed_at' => now()]);
        $this->recomputeParentStatus($item->documentRequest);

        return $item->fresh();
    }

    public function markSubmitted(DocumentRequestItem $item): DocumentRequestItem
    {
        if (! in_array($item->status, [
            DocumentRequestItemStatus::Requested,
            DocumentRequestItemStatus::Viewed,
            DocumentRequestItemStatus::NeedsReplacement,
        ], true)) {
            throw new \RuntimeException('This item cannot be submitted from its current status.');
        }

        $item->update(['status' => DocumentRequestItemStatus::Submitted, 'submitted_at' => now()]);
        $this->recomputeParentStatus($item->documentRequest);

        return $item->fresh();
    }

    public function markUnderReview(DocumentRequestItem $item): DocumentRequestItem
    {
        if ($item->status !== DocumentRequestItemStatus::Submitted) {
            throw new \RuntimeException('Only a submitted item can move under review.');
        }

        $item->update(['status' => DocumentRequestItemStatus::UnderReview]);

        return $item->fresh();
    }

    public function approve(DocumentRequestItem $item, User $reviewer): DocumentRequestItem
    {
        $item->update([
            'status' => DocumentRequestItemStatus::Approved,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
        ]);

        $this->recomputeParentStatus($item->documentRequest);

        return $item->fresh();
    }

    public function reject(DocumentRequestItem $item, User $reviewer, string $reason): DocumentRequestItem
    {
        $item->update([
            'status' => DocumentRequestItemStatus::Rejected,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'rejected_reason' => $reason,
        ]);

        $this->recomputeParentStatus($item->documentRequest);

        return $item->fresh();
    }

    public function requestReplacement(DocumentRequestItem $item, User $reviewer, string $reason): DocumentRequestItem
    {
        $item->update([
            'status' => DocumentRequestItemStatus::NeedsReplacement,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'rejected_reason' => $reason,
        ]);

        $this->recomputeParentStatus($item->documentRequest);

        return $item->fresh();
    }

    public function waive(DocumentRequestItem $item, User $staff, ?string $reason = null): DocumentRequestItem
    {
        $item->update([
            'status' => DocumentRequestItemStatus::Waived,
            'waived_by' => $staff->id,
            'waived_at' => now(),
            'rejected_reason' => $reason,
        ]);

        $this->recomputeParentStatus($item->documentRequest);

        return $item->fresh();
    }

    private function recomputeParentStatus(DocumentRequest $request): void
    {
        $items = $request->items()->get();

        $terminal = fn ($status) => in_array($status, [
            DocumentRequestItemStatus::Approved,
            DocumentRequestItemStatus::Waived,
            DocumentRequestItemStatus::Expired,
        ], true);

        $allTerminal = $items->every(fn (DocumentRequestItem $i) => $terminal($i->status));
        $anyTerminal = $items->contains(fn (DocumentRequestItem $i) => $terminal($i->status));

        $status = match (true) {
            $allTerminal => DocumentRequestStatus::Fulfilled,
            $anyTerminal => DocumentRequestStatus::PartiallyFulfilled,
            default => DocumentRequestStatus::Open,
        };

        $request->update(['status' => $status]);
    }
}
