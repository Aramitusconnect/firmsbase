<?php

namespace App\Services\Automation\Actions;

use App\Enums\AutomationActionRiskLevel;
use App\Enums\TaskPriority;
use App\Exceptions\AutomationActionPermanentException;
use App\Models\DomainEvent;
use App\Models\Firm;
use App\Models\Matter;
use App\Services\Automation\AutomationActionOutcome;
use App\Services\Automation\AutomationRecipientResolverService;
use App\Services\Automation\Contracts\AutomationActionHandler;
use App\Services\TaskService;
use Illuminate\Support\Arr;

/**
 * NotifyResponsibleAttorneyActionHandler — Event-Driven Automation
 * Engine, item 6/13. Resolves the triggering event's matter.id ->
 * Matter::assignedAttorney and creates a Task (TaskService::create())
 * for that one User. Skipped (never a guessed fallback recipient) when
 * the event carries no matter, or the matter has no assigned attorney.
 *
 * config: {title: string, description?: string, priority?: 'low'|'normal'|'high'|'urgent'}
 */
class NotifyResponsibleAttorneyActionHandler implements AutomationActionHandler
{
    public function __construct(
        private readonly TaskService $tasks,
        private readonly AutomationRecipientResolverService $recipients,
    ) {}

    public function riskLevel(): AutomationActionRiskLevel
    {
        return AutomationActionRiskLevel::AutoAllowed;
    }

    public function handle(Firm $firm, DomainEvent $event, array $config): AutomationActionOutcome
    {
        $title = $config['title'] ?? null;

        if (! is_string($title) || $title === '') {
            throw new AutomationActionPermanentException('NotifyResponsibleAttorney config requires a non-empty "title".');
        }

        $flat = Arr::dot($event->payload_json);
        $matterId = isset($flat['matter.id']) ? (int) $flat['matter.id'] : null;

        if ($matterId === null) {
            return AutomationActionOutcome::skipped('This event has no matter to resolve a responsible attorney from.');
        }

        $attorney = $this->recipients->matterAssignedAttorney($firm, $matterId);

        if ($attorney === null) {
            return AutomationActionOutcome::skipped("Matter #{$matterId} has no assigned attorney.");
        }

        $matter = Matter::query()->where('firm_id', $firm->id)->find($matterId);
        $priority = TaskPriority::tryFrom((string) ($config['priority'] ?? 'normal')) ?? TaskPriority::Normal;

        $task = $this->tasks->create(
            firm: $firm,
            title: $title,
            matter: $matter,
            assignedTo: $attorney,
            priority: $priority,
            description: is_string($config['description'] ?? null) ? $config['description'] : null,
        );

        return AutomationActionOutcome::succeeded($task);
    }
}
