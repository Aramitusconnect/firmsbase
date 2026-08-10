<?php

namespace App\Services\Automation\Actions;

use App\Enums\AutomationActionRiskLevel;
use App\Exceptions\AutomationActionPermanentException;
use App\Models\Client;
use App\Models\DomainEvent;
use App\Models\Firm;
use App\Models\Matter;
use App\Services\Automation\AutomationActionOutcome;
use App\Services\Automation\Contracts\AutomationActionHandler;
use App\Services\DocumentRequestService;
use Illuminate\Support\Arr;

/**
 * CreateDocumentRequestActionHandler — Zero-Click Core Workflow
 * Automation. Calls DocumentRequestService::create() — the ONLY
 * canonical creator of a DocumentRequest — never writes
 * document_requests/document_request_items directly. Used by Firm-
 * configured Matter-opening onboarding rules (item 8/30: a Firm's own
 * practice-area-specific document checklist, never a global "this is
 * legally required" assumption baked into this handler).
 *
 * config: {title?: string, instructions?: string, due_in_days?: int,
 *          items: array<int, array{label: string, is_required?: bool}>}
 */
class CreateDocumentRequestActionHandler implements AutomationActionHandler
{
    public function __construct(private readonly DocumentRequestService $documentRequests) {}

    public function riskLevel(): AutomationActionRiskLevel
    {
        return AutomationActionRiskLevel::AutoAllowed;
    }

    public function handle(Firm $firm, DomainEvent $event, array $config): AutomationActionOutcome
    {
        $items = $config['items'] ?? null;

        if (! is_array($items) || $items === []) {
            throw new AutomationActionPermanentException('CreateDocumentRequest config requires a non-empty "items" array.');
        }

        foreach ($items as $item) {
            if (! is_array($item) || ! is_string($item['label'] ?? null) || $item['label'] === '') {
                throw new AutomationActionPermanentException('CreateDocumentRequest config: every item requires a non-empty "label".');
            }
        }

        $flat = Arr::dot($event->payload_json);
        $clientId = isset($flat['client.id']) ? (int) $flat['client.id'] : (isset($flat['matter.client_id']) ? (int) $flat['matter.client_id'] : null);

        if ($clientId === null) {
            return AutomationActionOutcome::skipped('No client could be resolved from this event.');
        }

        $client = Client::query()->where('firm_id', $firm->id)->find($clientId);

        if ($client === null) {
            return AutomationActionOutcome::skipped("Client #{$clientId} could not be resolved for this firm.");
        }

        $matterId = isset($flat['matter.id']) ? (int) $flat['matter.id'] : null;
        $matter = $matterId !== null ? Matter::query()->where('firm_id', $firm->id)->find($matterId) : null;
        $dueAt = is_numeric($config['due_in_days'] ?? null) ? now()->addDays((int) $config['due_in_days']) : null;

        $documentRequest = $this->documentRequests->create(
            firm: $firm,
            client: $client,
            items: array_map(fn (array $item): array => [
                'label' => $item['label'],
                'is_required' => (bool) ($item['is_required'] ?? true),
            ], $items),
            matter: $matter,
            title: is_string($config['title'] ?? null) ? $config['title'] : 'Document request',
            instructions: is_string($config['instructions'] ?? null) ? $config['instructions'] : null,
            dueAt: $dueAt,
        );

        return AutomationActionOutcome::succeeded($documentRequest);
    }
}
