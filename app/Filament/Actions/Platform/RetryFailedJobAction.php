<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Models\FailedJob;
use App\Models\PlatformAdmin;
use App\Services\PlatformAdminAuditEventRecorder;
use App\Services\PlatformStaffAccessPolicyService;
use App\Services\QueueHealthService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * RetryFailedJobAction — Phase 4 (FirmsVault Platform Admin Control
 * Center, "Operations"). Row action on PlatformQueuesAndJobsPage's
 * failed-jobs table. Routes exclusively through
 * QueueHealthService::retryFailedJob() — standard Laravel
 * `queue:retry` semantics (see that method's own docblock), never a
 * raw re-INSERT into `jobs`.
 *
 * TOCTOU-safe: the failed job is looked up fresh by uuid inside
 * retryFailedJob() itself (never trusted from the table row's
 * page-load-time state) — if it was already retried/deleted by
 * someone else between page load and this click, the service returns
 * false and this action reports that plainly rather than erroring.
 */
class RetryFailedJobAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'retryFailedJob';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Retry');
        $this->icon(Heroicon::OutlinedArrowPath);
        $this->color('warning');
        $this->requiresConfirmation();
        $this->modalHeading('Retry failed job');
        $this->modalDescription('Re-dispatches this job back onto its original queue, with its attempt counter reset — exactly as Laravel\'s own `queue:retry` command would.');

        $this->action(function (FailedJob $record, PlatformStaffAccessPolicyService $accessPolicy, QueueHealthService $queueHealth, PlatformAdminAuditEventRecorder $auditRecorder): void {
            $actor = Auth::guard('platform_admin')->user();

            if (! $actor instanceof PlatformAdmin) {
                Notification::make()->title('You are not signed in as a platform admin.')->danger()->send();

                return;
            }

            $manageDecision = $accessPolicy->canManageOperations($actor);

            if (! $manageDecision->allowed) {
                Notification::make()->title('Not permitted')->body($manageDecision->reason)->danger()->send();

                return;
            }

            $mutateDecision = $accessPolicy->canMutate($actor);

            if (! $mutateDecision->allowed) {
                Notification::make()->title('Not permitted')->body($mutateDecision->reason)->danger()->send();

                return;
            }

            $uuid = $record->uuid;
            $retried = $queueHealth->retryFailedJob($uuid);

            if (! $retried) {
                Notification::make()->title('Could not retry this job')->body('It may have already been retried or deleted.')->warning()->send();

                return;
            }

            $auditRecorder->recordPlatformEvent($actor, 'failed_job_retried', 'operations_queue', [
                'failed_job_uuid' => $uuid,
                'queue' => $record->queue,
            ]);

            Notification::make()->title('Job retried')->success()->send();
        });
    }
}
