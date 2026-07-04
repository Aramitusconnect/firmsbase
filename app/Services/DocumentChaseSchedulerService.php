<?php

namespace App\Services;

use App\Enums\DocumentChaseRuleStatus;
use App\Models\DocumentChaseRule;
use App\Models\DocumentRequestItem;
use App\Models\Firm;

/**
 * DocumentChaseSchedulerService — pure schedule/policy math: given a
 * firm's DocumentChaseRule(s) and how long a DocumentRequestItem has
 * been outstanding, decides WHEN the next reminder is due and whether
 * escalation has been reached. Does not check consent/preferences and
 * does not log anything — that is DocumentChaseService's job
 * (mirrors the PaymentApplicationService/PaymentPlanInstallmentService
 * split from Phase 3).
 */
class DocumentChaseSchedulerService
{
    /**
     * Picks the most specific applicable rule for this item: a rule
     * whose applies_to matches (currently a plain string scope key,
     * e.g. a practice area key), falling back to a firm-wide rule
     * (applies_to null) if no specific match exists.
     */
    public function applicableRule(Firm $firm, DocumentRequestItem $item, ?string $scopeKey = null): ?DocumentChaseRule
    {
        $rules = DocumentChaseRule::query()
            ->where('firm_id', $firm->id)
            ->where('status', DocumentChaseRuleStatus::Active->value)
            ->get();

        if ($scopeKey) {
            $specific = $rules->firstWhere('applies_to', $scopeKey);

            if ($specific) {
                return $specific;
            }
        }

        return $rules->firstWhere('applies_to', null);
    }

    /**
     * How many reminders have already been sent for this item, based
     * on document_chase_events rows of type 'reminder_queued'.
     */
    public function remindersSentCount(DocumentRequestItem $item): int
    {
        return $item->chaseEvents()->where('event_type', 'reminder_queued')->count();
    }

    public function isReminderDue(DocumentChaseRule $rule, DocumentRequestItem $item, int $daysSinceRequested): bool
    {
        if ($this->remindersSentCount($item) >= $rule->max_reminders) {
            return false;
        }

        $offsets = $rule->reminder_offsets_days ?? [];

        return in_array($daysSinceRequested, $offsets, true);
    }

    public function isEscalationDue(DocumentChaseRule $rule, int $daysSinceRequested): bool
    {
        return $rule->escalate_after_days !== null && $daysSinceRequested >= $rule->escalate_after_days;
    }
}
