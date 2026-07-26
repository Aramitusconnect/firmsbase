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
 * RetrySyncFailureAction — SyncFailureResource's row action. UNLIKE the
 * Checkpoint 11 RequeueSyncItemAsSupportAction (which resolves its firm
 * from a single page-level `$livewire->firmUuid` property, since it
 * lives on the per-firm PlatformFirmIntegrationDetailPage), this action
 * resolves its firm from the CROSS-FIRM array record's own `firm_uuid`
 * key — SyncFailureResource's table spans every firm at once.
 *
 * Routed exclusively through
 * PlatformFirmIntegrationBoundedAccessService::requeueSyncItem() — the
 * same already-wired, already-audited backend method
 * RequeueSyncItemAsSupportAction uses, never a new write path. TOCTOU
 * discipline is identical: only `id` (the array record's lookup key) is
 * trusted — every other cached field is display-only, and
 * requeueSyncItem() itself re-checks every guard fresh, atomically,
 * inside its own single guarded UPDATE.
 *
 * Every-module requirement ("authorized (role-ceiling + canMutate())"):
 * requeueSyncItem() itself only asserts the broad
 * assertCanAccessFirm()/canAccessIntegrationOversight() gate (it never
 * consults canMutate() — that method is currently consulted only by
 * disconnectConnection(), per that method's own docblock). This action
 * closes that gap at the UI layer, exactly the same "narrower gate on
 * top of the broad one" shape disconnectConnection() itself uses,
 * without touching the shared bounded-access method at all: canMutate()
 * is checked here, explicitly, before ever calling into the bounded
 * service.
 */
class RetrySyncFailureAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'retrySyncFailure';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Retry');
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
        $this->modalHeading('Retry Sync Item');

        // Only meaningful for items that are actually terminal-failed —
        // failed_retryable items are still mid-cycle on the automated
        // retry poller, so retrying them here would just collide with
        // that poller rather than doing anything additive.
        $this->visible(fn (array $record): bool => ($record['status'] ?? null) === 'failed_permanent');

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

            $itemId = (int) $record['id'];

            try {
                $result = $boundedAccess->requeueSyncItem($admin, $firm, $itemId, $reasonCode);
            } catch (RuntimeException $e) {
                Notification::make()->title('Not permitted')->body($e->getMessage())->danger()->send();

                return;
            }

            if ($result === null) {
                $reason = $boundedAccess->diagnoseSyncItemRequeueIneligibility($admin, $firm, $itemId);

                Notification::make()
                    ->title('Could not retry this item')
                    ->body($reason?->description() ?? 'Please try again.')
                    ->danger()
                    ->send();

                return;
            }

            Notification::make()
                ->title('Item retried')
                ->body("This item has been requeued (retry #{$result->requeue_count}).")
                ->success()
                ->send();
        });
    }
}
