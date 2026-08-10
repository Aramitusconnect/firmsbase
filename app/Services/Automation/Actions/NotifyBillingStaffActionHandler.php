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
 * NotifyBillingStaffActionHandler — Event-Driven Automation Engine,
 * item 6/13. No internal firm-staff notification service exists
 * anywhere in this codebase (NotificationDispatchService is
 * client-notification-only, confirmed by audit) — "notify" here means
 * one Task per Active BillingStaff FirmUser (TaskService::create(), the
 * only canonical Task creator). A true push notification/email is
 * ACTION_UNAVAILABLE pending a real internal-staff notification
 * service; this is a pre-existing platform gap, not something this
 * pass papers over.
 *
 * config: {title: string, description?: string, priority?: 'low'|'normal'|'high'|'urgent'}
 */
class NotifyBillingStaffActionHandler implements AutomationActionHandler
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
            throw new AutomationActionPermanentException('NotifyBillingStaff config requires a non-empty "title".');
        }

        $billingStaff = $this->recipients->usersWithRole($firm, FirmUserRole::BillingStaff);

        if ($billingStaff->isEmpty()) {
            return AutomationActionOutcome::skipped('This firm has no Active Billing Staff user to notify.');
        }

        $flat = Arr::dot($event->payload_json);
        $matterId = isset($flat['matter.id']) ? (int) $flat['matter.id'] : null;
        $matter = $matterId !== null ? Matter::query()->where('firm_id', $firm->id)->find($matterId) : null;
        $priority = TaskPriority::tryFrom((string) ($config['priority'] ?? 'normal')) ?? TaskPriority::Normal;
        $description = is_string($config['description'] ?? null) ? $config['description'] : null;

        $lastTask = null;

        foreach ($billingStaff as $user) {
            $lastTask = $this->tasks->create(
                firm: $firm,
                title: $title,
                matter: $matter,
                assignedTo: $user,
                priority: $priority,
                description: $description,
            );
        }

        return AutomationActionOutcome::succeeded($lastTask, "Notified {$billingStaff->count()} Billing Staff user(s).");
    }
}
