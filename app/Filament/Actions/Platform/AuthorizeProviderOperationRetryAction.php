<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Enums\ProviderOperationReconciliationOutcome;
use App\Filament\Actions\Platform\Concerns\AuditsProviderOperationReconciliation;
use App\Integrations\Billing\ProviderOperationAttemptService;
use App\Integrations\Enums\ProviderOperationAttemptState;
use App\Integrations\Jobs\BootstrapWebhookSubscriptionsJob;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\ProviderOperationAttempt;
use App\Models\FirmUser;
use App\Models\PlatformAdmin;
use App\Services\PlatformAdminAuditEventRecorder;
use App\Services\PlatformStaffAccessPolicyService;
use App\Services\TenantContextService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * AuthorizeProviderOperationRetryAction — Checkpoint 8.2
 * (§A-reconciliation), resolution B: "the operator established that the
 * provider did NOT do the work, so a fresh attempt is safe."
 *
 * Routed exclusively through
 * `ProviderOperationAttemptService::resolveReconciliation(RetryAllowed, ...)`.
 * This is the ONLY way a row that ever reached `attempt_started` can
 * become sendable again — resolveReconciliation() itself enforces this
 * (its own docblock: "no automated path can produce it"), so this action
 * cannot bypass send_count/total_send_count safeguards: `RetryAllowed`
 * only permits `reclaim()` to run send_count back to 0 for a NEW
 * generation, and `total_send_count` stays monotonic regardless.
 *
 * Queues the retry through the ONE approved mechanism for the operation
 * types this page currently surfaces — the same job/service each
 * automated path already uses (`BootstrapWebhookSubscriptionsJob` for
 * webhook-bootstrap subscribes; every other operation type's own next
 * natural trigger — a future sync run, a future renewal cycle — will
 * pick up the now-`retry_allowed` row itself, since claim() reclaims a
 * `retry_allowed` row exactly like a `provider_rejected` one).
 */
class AuthorizeProviderOperationRetryAction extends Action
{
    use AuditsProviderOperationReconciliation;

    public static function getDefaultName(): ?string
    {
        return 'authorizeProviderOperationRetry';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Authorize Retry');
        $this->icon(Heroicon::OutlinedArrowPath);
        $this->color('warning');

        $this->schema([
            Textarea::make('reason')
                ->label('Why is a fresh attempt safe?')
                ->required()
                ->rows(2),
        ]);

        // Authorization is enforced entirely inside the action closure
        // below (never via ->visible()) — matching this codebase's own
        // established convention.
        $this->requiresConfirmation();
        $this->modalHeading('Authorize a fresh attempt');
        $this->modalDescription('Only do this when you have established the provider did NOT complete the original request.');

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
            $operationType = $attempt->operation_type;
            $firmIntegrationId = $attempt->firm_integration_id;
            $firmId = $attempt->firm_id;

            try {
                $attempts->resolveReconciliation(
                    $attempt,
                    ProviderOperationAttemptState::RetryAllowed,
                    $reason,
                    $admin->id,
                );
            } catch (Throwable $e) {
                $this->auditReconciliation($auditRecorder, $admin, $firmId, $attempt->logical_operation_key, $previousState, ProviderOperationReconciliationOutcome::AuthorizeRetryDenied, $reason, false, false, $e->getMessage());

                Notification::make()->title('Could not authorize a retry')->body('Another operator or process may have already resolved it.')->danger()->send();

                return;
            }

            $this->auditReconciliation($auditRecorder, $admin, $firmId, $attempt->logical_operation_key, $previousState, ProviderOperationReconciliationOutcome::AuthorizeRetry, $reason, true, false);

            // Webhook-bootstrap subscribes have no OTHER natural trigger —
            // unlike a sync/renewal cycle, nothing re-dispatches on its
            // own schedule. Every other operation type's own next
            // natural trigger reclaims a retry_allowed row unassisted.
            //
            // The job requires the raw users.id of an active FirmUser
            // (never a PlatformAdmin id — a platform admin has no
            // membership row in the firm's own tenant tables), so it is
            // re-dispatched as the connection's ORIGINAL connecting
            // user, never this operator. connected_by_firm_user_id is a
            // firm_users.id FK, not a raw user id, so it must be
            // resolved to that row's own user_id column. If the
            // connecting FirmUser is no longer resolvable, the row
            // stays `retry_allowed` for a manual retry rather than
            // guessing an actor.
            if ($operationType === 'webhook_bootstrap_subscribe' && $firmIntegrationId !== null) {
                $rawUserId = (new TenantContextService)->runWithFirmContext($firmId, function () use ($firmIntegrationId) {
                    $connectedByFirmUserId = FirmIntegration::query()->where('id', $firmIntegrationId)->value('connected_by_firm_user_id');

                    if ($connectedByFirmUserId === null) {
                        return null;
                    }

                    return FirmUser::query()->where('id', $connectedByFirmUserId)->value('user_id');
                });

                if ($rawUserId !== null) {
                    BootstrapWebhookSubscriptionsJob::dispatch($firmIntegrationId, $firmId, (int) $rawUserId);
                }
            }

            Notification::make()->title('Retry authorized')->success()->send();
        });
    }
}
