<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Enums\ProviderOperationReconciliationOutcome;
use App\Filament\Actions\Platform\Concerns\AuditsProviderOperationReconciliation;
use App\Integrations\Billing\ProviderOperationAttemptService;
use App\Integrations\Enums\ProviderOperationAttemptState;
use App\Integrations\Models\ProviderOperationAttempt;
use App\Models\PlatformAdmin;
use App\Services\PlatformAdminAuditEventRecorder;
use App\Services\PlatformStaffAccessPolicyService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * ResolveProviderOperationWithoutRetryAction — Checkpoint 8.2
 * (§A-reconciliation), resolution C: "close the operation safely,
 * without retrying." `ProviderOperationAttemptService::resolveReconciliation()`
 * only recognizes two legal exits from `reconciliation_required`
 * (`LocalProcessingComplete` or `RetryAllowed`) — closing without a retry
 * is therefore recorded as `LocalProcessingComplete`, exactly like a
 * confirmed success, but with a DISTINCT audit action category
 * (`resolve_without_retry`, never `confirm_provider_succeeded`) so the
 * audit trail never claims the operator verified the provider actually
 * did the work.
 *
 * Never marks any integration "healthy" as a side effect — this action
 * touches only the durable gate row.
 */
class ResolveProviderOperationWithoutRetryAction extends Action
{
    use AuditsProviderOperationReconciliation;

    public static function getDefaultName(): ?string
    {
        return 'resolveProviderOperationWithoutRetry';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Resolve Without Retry');
        $this->icon(Heroicon::OutlinedXCircle);
        $this->color('gray');

        $this->schema([
            Textarea::make('reason')
                ->label('Why is no further action needed?')
                ->required()
                ->rows(2),
        ]);

        // Authorization is enforced entirely inside the action closure
        // below (never via ->visible()) — matching this codebase's own
        // established convention.
        $this->requiresConfirmation();
        $this->modalHeading('Close this operation without retrying');
        $this->modalDescription('Use this when the operation should be considered closed and no further automated or manual retry is appropriate.');

        $this->action(function (ProviderOperationAttempt $record, array $data, ProviderOperationAttemptService $attempts, PlatformAdminAuditEventRecorder $auditRecorder, PlatformStaffAccessPolicyService $accessPolicy): void {
            $admin = Auth::guard('platform_admin')->user();

            if (! $admin instanceof PlatformAdmin) {
                Notification::make()->title('You are not signed in as a platform admin.')->danger()->send();

                return;
            }

            $manageDecision = $accessPolicy->canManageIntegrationConnections($admin);

            if (! $manageDecision->allowed) {
                Notification::make()->title('Not permitted')->body($manageDecision->reason)->danger()->send();

                return;
            }

            $mutateDecision = $accessPolicy->canMutate($admin);

            if (! $mutateDecision->allowed) {
                Notification::make()->title('Not permitted')->body($mutateDecision->reason)->danger()->send();

                return;
            }

            $attempt = $attempts->findByIdForFirm($record->id, $record->firm_id);

            if ($attempt === null) {
                Notification::make()->title('This operation no longer exists.')->danger()->send();

                return;
            }

            $reason = trim((string) ($data['reason'] ?? ''));
            $previousState = $attempt->attempt_state->value;

            try {
                $attempts->resolveReconciliation(
                    $attempt,
                    ProviderOperationAttemptState::LocalProcessingComplete,
                    $reason,
                    $admin->id,
                );
            } catch (Throwable $e) {
                $this->auditReconciliation($auditRecorder, $admin, $attempt->firm_id, $attempt->logical_operation_key, $previousState, ProviderOperationReconciliationOutcome::ResolveWithoutRetryDenied, $reason, false, false, $e->getMessage());

                Notification::make()->title('Could not resolve this operation')->body('Another operator or process may have already resolved it.')->danger()->send();

                return;
            }

            $this->auditReconciliation($auditRecorder, $admin, $attempt->firm_id, $attempt->logical_operation_key, $previousState, ProviderOperationReconciliationOutcome::ResolveWithoutRetry, $reason, false, false);

            Notification::make()->title('Operation closed without retry')->success()->send();
        });
    }
}
