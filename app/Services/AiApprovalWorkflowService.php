<?php

namespace App\Services;

use App\Enums\AiApprovalCategory;
use App\Enums\AiApprovalEventType;
use App\Enums\AiApprovalRequestStatus;
use App\Models\AiApprovalEvent;
use App\Models\AiApprovalRequest;
use App\Models\AiUsageEvent;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Matter;
use App\Models\User;
use App\Enums\FirmUserRole;
use Illuminate\Support\Facades\DB;

/**
 * AiApprovalWorkflowService — the only writer of ai_approval_requests/
 * ai_approval_events. Every high-risk category (Master Plan §22;
 * project rules 15/19/20) MUST go through submit()/approve()/reject()
 * before the underlying draft may be used or sent to a client — no
 * caller may bypass this by writing to either table directly (both
 * models restrict who may reasonably call their statics, but the real
 * enforcement is "only this service is wired to firm business logic").
 *
 * submit() stores an ENCRYPTED content snapshot (approved decision #4)
 * using the exact same tenant-encryption chain as webhook secrets/
 * firm-owned AI provider keys (EncryptionKeyService +
 * EmailBodyEncryptionService — no second encryption system). This is
 * allowed even when firm_ai_settings.full_content_logging_enabled is
 * false, because approval needs a stable review artifact — the RAW
 * provider prompt/response is never stored anywhere else unless that
 * policy flag is true (enforced by AiUsageRecorderService, which never
 * persists raw prompt/response text on ai_usage_events regardless of
 * this table).
 *
 * approve()/reject() are restricted to FirmOwner/Attorney
 * (FirmUserRole), mirroring FormAndDocumentAccessPolicyService's
 * APPROVAL_ROLES exactly — same roles, same reasoning: no AI actor
 * type exists, so this can never be satisfied by an AI approval.
 */
class AiApprovalWorkflowService
{
    private const APPROVAL_ROLES = [
        FirmUserRole::FirmOwner,
        FirmUserRole::Attorney,
    ];

    public function __construct(private readonly EmailBodyEncryptionService $encryption)
    {
    }

    public function submit(
        Firm $firm,
        User $requestedBy,
        AiUsageEvent $usageEvent,
        AiApprovalCategory $category,
        string $draftContent,
        ?Matter $matter = null,
    ): AiApprovalRequest {
        $result = $this->encryption->encrypt($firm, $draftContent);

        if (! $result->succeeded) {
            throw new \RuntimeException("Cannot submit AI approval request: {$result->reason}");
        }

        return DB::transaction(function () use ($firm, $requestedBy, $usageEvent, $category, $matter, $result) {
            $request = AiApprovalRequest::create([
                'firm_id' => $firm->id,
                'matter_id' => $matter?->id,
                'requested_by' => $requestedBy->id,
                'ai_usage_event_id' => $usageEvent->id,
                'category' => $category,
                'status' => AiApprovalRequestStatus::Pending,
                'draft_label' => 'ai_generated_draft',
                'encrypted_snapshot_ciphertext' => $result->ciphertext,
                'encryption_key_id' => $result->encryptionKeyId,
            ]);

            AiApprovalEvent::create([
                'ai_approval_request_id' => $request->id,
                'firm_id' => $firm->id,
                'event_type' => AiApprovalEventType::Submitted,
                'actor_id' => $requestedBy->id,
            ]);

            return $request->fresh();
        });
    }

    public function approve(AiApprovalRequest $request, FirmUser $actor, ?string $reason = null): AiApprovalRequest
    {
        $this->assertActorMayResolve($actor);
        $this->assertPending($request);

        return DB::transaction(function () use ($request, $actor, $reason) {
            $request->update([
                'status' => AiApprovalRequestStatus::Approved,
                'resolved_at' => now(),
            ]);

            AiApprovalEvent::create([
                'ai_approval_request_id' => $request->id,
                'firm_id' => $request->firm_id,
                'event_type' => AiApprovalEventType::Approved,
                'actor_id' => $actor->user_id,
                'reason' => $reason,
            ]);

            return $request->fresh();
        });
    }

    public function reject(AiApprovalRequest $request, FirmUser $actor, ?string $reason = null): AiApprovalRequest
    {
        $this->assertActorMayResolve($actor);
        $this->assertPending($request);

        return DB::transaction(function () use ($request, $actor, $reason) {
            $request->update([
                'status' => AiApprovalRequestStatus::Rejected,
                'resolved_at' => now(),
            ]);

            AiApprovalEvent::create([
                'ai_approval_request_id' => $request->id,
                'firm_id' => $request->firm_id,
                'event_type' => AiApprovalEventType::Rejected,
                'actor_id' => $actor->user_id,
                'reason' => $reason,
            ]);

            return $request->fresh();
        });
    }

    /**
     * The ONLY path back to the plaintext draft snapshot. Callers must
     * keep the return value in memory only. No route/controller/UI
     * calls this in Phase 15 (none exist).
     */
    public function decryptSnapshot(Firm $firm, AiApprovalRequest $request): string
    {
        if ($request->firm_id !== $firm->id) {
            throw new \RuntimeException('This approval request does not belong to this firm.');
        }

        return $this->encryption->decrypt($firm, $request->encrypted_snapshot_ciphertext, $request->encryption_key_id);
    }

    private function assertActorMayResolve(FirmUser $actor): void
    {
        if (! in_array($actor->role, self::APPROVAL_ROLES, true)) {
            throw new \RuntimeException('Only FirmOwner or Attorney may approve or reject an AI approval request.');
        }
    }

    private function assertPending(AiApprovalRequest $request): void
    {
        if (! $request->isPending()) {
            throw new \RuntimeException('This AI approval request has already been resolved.');
        }
    }
}
