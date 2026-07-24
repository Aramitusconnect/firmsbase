<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\FirmIntegrationResource\Actions;

use App\Integrations\Services\IntegrationAccessPolicyService;
use App\Integrations\Services\SyncItemService;
use App\Services\IntegrationEntitlementPolicyService;
use App\Services\TenantContextService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * RequeueSyncItemAction — Checkpoint 10 (frozen-design-post-security-
 * review.md §5, §11; agent-10h-architecture-security-review.md §4). Row
 * action attached to a permanently-failed `IntegrationSyncItem` row
 * surfaced by FailedItemsRelationManager (an array-record row, keyed
 * `type = 'sync_item'`, `model_id` carrying the real
 * `integration_sync_items.id`).
 *
 * See RequeueOutboxEventAction's docblock for the full, identical
 * entitlement/role-wiring, TOCTOU, and rejection-reason-UX discipline —
 * this class mirrors that one exactly, against
 * SyncItemService::requeueFromFailedPermanent()/
 * diagnoseRequeueIneligibility() instead.
 */
class RequeueSyncItemAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'requeueSyncItem';
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

        $this->action(function (array $record, array $data, SyncItemService $syncItems): void {
            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null) {
                Notification::make()->title('No active firm membership.')->danger()->send();

                return;
            }

            try {
                app(IntegrationEntitlementPolicyService::class)->assertEnabled($firmUser->firm);
                app(IntegrationAccessPolicyService::class)->assertCanConfigure($firmUser);
            } catch (RuntimeException $e) {
                Notification::make()->title('Not permitted')->body($e->getMessage())->danger()->send();

                return;
            }

            $reasonCode = (string) ($data['reason_code'] ?? 'other_with_note');
            $note = trim((string) ($data['note'] ?? ''));
            $reasonCode = $note === '' ? $reasonCode : "{$reasonCode}: {$note}";

            // PRODUCTION BUG FIX: requeueFromFailedPermanent()/
            // diagnoseRequeueIneligibility() run guarded raw SQL directly
            // against the FORCE-RLS-protected `integration_sync_items`
            // table and establish no tenant context of their own — this
            // closure runs via Filament's shared `livewire/update` AJAX
            // endpoint, which never runs this app's
            // `EstablishFirmTenantContext` middleware (see
            // ViewFirmIntegration's docblock for the full root cause), so
            // without this wrap the explicit `firm_id = ?` predicate below
            // would still see zero rows under FORCE RLS.
            app(TenantContextService::class)->runWithFirmContext((int) $firmUser->firm_id, function () use ($record, $reasonCode, $firmUser, $syncItems): void {
                $result = $syncItems->requeueFromFailedPermanent(
                    (int) $record['model_id'],
                    (int) $firmUser->firm_id,
                    $reasonCode,
                    (int) $firmUser->id,
                );

                if ($result === null) {
                    $reason = $syncItems->diagnoseRequeueIneligibility((int) $record['model_id'], (int) $firmUser->firm_id);

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
        });
    }
}
