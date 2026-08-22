<?php

namespace App\Services;

use App\Enums\KeyDestructionRequestStatus;
use App\Models\KeyDestructionRequest;

/**
 * KeyDestructionExecutionService — the ONLY caller of
 * EncryptionKeyService::destroy(). Re-checks approval status as a
 * defense-in-depth guard immediately before the irreversible action
 * (never trusts a stale in-memory status). Fully audited via
 * TimelineEventRecorder. Only ever affects the firm named on the
 * request.
 */
class KeyDestructionExecutionService
{
    public function __construct(
        private readonly EncryptionKeyService $encryptionKeyService,
        private readonly TimelineEventRecorder $timelineEventRecorder,
    ) {}

    public function execute(KeyDestructionRequest $request): KeyDestructionRequest
    {
        // New, leading, standalone wrap — key_destruction_requests is
        // now FORCE RLS (this batch); $request->fresh() re-reads the
        // row from the database and must run under its own firm's
        // context. Sequential with, not nested inside, the existing
        // Wave-7-fixed tail wrap below (keyed on $fresh->firm): this
        // wrap's own finally-block closes before that one opens, so
        // neither one's context leaks into or is clobbered by the
        // other.
        $fresh = (new TenantContextService)->runWithFirmContext($request->firm_id, fn () => $request->fresh());

        if ($fresh->status !== KeyDestructionRequestStatus::Approved) {
            throw new \RuntimeException('Key destruction can only execute for an Approved request.');
        }

        $destroyedCount = $this->encryptionKeyService->destroy($fresh->firm, $fresh->id);

        return (new TenantContextService)->runWithFirmContext($fresh->firm, function () use ($fresh, $destroyedCount) {
            $fresh->update([
                'status' => KeyDestructionRequestStatus::Executed,
                'executed_at' => now(),
            ]);

            $this->timelineEventRecorder->record(
                $fresh->firm,
                'key_destruction.executed',
                $fresh,
                null,
                ['key_destruction_request_id' => $fresh->id, 'keys_destroyed' => $destroyedCount],
            );

            return $fresh->fresh();
        });
    }
}
