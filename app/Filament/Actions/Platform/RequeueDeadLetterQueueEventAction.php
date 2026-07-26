<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Services\PlatformFirmIntegrationBoundedAccessService;
use App\Services\PlatformStaffAccessPolicyService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * RequeueDeadLetterQueueEventAction — DeadLetterQueueResource's row
 * action. Mirrors RetrySyncFailureAction's exact shape (see that class's
 * own docblock for the full "why this differs from the Checkpoint 11
 * *AsSupportAction pair" reasoning) — the one difference is the
 * underlying bounded-access method (requeueOutboxEvent() instead of
 * requeueSyncItem()) and the ineligibility diagnostic pair
 * (diagnoseOutboxRequeueIneligibility()).
 *
 * Routed exclusively through
 * PlatformFirmIntegrationBoundedAccessService::requeueOutboxEvent() —
 * the same already-wired, already-audited backend method
 * RequeueOutboxEventAsSupportAction uses, never a new write path.
 * canMutate() is checked explicitly here (see RetrySyncFailureAction's
 * docblock for why this belongs at the UI layer rather than inside the
 * shared bounded-access method).
 */
class RequeueDeadLetterQueueEventAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'requeueDeadLetterQueueEvent';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Requeue');
        $this->icon(Heroicon::OutlinedArrowPath);
        $this->color('warning');

        $this->schema([
            Select::make('reason_code')
                ->label('Reason')
                ->options([
                    'manual_retry_after_provider_fix' => 'Retrying after a provider-side fix',
                    'manual_retry_transient' => 'Retrying a transient failure',
                    'other_with_note' => 'Other (see note)',
                ])
                ->required()
                ->native(false),
            Textarea::make('note')
                ->label('Note (optional)')
                ->rows(2),
        ]);

        $this->requiresConfirmation();
        $this->modalHeading('Requeue Dead-Lettered Event');

        $this->action(function (array $record, array $data, PlatformFirmIntegrationBoundedAccessService $boundedAccess, PlatformStaffAccessPolicyService $accessPolicy): void {
            $admin = Auth::guard('platform_admin')->user();

            if (! $admin instanceof PlatformAdmin) {
                Notification::make()->title('You are not signed in as a platform admin.')->danger()->send();

                return;
            }

            $mutateDecision = $accessPolicy->canMutate($admin);

            if (! $mutateDecision->allowed) {
                Notification::make()->title('Not permitted')->body($mutateDecision->reason)->danger()->send();

                return;
            }

            $firm = Firm::findByUuid((string) $record['firm_uuid']);

            $reasonCode = (string) ($data['reason_code'] ?? 'other_with_note');
            $note = trim((string) ($data['note'] ?? ''));
            $reasonCode = $note === '' ? $reasonCode : "{$reasonCode}: {$note}";

            $eventId = (int) $record['id'];

            try {
                $result = $boundedAccess->requeueOutboxEvent($admin, $firm, $eventId, $reasonCode);
            } catch (RuntimeException $e) {
                Notification::make()->title('Not permitted')->body($e->getMessage())->danger()->send();

                return;
            }

            if ($result === null) {
                $reason = $boundedAccess->diagnoseOutboxRequeueIneligibility($admin, $firm, $eventId);

                Notification::make()
                    ->title('Could not requeue this event')
                    ->body($reason?->description() ?? 'Please try again.')
                    ->danger()
                    ->send();

                return;
            }

            Notification::make()
                ->title('Event requeued')
                ->body("This event has been requeued (requeue #{$result->requeue_count}).")
                ->success()
                ->send();
        });
    }
}
