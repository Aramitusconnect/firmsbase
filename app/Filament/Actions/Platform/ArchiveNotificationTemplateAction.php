<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Enums\NotificationTemplateStatus;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Services\NotificationTemplateService;
use App\Services\PlatformNotificationTemplateDirectoryService;
use App\Services\PlatformStaffAccessPolicyService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * ArchiveNotificationTemplateAction — NotificationTemplateResource's
 * row action. Calls NotificationTemplateService::archive($template,
 * $actor) — the actor/audit plumbing this phase added to that method.
 * TOCTOU-safe: resolves the real model fresh (via
 * PlatformNotificationTemplateDirectoryService::findModel(), scoped to
 * the row's own global/firm-scoped context) before re-checking its
 * status is not already Archived.
 */
class ArchiveNotificationTemplateAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'archiveNotificationTemplate';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Archive');
        $this->icon(Heroicon::OutlinedArchiveBox);
        $this->color('danger');

        $this->requiresConfirmation();
        $this->modalHeading('Archive Notification Template');
        $this->modalDescription('This marks the template Archived. It will no longer be resolved for dispatch (resolve() only ever returns Active rows). This cannot be undone from this console.');

        $this->visible(fn (array $record): bool => ($record['status'] ?? null) !== NotificationTemplateStatus::Archived->value);

        $this->action(function (array $record, PlatformNotificationTemplateDirectoryService $directory, NotificationTemplateService $templateService, PlatformStaffAccessPolicyService $accessPolicy): void {
            $admin = Auth::guard('platform_admin')->user();

            if (! $admin instanceof PlatformAdmin) {
                Notification::make()->title('You are not signed in as a platform admin.')->danger()->send();

                return;
            }

            if (! $accessPolicy->canManageNotificationTemplates($admin)->allowed) {
                Notification::make()->title('You are not authorized to manage notification templates.')->danger()->send();

                return;
            }

            $mutateDecision = $accessPolicy->canMutate($admin);

            if (! $mutateDecision->allowed) {
                Notification::make()->title('Not permitted')->body($mutateDecision->reason)->danger()->send();

                return;
            }

            $firm = $record['firm_id'] === null ? null : Firm::query()->find($record['firm_id']);

            $template = $directory->findModel($admin, $firm, (int) $record['id']);

            if ($template === null) {
                Notification::make()->title('That notification template could not be found.')->danger()->send();

                return;
            }

            if ($template->status === NotificationTemplateStatus::Archived) {
                Notification::make()->title('This template is already archived')->warning()->send();

                return;
            }

            $archived = $templateService->archive($template, $admin);

            Notification::make()
                ->title('Notification template archived')
                ->body("{$archived->key} ({$archived->channel->value}).")
                ->success()
                ->send();
        });
    }
}
