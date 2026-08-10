<?php

namespace App\Services\Automation\Actions;

use App\Enums\AutomationActionRiskLevel;
use App\Enums\FirmUserRole;
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
 * EscalateDeadlineActionHandler — Event-Driven Automation Engine, item
 * 6/13. "Escalate according to Firm configuration" (master prompt's own
 * starter 2 wording) has nothing to read from: Deadline has no
 * escalate_to_user_id-like field and FirmSettings has no
 * deadline-escalation concept at all (confirmed by audit — neither
 * exists). The rule's own config IS the firm's configuration surface
 * here — escalate_to names a role, resolved to its first Active
 * FirmUser (deterministic by id) — Firm-editable via the rule itself,
 * which is arguably a better fit than a rigid FirmSettings field would
 * have been. Creates a High-priority Task (TaskService::create()).
 *
 * config: {title: string, description?: string, escalate_to: 'role:<FirmUserRole value>'}
 */
class EscalateDeadlineActionHandler implements AutomationActionHandler
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
            throw new AutomationActionPermanentException('EscalateDeadline config requires a non-empty "title".');
        }

        $escalateTo = $config['escalate_to'] ?? null;

        if (! is_string($escalateTo) || ! str_starts_with($escalateTo, 'role:')) {
            throw new AutomationActionPermanentException('EscalateDeadline config requires "escalate_to" in the form "role:<FirmUserRole value>".');
        }

        $role = FirmUserRole::tryFrom(substr($escalateTo, 5));

        if ($role === null) {
            throw new AutomationActionPermanentException("EscalateDeadline config has an unrecognized role in escalate_to [{$escalateTo}].");
        }

        $escalationTarget = $this->recipients->usersWithRole($firm, $role)->first();

        if ($escalationTarget === null) {
            return AutomationActionOutcome::skipped("This firm has no Active user with role [{$role->value}] to escalate to.");
        }

        $flat = Arr::dot($event->payload_json);
        $matterId = isset($flat['matter.id']) ? (int) $flat['matter.id'] : null;
        $matter = $matterId !== null ? Matter::query()->where('firm_id', $firm->id)->find($matterId) : null;

        $task = $this->tasks->create(
            firm: $firm,
            title: $title,
            matter: $matter,
            assignedTo: $escalationTarget,
            priority: TaskPriority::Urgent,
            description: is_string($config['description'] ?? null) ? $config['description'] : null,
        );

        return AutomationActionOutcome::succeeded($task);
    }
}
