<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Services\PlatformFirmIntegrationBoundedAccessService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * RequeueSyncItemAsSupportAction — Checkpoint 11 (frozen-design-post-
 * security-review.md §3, §7, §12). Row action on the combined
 * failed-items table on
 * App\Filament\Pages\PlatformFirmIntegrationDetailPage, mirroring
 * App\Filament\Firm\Resources\FirmIntegrationResource\Actions\
 * RequeueSyncItemAction's shape (Checkpoint 10) but routed exclusively
 * through PlatformFirmIntegrationBoundedAccessService::
 * requeueSyncItem() — never calls
 * App\Integrations\Services\SyncItemService directly.
 */
class RequeueSyncItemAsSupportAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'requeueSyncItemAsSupport';
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
        $this->modalHeading('Requeue Sync Item');

        $this->visible(fn (array $record): bool => ($record['type'] ?? null) === 'sync_item');

        $this->action(function (array $record, array $data, $livewire, PlatformFirmIntegrationBoundedAccessService $boundedAccess): void {
            $admin = Auth::guard('platform_admin')->user();

            if (! $admin instanceof PlatformAdmin) {
                Notification::make()->title('You are not signed in as a platform admin.')->danger()->send();

                return;
            }

            $firm = Firm::findByUuid((string) $livewire->firmUuid);

            $reasonCode = (string) ($data['reason_code'] ?? 'other_with_note');
            $note = trim((string) ($data['note'] ?? ''));
            $reasonCode = $note === '' ? $reasonCode : "{$reasonCode}: {$note}";

            $itemId = (int) $record['model_id'];

            try {
                $result = $boundedAccess->requeueSyncItem($admin, $firm, $itemId, $reasonCode);
            } catch (RuntimeException $e) {
                Notification::make()->title('Not permitted')->body($e->getMessage())->danger()->send();

                return;
            }

            if ($result === null) {
                $reason = $boundedAccess->diagnoseSyncItemRequeueIneligibility($admin, $firm, $itemId);

                Notification::make()
                    ->title('Could not requeue this item')
                    ->body($reason?->description() ?? 'Please try again.')
                    ->danger()
                    ->send();

                return;
            }

            Notification::make()
                ->title('Item requeued')
                ->body("This item has been requeued (requeue #{$result->requeue_count}).")
                ->success()
                ->send();
        });
    }
}
