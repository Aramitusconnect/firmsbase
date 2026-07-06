<?php

namespace App\Services;

use App\Enums\RetentionPolicyStatus;
use App\Enums\RetentionRecordType;
use App\Models\Firm;
use App\Models\RetentionPolicy;
use App\ValueObjects\RetentionClearanceResult;

/**
 * RetentionPolicyService — resolveEffectivePolicyFor() is the single
 * place "firm override wins over platform default" is decided.
 * isRetentionCleared() is the single place "no policy means not
 * cleared, never unrestricted" is enforced. Trust ledger permanence
 * (project rule) is enforced generically here via is_permanent, not as
 * a special case — the seeded platform-default trust_ledger policy row
 * simply has is_permanent=true.
 */
class RetentionPolicyService
{
    public function resolveEffectivePolicyFor(
        ?Firm $firm,
        RetentionRecordType $recordType,
        ?string $documentCategory = null,
    ): ?RetentionPolicy {
        if ($firm !== null) {
            $override = $this->activePolicyQuery($recordType, $documentCategory)
                ->where('firm_id', $firm->id)
                ->first();

            if ($override !== null) {
                return $override;
            }
        }

        return $this->activePolicyQuery($recordType, $documentCategory)
            ->whereNull('firm_id')
            ->first();
    }

    public function isRetentionCleared(
        ?RetentionPolicy $policy,
        \DateTimeInterface $recordCreatedAt,
        \DateTimeInterface $now = new \DateTimeImmutable(),
    ): RetentionClearanceResult {
        if ($policy === null) {
            return RetentionClearanceResult::notCleared('No retention policy is configured for this record type — clearance defaults to false, never unrestricted.');
        }

        if ($policy->is_permanent) {
            return RetentionClearanceResult::notCleared('Retention policy is permanent and has not been reviewed/approved otherwise.');
        }

        if ($policy->retention_period_days === null) {
            return RetentionClearanceResult::notCleared('Retention policy has no configured retention period.');
        }

        $clearsAt = (clone $recordCreatedAt)->modify("+{$policy->retention_period_days} days");

        if ($now < $clearsAt) {
            return RetentionClearanceResult::notCleared('Retention period has not yet elapsed.');
        }

        return RetentionClearanceResult::cleared();
    }

    /**
     * Approved decision #4: replacement is allowed only when the policy
     * BOTH allows client replacement AND requires audit history to be
     * preserved — never either alone.
     */
    public function allowsClientDocumentReplacement(?RetentionPolicy $policy): bool
    {
        if ($policy === null) {
            return false;
        }

        return $policy->allows_client_replacement && $policy->preserves_audit_history_required;
    }

    public function supersede(RetentionPolicy $policy, array $newAttributes, ?string $reason = null): RetentionPolicy
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($policy, $newAttributes, $reason) {
            $policy->update(['status' => RetentionPolicyStatus::Superseded, 'superseded_at' => now()]);

            return RetentionPolicy::create(array_merge([
                'firm_id' => $policy->firm_id,
                'record_type' => $policy->record_type,
                'document_category' => $policy->document_category,
                'practice_area' => $policy->practice_area,
                'jurisdiction' => $policy->jurisdiction,
                'status' => RetentionPolicyStatus::Active,
                'effective_at' => now(),
                'supersedes_policy_id' => $policy->id,
                'reason' => $reason ?? 'Supersedes prior policy.',
            ], $newAttributes));
        });
    }

    private function activePolicyQuery(RetentionRecordType $recordType, ?string $documentCategory)
    {
        $query = RetentionPolicy::query()
            ->where('record_type', $recordType->value)
            ->where('status', RetentionPolicyStatus::Active->value);

        if ($recordType === RetentionRecordType::DocumentCategory) {
            $query->where('document_category', $documentCategory);
        }

        return $query;
    }
}
