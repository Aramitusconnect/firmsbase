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

        return (new TenantContextService())->runWithFirmContext($firm, function () use ($firm, $client, $matter, $items, $title, $instructions, $dueAt, $createdBy) {
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
        });
    }

    /**
     * Section 39A-3L, Checkpoint 10 — $firm is now an explicit required
     * parameter: DocumentRequestItem carries no firm_id of its own, so
     * there is no safe way to derive the firm from $item alone once
     * document_requests is FORCE-protected (the item's own table has no
     * RLS, so $item->update() always silently succeeds, but the
     * subsequent lazy-load of $item->documentRequest inside
     * recomputeParentStatus() would return null with no active
     * context). Wrapping the WHOLE method body (not just the
     * recomputeParentStatus() call) in runWithFirmContext()+
     * DB::transaction() also fixes a pre-existing atomicity gap: the
     * item's own update() previously had no transaction, so a crash
     * between it and recomputeParentStatus() could leave the item's
     * status durably persisted with the parent's aggregate status never
     * recomputed.
     */
    public function markViewed(Firm $firm, DocumentRequestItem $item): DocumentRequestItem
    {
        if ($item->status !== DocumentRequestItemStatus::Requested) {
            return $item;
        }

        return (new TenantContextService())->runWithFirmContext($firm, function () use ($item) {
            return DB::transaction(function () use ($item) {
                $item->update(['status' => DocumentRequestItemStatus::Viewed, 'viewed_at' => now()]);
                $this->recomputeParentStatus($item->documentRequest);

                return $item->fresh();
            });
        });
    }

    public function markSubmitted(Firm $firm, DocumentRequestItem $item): DocumentRequestItem
    {
        if (! in_array($item->status, [
            DocumentRequestItemStatus::Requested,
            DocumentRequestItemStatus::Viewed,
            DocumentRequestItemStatus::NeedsReplacement,
        ], true)) {
            throw new \RuntimeException('This item cannot be submitted from its current status.');
        }

        return (new TenantContextService())->runWithFirmContext($firm, function () use ($item) {
            return DB::transaction(function () use ($item) {
                $item->update(['status' => DocumentRequestItemStatus::Submitted, 'submitted_at' => now()]);
                $this->recomputeParentStatus($item->documentRequest);

                return $item->fresh();
            });
        });
    }

    /**
     * Section 39A-3L, Checkpoint 10 — unlike its six sibling mutators,
     * markUnderReview() never called recomputeParentStatus() before
     * this batch; that pre-existing business behavior is preserved
     * unchanged here (only tenant-context wiring + atomicity are added,
     * per this batch's scope).
     */
    public function markUnderReview(Firm $firm, DocumentRequestItem $item): DocumentRequestItem
    {
        if ($item->status !== DocumentRequestItemStatus::Submitted) {
            throw new \RuntimeException('Only a submitted item can move under review.');
        }

        return (new TenantContextService())->runWithFirmContext($firm, function () use ($item) {
            return DB::transaction(function () use ($item) {
                $item->update(['status' => DocumentRequestItemStatus::UnderReview]);

                return $item->fresh();
            });
        });
    }

    public function approve(Firm $firm, DocumentRequestItem $item, User $reviewer): DocumentRequestItem
    {
        return (new TenantContextService())->runWithFirmContext($firm, function () use ($item, $reviewer) {
            return DB::transaction(function () use ($item, $reviewer) {
                $item->update([
                    'status' => DocumentRequestItemStatus::Approved,
                    'reviewed_by' => $reviewer->id,
                    'reviewed_at' => now(),
                ]);

                $this->recomputeParentStatus($item->documentRequest);

                return $item->fresh();
            });
        });
    }

    public function reject(Firm $firm, DocumentRequestItem $item, User $reviewer, string $reason): DocumentRequestItem
    {
        return (new TenantContextService())->runWithFirmContext($firm, function () use ($item, $reviewer, $reason) {
            return DB::transaction(function () use ($item, $reviewer, $reason) {
                $item->update([
                    'status' => DocumentRequestItemStatus::Rejected,
                    'reviewed_by' => $reviewer->id,
                    'reviewed_at' => now(),
                    'rejected_reason' => $reason,
                ]);

                $this->recomputeParentStatus($item->documentRequest);

                return $item->fresh();
            });
        });
    }

    public function requestReplacement(Firm $firm, DocumentRequestItem $item, User $reviewer, string $reason): DocumentRequestItem
    {
        return (new TenantContextService())->runWithFirmContext($firm, function () use ($item, $reviewer, $reason) {
            return DB::transaction(function () use ($item, $reviewer, $reason) {
                $item->update([
                    'status' => DocumentRequestItemStatus::NeedsReplacement,
                    'reviewed_by' => $reviewer->id,
                    'reviewed_at' => now(),
                    'rejected_reason' => $reason,
                ]);

                $this->recomputeParentStatus($item->documentRequest);

                return $item->fresh();
            });
        });
    }

    public function waive(Firm $firm, DocumentRequestItem $item, User $staff, ?string $reason = null): DocumentRequestItem
    {
        return (new TenantContextService())->runWithFirmContext($firm, function () use ($item, $staff, $reason) {
            return DB::transaction(function () use ($item, $staff, $reason) {
                $item->update([
                    'status' => DocumentRequestItemStatus::Waived,
                    'waived_by' => $staff->id,
                    'waived_at' => now(),
                    'rejected_reason' => $reason,
                ]);

                $this->recomputeParentStatus($item->documentRequest);

                return $item->fresh();
            });
        });
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
