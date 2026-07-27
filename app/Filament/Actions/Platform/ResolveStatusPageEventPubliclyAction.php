<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Enums\StatusPageEventStatus;
use App\Models\PlatformAdmin;
use App\Models\StatusPageEvent;
use App\Services\PlatformStaffAccessPolicyService;
use App\Services\StatusPageService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * ResolveStatusPageEventPubliclyAction — Phase 4 (FirmsVault Platform
 * Admin Control Center, "Operations"). Row action on
 * PlatformStatusPageEventsPage. Routes exclusively through
 * StatusPageService::resolvePublicly(). Hidden once already
 * Unpublished/Archived.
 */
class ResolveStatusPageEventPubliclyAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'resolveStatusPageEventPublicly';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Resolve Publicly');
        $this->icon(Heroicon::OutlinedCheckCircle);
        $this->color('success');

        $this->schema([
            Textarea::make('public_message')->label('Public resolution message')->required()->rows(3),
        ]);

        $this->requiresConfirmation();
        $this->modalHeading('Resolve this status update publicly');

        $this->visible(fn (StatusPageEvent $record): bool => in_array($record->status, [StatusPageEventStatus::Draft, StatusPageEventStatus::Published], true));

        $this->action(function (StatusPageEvent $record, array $data, PlatformStaffAccessPolicyService $accessPolicy, StatusPageService $statusPageService): void {
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

            $statusPageService->resolvePublicly(
                $record->correlation_id,
                (string) $data['public_message'],
                $actor,
            );

            Notification::make()->title('Status update resolved publicly')->success()->send();
        });
    }
}
