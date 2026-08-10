<?php

namespace App\Services\Automation\Actions;

use App\Enums\AutomationActionRiskLevel;
use App\Enums\FirmUserRole;
use App\Exceptions\AutomationActionPermanentException;
use App\Models\Document;
use App\Models\DomainEvent;
use App\Models\Firm;
use App\Models\Matter;
use App\Services\Automation\AutomationActionOutcome;
use App\Services\Automation\AutomationRecipientResolverService;
use App\Services\Automation\Contracts\AutomationActionHandler;
use App\Services\DocumentMatchingService;
use App\Services\DocumentRequestService;
use App\Services\DocumentSecurityService;
use App\Services\TaskService;
use Illuminate\Support\Arr;

/**
 * MatchDocumentToRequestActionHandler — Zero-Click Core Workflow
 * Automation, item 5. See DocumentMatchingService's own docblock for
 * the conservative-matching rationale. Calls
 * DocumentSecurityService::linkToRequestItem() then
 * DocumentRequestService::markSubmitted() — the same two canonical
 * services every other document-lifecycle handler in this codebase
 * already uses — never a direct write to documents/
 * document_request_items.
 */
class MatchDocumentToRequestActionHandler implements AutomationActionHandler
{
    public function __construct(
        private readonly DocumentMatchingService $matching,
        private readonly DocumentSecurityService $documentSecurity,
        private readonly DocumentRequestService $documentRequests,
        private readonly TaskService $tasks,
        private readonly AutomationRecipientResolverService $recipients,
    ) {}

    public function riskLevel(): AutomationActionRiskLevel
    {
        return AutomationActionRiskLevel::AutoAllowed;
    }

    public function handle(Firm $firm, DomainEvent $event, array $config): AutomationActionOutcome
    {
        $flat = Arr::dot($event->payload_json);

        if (isset($flat['document.document_request_item_id']) && $flat['document.document_request_item_id'] !== null) {
            return AutomationActionOutcome::skipped('This document was already linked to a document request item at upload time.');
        }

        $matterId = isset($flat['document.matter_id']) ? (int) $flat['document.matter_id'] : null;

        if ($matterId === null) {
            return AutomationActionOutcome::skipped('This document has no matter to match against.');
        }

        $matter = Matter::query()->where('firm_id', $firm->id)->find($matterId);

        if ($matter === null) {
            return AutomationActionOutcome::skipped("Matter #{$matterId} could not be resolved for this firm.");
        }

        $documentId = isset($flat['document.id']) ? (int) $flat['document.id'] : null;
        $document = $documentId !== null ? Document::query()->where('firm_id', $firm->id)->find($documentId) : null;

        if ($document === null) {
            return AutomationActionOutcome::skipped('The triggering document could not be resolved for this firm.');
        }

        $candidates = $this->matching->candidatesFor($firm, $matter);

        if ($candidates->count() === 1) {
            $item = $candidates->first();

            try {
                $this->documentSecurity->linkToRequestItem($document, $item);
                $updated = $this->documentRequests->markSubmitted($firm, $item);
            } catch (\RuntimeException $e) {
                throw new AutomationActionPermanentException($e->getMessage(), previous: $e);
            }

            return AutomationActionOutcome::succeeded($updated, 'Document matched to the single open document request item.');
        }

        if ($candidates->count() === 0) {
            return AutomationActionOutcome::skipped('No open document request item exists for this matter to match against.');
        }

        $assignee = $this->recipients->matterAssignedAttorney($firm, $matter->id)
            ?? $this->recipients->usersWithRole($firm, FirmUserRole::Paralegal)->first();

        if ($assignee === null) {
            return AutomationActionOutcome::skipped("Ambiguous document match ({$candidates->count()} candidates) and no responsible staff could be resolved for review.");
        }

        $task = $this->tasks->create(
            firm: $firm,
            title: 'Review document matching — multiple open requests could apply',
            matter: $matter,
            assignedTo: $assignee,
            description: "The uploaded document could match any of {$candidates->count()} open document request items. Please confirm which one it satisfies.",
        );

        return AutomationActionOutcome::succeeded($task, "Ambiguous match ({$candidates->count()} candidates) — review task created.");
    }
}
