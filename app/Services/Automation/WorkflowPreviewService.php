<?php

namespace App\Services\Automation;

use App\Enums\AutomationActionType;
use App\Enums\DomainEventType;
use App\Models\AutomationRule;
use App\Models\Matter;

/**
 * WorkflowPreviewService — Zero-Click Core Workflow Automation, item
 * 24. Read-only simulation ONLY — never calls an AutomationActionHandler,
 * never writes a row of any kind. Scoped to MatterOpened-triggered
 * rules (the primary "apply this template to a Matter" case items 8/24
 * both use as their own worked example); a rule targeting any other
 * event type is reported as not previewable rather than guessing at a
 * generic simulated payload for events this service was never taught
 * the shape of.
 *
 * Reuses ConditionEvaluatorService unmodified for the match decision —
 * this is a genuinely new, small piece of functionality (describing
 * what an already-matched rule's own actions configuration WOULD do),
 * never a second condition-evaluation implementation.
 */
class WorkflowPreviewService
{
    public function __construct(private readonly ConditionEvaluatorService $conditions) {}

    /**
     * @return array{previewable: bool, would_match: bool, blocked_reason: ?string, requires_approval: bool, actions: array<int, string>}
     */
    public function previewForMatter(AutomationRule $rule, Matter $matter): array
    {
        if ($rule->event_type !== DomainEventType::MatterOpened) {
            return [
                'previewable' => false,
                'would_match' => false,
                'blocked_reason' => "Preview only supports rules triggered by matter_opened (this rule targets {$rule->event_type->value}).",
                'requires_approval' => $rule->requires_approval,
                'actions' => [],
            ];
        }

        if (! $rule->enabled) {
            return [
                'previewable' => true,
                'would_match' => false,
                'blocked_reason' => 'This rule is disabled — it would not run at all.',
                'requires_approval' => $rule->requires_approval,
                'actions' => [],
            ];
        }

        $payload = [
            'matter' => [
                'id' => $matter->id,
                'client_id' => $matter->client_id,
                'assigned_attorney_id' => $matter->assigned_attorney_id,
                'status' => $matter->status->value,
                'primary_practice_area_id' => $matter->primary_practice_area_id,
                'matter_type_id' => $matter->matter_type_id,
            ],
        ];

        $result = $this->conditions->evaluate($rule->event_type, $rule->conditions_json, $payload);

        if (! $result['matched']) {
            return [
                'previewable' => true,
                'would_match' => false,
                'blocked_reason' => 'This Matter does not satisfy the rule\'s own conditions.',
                'requires_approval' => $rule->requires_approval,
                'actions' => [],
            ];
        }

        return [
            'previewable' => true,
            'would_match' => true,
            'blocked_reason' => null,
            'requires_approval' => $rule->requires_approval,
            'actions' => array_map(fn (array $action) => $this->describeAction($action), $rule->actions_json),
        ];
    }

    /**
     * @param  array{action_type: string, config: array<string, mixed>}  $action
     */
    private function describeAction(array $action): string
    {
        $type = AutomationActionType::tryFrom((string) ($action['action_type'] ?? ''));
        $config = $action['config'] ?? [];

        return match ($type) {
            AutomationActionType::CreateTask => sprintf(
                'Would create 1 Task: "%s" (assigned to %s)',
                $config['title'] ?? 'Untitled task',
                $config['assigned_to'] ?? 'unresolved recipient',
            ),
            AutomationActionType::CreateDocumentRequest => sprintf(
                'Would create 1 Document Request "%s" with %d item(s)',
                $config['title'] ?? 'Document request',
                is_array($config['items'] ?? null) ? count($config['items']) : 0,
            ),
            AutomationActionType::NotifyClient => sprintf(
                'Would attempt a client reminder via %s using template "%s" (falls back to a review Task if blocked)',
                $config['channel'] ?? 'email',
                $config['template_key'] ?? 'unresolved template',
            ),
            AutomationActionType::NotifyBillingStaff => 'Would create 1 Task per Active Billing Staff user',
            AutomationActionType::NotifyResponsibleAttorney => 'Would create 1 Task for the Matter\'s assigned attorney (skipped if none)',
            AutomationActionType::EscalateDeadline => sprintf('Would create 1 escalation Task for %s', $config['escalate_to'] ?? 'unresolved recipient'),
            AutomationActionType::MarkDocumentRequestItemSubmitted => 'Would mark the linked document request item Submitted (skipped if none is linked)',
            AutomationActionType::MatchDocumentToRequest => 'Would attempt to match the document to an open document request item',
            null => 'Unknown action type — this rule row could not be fully described',
        };
    }
}
