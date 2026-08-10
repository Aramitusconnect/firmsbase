<?php

namespace App\Services\Automation\Actions;

use App\Enums\AutomationActionRiskLevel;
use App\Exceptions\AutomationActionPermanentException;
use App\Models\DocumentRequestItem;
use App\Models\DomainEvent;
use App\Models\Firm;
use App\Services\Automation\AutomationActionOutcome;
use App\Services\Automation\Contracts\AutomationActionHandler;
use App\Services\DocumentRequestService;
use Illuminate\Support\Arr;

/**
 * MarkDocumentRequestItemSubmittedActionHandler — Event-Driven
 * Automation Engine, item 6/13. Calls DocumentRequestService::markSubmitted()
 * — the ONLY canonical way to transition a checklist item — never
 * writes document_request_items directly. A genuinely new integration
 * point (audit confirmed no existing service auto-wires
 * upload -> checklist-satisfied today), but the call itself is 100% the
 * existing, unmodified canonical method.
 *
 * DocumentRequestItem carries no firm_id of its own (scoped
 * transitively through document_request_id, per
 * DocumentRequestService::markViewed()'s own docblock) — this handler
 * explicitly verifies the resolved item's parent DocumentRequest
 * belongs to $firm before ever passing it to markSubmitted(), rather
 * than trusting an ambient RLS filter alone.
 *
 * config: {} (no configuration — the item to submit comes entirely
 * from the triggering event's own document.document_request_item_id).
 */
class MarkDocumentRequestItemSubmittedActionHandler implements AutomationActionHandler
{
    public function __construct(private readonly DocumentRequestService $documentRequests) {}

    public function riskLevel(): AutomationActionRiskLevel
    {
        return AutomationActionRiskLevel::AutoAllowed;
    }

    public function handle(Firm $firm, DomainEvent $event, array $config): AutomationActionOutcome
    {
        $flat = Arr::dot($event->payload_json);
        $itemId = $flat['document.document_request_item_id'] ?? null;

        if ($itemId === null) {
            return AutomationActionOutcome::skipped('This document was not linked to a document request item at upload time.');
        }

        $item = DocumentRequestItem::with('documentRequest')->find((int) $itemId);

        if ($item === null || $item->documentRequest === null || (int) $item->documentRequest->firm_id !== (int) $firm->id) {
            return AutomationActionOutcome::skipped("Document request item #{$itemId} could not be resolved for this firm.");
        }

        try {
            $updated = $this->documentRequests->markSubmitted($firm, $item);
        } catch (\RuntimeException $e) {
            // "This item cannot be submitted from its current status" —
            // an invalid business state (e.g. already Approved/Waived),
            // not a transient fault. Never retried.
            throw new AutomationActionPermanentException($e->getMessage(), previous: $e);
        }

        return AutomationActionOutcome::succeeded($updated);
    }
}
