<?php

namespace App\Services;

use App\Models\DocumentChaseRule;
use App\Models\DocumentRequestItem;
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
 */
class DocumentChaseService
{
    public function __construct(
        private NotificationEligibilityService $eligibility,
        private TimelineEventRecorder $timeline,
    ) {
    }

    public function checkAndLog(DocumentRequestItem $item, ?DocumentChaseRule $rule = null): DunningEligibility
    {
        $documentRequest = $item->documentRequest;
        $firm = $documentRequest->firm;
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

        $this->logEvent($item, $rule, $result->eligible ? 'reminder_queued' : 'reminder_skipped', [
            'channel' => $channel->value,
            'reason' => $result->reason,
        ]);

        return $result;
    }

    public function escalate(DocumentRequestItem $item, DocumentChaseRule $rule, User $actor): void
    {
        $this->logEvent($item, $rule, 'escalated', ['escalate_to_user_id' => $rule->escalate_to_user_id], $actor);
    }

    public function pause(DocumentRequestItem $item, ?DocumentChaseRule $rule, User $actor, ?string $reason = null): void
    {
        $this->logEvent($item, $rule, 'paused', ['reason' => $reason], $actor);
    }

    public function resume(DocumentRequestItem $item, ?DocumentChaseRule $rule, User $actor): void
    {
        $this->logEvent($item, $rule, 'resumed', [], $actor);
    }

    private function logEvent(DocumentRequestItem $item, ?DocumentChaseRule $rule, string $eventType, array $metadata, ?User $actor = null): void
    {
        $firm = $item->documentRequest->firm;

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
