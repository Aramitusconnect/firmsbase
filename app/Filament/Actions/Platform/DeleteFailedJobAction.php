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
 * DeleteFailedJobAction — Phase 4 (FirmsVault Platform Admin Control
 * Center, "Operations"). Row action on PlatformQueuesAndJobsPage's
 * failed-jobs table. Routes exclusively through
 * QueueHealthService::deleteFailedJob() — standard Laravel
 * `queue:forget` semantics; deletes the failed_jobs row only, never
 * re-dispatches the underlying job.
 *
 * TOCTOU-safe: looked up fresh by uuid inside deleteFailedJob() itself.
 */
class DeleteFailedJobAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'deleteFailedJob';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Delete');
        $this->icon(Heroicon::OutlinedTrash);
        $this->color('danger');
        $this->requiresConfirmation();
        $this->modalHeading('Delete failed job');
        $this->modalDescription('Permanently removes this row from the failed-jobs table — exactly as Laravel\'s own `queue:forget` command would. The job itself is not retried.');

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
            $queue = $record->queue;
            $deleted = $queueHealth->deleteFailedJob($uuid);

            if (! $deleted) {
                Notification::make()->title('Could not delete this job')->body('It may have already been retried or deleted.')->warning()->send();

                return;
            }

            $auditRecorder->recordPlatformEvent($actor, 'failed_job_deleted', 'operations_queue', [
                'failed_job_uuid' => $uuid,
                'queue' => $queue,
            ]);

            Notification::make()->title('Job deleted')->success()->send();
        });
    }
}
