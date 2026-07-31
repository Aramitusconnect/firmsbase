<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform\Concerns;

use App\Enums\ProviderOperationReconciliationOutcome;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Services\PlatformAdminAuditEventRecorder;
use App\Services\PlatformRoleService;

/**
 * AuditsProviderOperationReconciliation — Checkpoint 8.2
 * (§A-reconciliation). Shared by every reconciliation-resolution action
 * so the required audit fields (operator, operator's own actions,
 * operation identifier, firm reference, previous/new state, safe reason,
 * retry/resume flags) are recorded identically regardless of which
 * action fired — never tokens, credentials, raw provider responses, or
 * decrypted cursors, matching `PlatformAdminAuditEventRecorder`'s own
 * "sanitized metadata only" contract.
 *
 * Audits BOTH a successful resolution and a DENIED one (e.g. another
 * operator won the compare-and-set first) — a denied mutation must never
 * be silently invisible, and must never be recorded as if it succeeded.
 */
trait AuditsProviderOperationReconciliation
{
    private function auditReconciliation(
        PlatformAdminAuditEventRecorder $auditRecorder,
        PlatformAdmin $admin,
        int $firmId,
        string $logicalOperationKey,
        string $previousState,
        ProviderOperationReconciliationOutcome $outcome,
        string $reason,
        bool $retryAuthorized,
        bool $localApplyResumed,
        ?string $denialReason = null,
    ): void {
        $firm = Firm::query()->find($firmId);

        $operatorRoles = array_map(
            static fn ($role): string => $role->value,
            app(PlatformRoleService::class)->activeRolesFor($admin),
        );

        $metadata = [
            'operator_user_id' => $admin->id,
            'operator_roles' => $operatorRoles,
            'operation_identifier' => $logicalOperationKey,
            'firm_id' => $firmId,
            'previous_state' => $previousState,
            'new_state' => $outcome->resultingState(),
            'action_category' => $outcome->value,
            'safe_reason' => $reason,
            'retry_authorized' => $retryAuthorized,
            'local_apply_resumed' => $localApplyResumed,
            'denial_reason' => $denialReason,
        ];

        if ($firm !== null) {
            $auditRecorder->record($firm, $admin, 'provider_operation_reconciliation_resolved', $outcome->value, $metadata);

            return;
        }

        $auditRecorder->recordPlatformEvent($admin, 'provider_operation_reconciliation_resolved', $outcome->value, $metadata);
    }
}
