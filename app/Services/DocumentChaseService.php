<?php

namespace App\Services;

use App\Models\DocumentChaseRule;
use App\Models\DocumentRequestItem;
use App\Models\Firm;
use App\Models\User;
use App\Services\TimelineEventRecorder;
use App\ValueObjects\DunningEligibility;

/**
 * DocumentChaseService — evaluates and logs one chase attempt for a
 * DocumentRequestItem, reusing NotificationEligibilityService (which
 * itself reuses Phase 1/2's consent/preference foundation — no second
 * consent system, project rule) and Phase 3's DunningEligibility VO.
 * "Client reminders stop when approved, waived, expired, or paused by
 * staff" (PDF) is enforced via DocumentRequestItem::
 * isChaseEligibleStatus() before any consent check even runs.
 *
 * Section 39A-3L, Checkpoint 10 — checkAndLog(), logEvent(),
 * escalate(), pause(), and resume() now take an explicit Firm $firm
 * parameter (mirroring the existing convention already used by the
 * sibling DocumentChaseSchedulerService::applicableRule(Firm $firm,
 * ...) in this same file family), since document_requests is
 * FORCE-protected but DocumentRequestItem carries no firm_id of its
 * own — the previous unwrapped $item->documentRequest lazy-load
 * returned null with no active context, crashing with a TypeError.
 * logEvent() is a private helper always called from an already-active
 * context (either checkAndLog()'s own wrap, or escalate()/pause()/
 * resume()'s own wrap at their call site) — per this project's
 * established convention, the wrap is applied at EACH call site
 * (checkAndLog(), escalate(), pause(), resume()), not inside logEvent()
 * itself, so a call from checkAndLog() -> logEvent() never nests two
 * active runWithFirmContext() wraps (a nested wrap's finally would
 * clear the outer caller's still-active context prematurely).
 */
class DocumentChaseService
{
    public function __construct(
        private NotificationEligibilityService $eligibility,
        private TimelineEventRecorder $timeline,
    ) {
    }

    public function checkAndLog(Firm $firm, DocumentRequestItem $item, ?DocumentChaseRule $rule = null): DunningEligibility
    {
        return (new TenantContextService())->runWithFirmContext($firm, function () use ($firm, $item, $rule) {
            $documentRequest = $item->documentRequest;
            $client = $documentRequest->client;

            if (! $item->isChaseEligibleStatus()) {
                // No dunning-style attempt occurred — nothing to log.
                return new DunningEligibility(
                    eligible: false,
                    reason: "item is not in a chase-eligible status (status={$item->status->value})",
                );
            }

            if ($rule && $rule->status->value !== 'active') {
                return new DunningEligibility(eligible: false, reason: 'chase rule is paused');
            }

            $channel = $rule?->channel ?? \App\Enums\ConsentChannel::Email;
            $recipient = $client->email ?? '';

            $result = $this->eligibility->check($firm, $client, $channel, $recipient);

            $this->logEvent($firm, $item, $rule, $result->eligible ? 'reminder_queued' : 'reminder_skipped', [
                'channel' => $channel->value,
                'reason' => $result->reason,
            ]);

            return $result;
        });
    }

    public function escalate(Firm $firm, DocumentRequestItem $item, DocumentChaseRule $rule, User $actor): void
    {
        (new TenantContextService())->runWithFirmContext($firm, function () use ($firm, $item, $rule, $actor) {
            $this->logEvent($firm, $item, $rule, 'escalated', ['escalate_to_user_id' => $rule->escalate_to_user_id], $actor);
        });
    }

    public function pause(Firm $firm, DocumentRequestItem $item, ?DocumentChaseRule $rule, User $actor, ?string $reason = null): void
    {
        (new TenantContextService())->runWithFirmContext($firm, function () use ($firm, $item, $rule, $actor, $reason) {
            $this->logEvent($firm, $item, $rule, 'paused', ['reason' => $reason], $actor);
        });
    }

    public function resume(Firm $firm, DocumentRequestItem $item, ?DocumentChaseRule $rule, User $actor): void
    {
        (new TenantContextService())->runWithFirmContext($firm, function () use ($firm, $item, $rule, $actor) {
            $this->logEvent($firm, $item, $rule, 'resumed', [], $actor);
        });
    }

    /**
     * No wrap of its own — see class docblock. Always called from
     * within an already-active runWithFirmContext() wrap established by
     * its caller.
     */
    private function logEvent(Firm $firm, DocumentRequestItem $item, ?DocumentChaseRule $rule, string $eventType, array $metadata, ?User $actor = null): void
    {
        $item->chaseEvents()->create([
            'firm_id' => $firm->id,
            'document_chase_rule_id' => $rule?->id,
            'event_type' => $eventType,
            'metadata_json' => $metadata,
            'actor_user_id' => $actor?->id,
        ]);

        $this->timeline->record($firm, "document_chase_{$eventType}", $item, $actor, $metadata);
    }
}
