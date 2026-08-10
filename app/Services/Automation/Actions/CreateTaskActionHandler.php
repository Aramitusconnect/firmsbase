<?php

namespace App\Services\Automation\Actions;

use App\Enums\AutomationActionRiskLevel;
use App\Enums\FirmUserRole;
use App\Enums\TaskPriority;
use App\Exceptions\AutomationActionPermanentException;
use App\Models\DomainEvent;
use App\Models\Firm;
use App\Models\Matter;
use App\Models\User;
use App\Services\Automation\AutomationActionOutcome;
use App\Services\Automation\AutomationRecipientResolverService;
use App\Services\Automation\Contracts\AutomationActionHandler;
use App\Services\TaskService;
use Illuminate\Support\Arr;

/**
 * CreateTaskActionHandler — Event-Driven Automation Engine, item 6. The
 * generic task-creation action. Calls TaskService::create() (the ONLY
 * canonical creator of a Task) — never writes to the tasks table
 * directly.
 *
 * config: {title: string, description?: string, priority?: 'low'|'normal'|'high'|'urgent',
 *          due_in_days?: int, assigned_to: 'matter_assigned_attorney'|'role:<FirmUserRole value>'}
 *
 * assigned_to='matter_assigned_attorney' resolves the triggering
 * event's own matter.id (from its payload, per
 * AutomationFieldAllowlistRegistry) -> Matter::assignedAttorney.
 * assigned_to='role:<value>' resolves every Active FirmUser with that
 * role and picks the first by id (deterministic, but arbitrary among
 * more than one match) — role-based fan-out (one task per match) is
 * NotifyBillingStaff's own, more specific behavior, not this generic
 * action's.
 */
class CreateTaskActionHandler implements AutomationActionHandler
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
            throw new AutomationActionPermanentException('CreateTask config requires a non-empty "title".');
        }

        $assignedToKey = $config['assigned_to'] ?? null;

        if (! is_string($assignedToKey) || $assignedToKey === '') {
            throw new AutomationActionPermanentException('CreateTask config requires a non-empty "assigned_to".');
        }

        $flat = Arr::dot($event->payload_json);
        $matterId = isset($flat['matter.id']) ? (int) $flat['matter.id'] : null;

        $assignee = match (true) {
            $assignedToKey === 'matter_assigned_attorney' => $this->recipients->matterAssignedAttorney($firm, $matterId),
            str_starts_with($assignedToKey, 'role:') => $this->resolveFirstByRole($firm, substr($assignedToKey, 5)),
            default => throw new AutomationActionPermanentException("CreateTask config has an unrecognized assigned_to strategy [{$assignedToKey}]."),
        };

        if ($assignee === null) {
            return AutomationActionOutcome::skipped("No recipient could be resolved for assigned_to [{$assignedToKey}].");
        }

        $matter = $matterId !== null ? Matter::query()->where('firm_id', $firm->id)->find($matterId) : null;
        $priority = TaskPriority::tryFrom((string) ($config['priority'] ?? 'normal')) ?? TaskPriority::Normal;
        $dueAt = is_numeric($config['due_in_days'] ?? null) ? now()->addDays((int) $config['due_in_days']) : null;

        $task = $this->tasks->create(
            firm: $firm,
            title: $title,
            matter: $matter,
            assignedTo: $assignee,
            priority: $priority,
            dueAt: $dueAt,
            description: is_string($config['description'] ?? null) ? $config['description'] : null,
        );

        return AutomationActionOutcome::succeeded($task);
    }

    private function resolveFirstByRole(Firm $firm, string $roleValue): ?User
    {
        $role = FirmUserRole::tryFrom($roleValue);

        if ($role === null) {
            throw new AutomationActionPermanentException("CreateTask config has an unrecognized role [{$roleValue}].");
        }

        return $this->recipients->usersWithRole($firm, $role)->first();
    }
}
