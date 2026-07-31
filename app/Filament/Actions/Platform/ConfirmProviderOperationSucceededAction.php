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
 * ConfirmProviderOperationSucceededAction — Checkpoint 8.2
 * (§A-reconciliation), resolution A: "the operator established that the
 * provider DID the work; this side is settled."
 *
 * Routed exclusively through
 * `ProviderOperationAttemptService::resolveReconciliation(LocalProcessingComplete, ...)`
 * — the same compare-and-set-guarded state machine every automated path
 * uses, never a direct column write. TOCTOU discipline: only the
 * record's `id`/`firm_id` are trusted for the lookup; the CAS on
 * `attempt_state = reconciliation_required` inside `resolveReconciliation()`
 * itself is what actually prevents two operators (or a stale browser tab)
 * from both winning.
 *
 * Never resends to the provider — this action's entire purpose is to
 * record a HUMAN FACT about what already happened, not to trigger new
 * work.
 */
class ConfirmProviderOperationSucceededAction extends Action
{
    use AuditsProviderOperationReconciliation;

    public static function getDefaultName(): ?string
    {
        return 'confirmProviderOperationSucceeded';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Confirm Provider Succeeded');
        $this->icon(Heroicon::OutlinedCheckCircle);
        $this->color('success');

        $this->schema([
            Textarea::make('reason')
                ->label('How was this confirmed?')
                ->required()
                ->rows(2),
        ]);

        // Authorization is enforced entirely inside the action closure
        // below (never via ->visible()) — matching this codebase's own
        // established convention (RetrySyncFailureAction and every other
        // Platform Admin mutating action gate on record state via
        // ->visible(), never on the CURRENT admin's own role there; role
        // authorization is always re-checked fresh inside the closure).
        $this->requiresConfirmation();
        $this->modalHeading('Confirm the provider completed this operation');
        $this->modalDescription('This closes the operation as settled and does NOT contact the provider again.');

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
                $this->auditReconciliation($auditRecorder, $admin, $attempt->firm_id, $attempt->logical_operation_key, $previousState, ProviderOperationReconciliationOutcome::ConfirmSucceededDenied, $reason, false, false, $e->getMessage());

                Notification::make()->title('Could not resolve this operation')->body('Another operator or process may have already resolved it.')->danger()->send();

                return;
            }

            $this->auditReconciliation($auditRecorder, $admin, $attempt->firm_id, $attempt->logical_operation_key, $previousState, ProviderOperationReconciliationOutcome::ConfirmSucceeded, $reason, false, true);

            Notification::make()->title('Operation confirmed succeeded')->success()->send();
        });
    }
}
