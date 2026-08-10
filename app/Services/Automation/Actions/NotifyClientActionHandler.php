<?php

namespace App\Services\Automation\Actions;

use App\Enums\AutomationActionRiskLevel;
use App\Enums\ConsentChannel;
use App\Enums\FirmUserRole;
use App\Exceptions\AutomationActionPermanentException;
use App\Models\Client;
use App\Models\DomainEvent;
use App\Models\Firm;
use App\Models\Matter;
use App\Services\Automation\AutomationActionOutcome;
use App\Services\Automation\AutomationRecipientResolverService;
use App\Services\Automation\Contracts\AutomationActionHandler;
use App\Services\NotificationDispatchService;
use App\Services\TaskService;
use Illuminate\Support\Arr;

/**
 * NotifyClientActionHandler — Zero-Click Core Workflow Automation. The
 * first Automation Action that reaches a client, wired through the
 * EXISTING, unmodified pipeline (ConsentService via
 * NotificationEligibilityService, then NotificationDispatchService::
 * dispatch()) — never a second notification system, never a raw mail
 * call. dispatch() already gates on an active template, a verified
 * sender domain, and eligibility (consent/do-not-contact/suppression)
 * before ever queuing a send.
 *
 * Per this mission's own explicit safety rule ("if real delivery
 * transport is unavailable, create a staff task — never fake a
 * successful send"): a non-accepted NotificationDispatchResult (no
 * template, unverified domain, or ineligible/blocked/suppressed
 * recipient) creates a REQUIRES_REVIEW Task for the resolved
 * responsible attorney (falling back to Billing Staff) instead of
 * silently failing — this is a genuine outcome, not an error, so it is
 * returned as AutomationActionOutcome::succeeded() carrying the review
 * Task as its result reference, matching the codebase's own "skipped
 * vs succeeded" vocabulary (a blocked send is a real, audited terminal
 * state, not a retryable fault).
 *
 * config: {template_key: string, channel?: 'email'|'sms'|'whatsapp'|'portal' (default 'email'),
 *          review_task_title?: string}
 */
class NotifyClientActionHandler implements AutomationActionHandler
{
    public function __construct(
        private readonly NotificationDispatchService $dispatcher,
        private readonly TaskService $tasks,
        private readonly AutomationRecipientResolverService $recipients,
    ) {}

    public function riskLevel(): AutomationActionRiskLevel
    {
        return AutomationActionRiskLevel::AutoAllowed;
    }

    public function handle(Firm $firm, DomainEvent $event, array $config): AutomationActionOutcome
    {
        $templateKey = $config['template_key'] ?? null;

        if (! is_string($templateKey) || $templateKey === '') {
            throw new AutomationActionPermanentException('NotifyClient config requires a non-empty "template_key".');
        }

        $channel = ConsentChannel::tryFrom((string) ($config['channel'] ?? 'email'));

        if ($channel === null) {
            throw new AutomationActionPermanentException('NotifyClient config has an unrecognized "channel".');
        }

        $flat = Arr::dot($event->payload_json);
        $clientId = $this->resolveClientId($flat);

        if ($clientId === null) {
            return AutomationActionOutcome::skipped('No client could be resolved from this event.');
        }

        $client = Client::query()->where('firm_id', $firm->id)->find($clientId);

        if ($client === null) {
            return AutomationActionOutcome::skipped("Client #{$clientId} could not be resolved for this firm.");
        }

        $recipient = $channel === ConsentChannel::Email ? $client->email : $client->phone;

        if (! is_string($recipient) || $recipient === '') {
            return $this->reviewInstead($firm, $flat, $config, "Client #{$clientId} has no {$channel->value} address on file.");
        }

        $matterId = isset($flat['matter.id']) ? (int) $flat['matter.id'] : null;
        $matter = $matterId !== null ? Matter::query()->where('firm_id', $firm->id)->find($matterId) : null;

        $result = $this->dispatcher->dispatch(
            firm: $firm,
            client: $client,
            channel: $channel,
            recipient: $recipient,
            templateKey: $templateKey,
            language: $client->preferred_language ?? 'en',
            subject: $event,
            matter: $matter,
        );

        if (! $result->accepted) {
            return $this->reviewInstead($firm, $flat, $config, $result->reason ?? 'Client reminder could not be sent.', $matter);
        }

        return AutomationActionOutcome::succeeded(null, "Client reminder queued via {$channel->value}.");
    }

    /**
     * @param  array<string, mixed>  $flat
     */
    private function resolveClientId(array $flat): ?int
    {
        foreach (['client.id', 'matter.client_id', 'payment_plan.client_id'] as $key) {
            if (isset($flat[$key])) {
                return (int) $flat[$key];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $flat
     * @param  array<string, mixed>  $config
     */
    private function reviewInstead(Firm $firm, array $flat, array $config, string $reason, ?Matter $matter = null): AutomationActionOutcome
    {
        $matterId = $matter?->id ?? (isset($flat['matter.id']) ? (int) $flat['matter.id'] : null);
        $matter ??= $matterId !== null ? Matter::query()->where('firm_id', $firm->id)->find($matterId) : null;

        $assignee = $matter !== null
            ? $this->recipients->matterAssignedAttorney($firm, $matter->id)
            : null;

        $assignee ??= $this->recipients->usersWithRole($firm, FirmUserRole::BillingStaff)->first();

        if ($assignee === null) {
            return AutomationActionOutcome::skipped("Client reminder blocked ({$reason}) and no responsible staff could be resolved for review.");
        }

        $task = $this->tasks->create(
            firm: $firm,
            title: is_string($config['review_task_title'] ?? null) ? $config['review_task_title'] : 'Review client reminder — could not be sent automatically',
            matter: $matter,
            assignedTo: $assignee,
            description: $reason,
        );

        return AutomationActionOutcome::succeeded($task, "Client reminder blocked ({$reason}) — review task created.");
    }
}
