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
    ) {
    }

    public function execute(KeyDestructionRequest $request): KeyDestructionRequest
    {
        $fresh = $request->fresh();

        if ($fresh->status !== KeyDestructionRequestStatus::Approved) {
            throw new \RuntimeException('Key destruction can only execute for an Approved request.');
        }

        $destroyedCount = $this->encryptionKeyService->destroy($fresh->firm, $fresh->id);

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
    }
}
