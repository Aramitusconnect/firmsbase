<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\FirmIntegrationResource\Actions;

use App\Integrations\Services\IntegrationAccessPolicyService;
use App\Integrations\Services\IntegrationOutboxEventService;
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
 * RequeueOutboxEventAction — Checkpoint 10 (frozen-design-post-security-
 * review.md §5, §11; agent-10h-architecture-security-review.md §4).
 * Row action attached to a dead-lettered `IntegrationOutboxEvent` row
 * surfaced by FailedItemsRelationManager (an array-record row, keyed
 * `type = 'outbox_event'`, `model_id` carrying the real
 * `integration_outbox_events.id`).
 *
 * Entitlement/role wiring (frozen design §4 item 3): checked HERE, in
 * this caller-layer action handler, before invoking
 * IntegrationOutboxEventService::requeue() — never inside that service,
 * which is a shared, actor-authority-blind primitive.
 *
 * TOCTOU discipline (frozen design §10): the array record's own cached
 * fields (last_error, requeue_count, etc.) are NEVER used to decide
 * eligibility — only `model_id` is trusted, and only as a lookup key.
 * requeue() itself re-checks every guard fresh, atomically, inside its
 * own single guarded UPDATE.
 *
 * Rejection-reason UX (frozen design §5): when requeue() returns null,
 * calls the new, read-only, non-authoritative
 * diagnoseRequeueIneligibility() to surface a SPECIFIC reason — never
 * used to gate or retry the requeue itself.
 */
class RequeueOutboxEventAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'requeueOutboxEvent';
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
        $this->modalHeading('Requeue Outbox Event');

        $this->visible(fn (array $record): bool => ($record['type'] ?? null) === 'outbox_event');

        $this->action(function (array $record, array $data, IntegrationOutboxEventService $outboxEvents): void {
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

            // PRODUCTION BUG FIX: requeue()/diagnoseRequeueIneligibility()
            // run guarded raw SQL directly against the FORCE-RLS-protected
            // `integration_outbox_events` table and establish no tenant
            // context of their own (unlike ProviderConnectionService's
            // methods) — this closure runs via Filament's shared
            // `livewire/update` AJAX endpoint, which never runs this app's
            // `EstablishFirmTenantContext` middleware (see
            // ViewFirmIntegration's docblock for the full root cause), so
            // without this wrap the explicit `firm_id = ?` predicate below
            // would still see zero rows under FORCE RLS.
            app(TenantContextService::class)->runWithFirmContext((int) $firmUser->firm_id, function () use ($record, $reasonCode, $firmUser, $outboxEvents): void {
                $result = $outboxEvents->requeue(
                    (int) $record['model_id'],
                    (int) $firmUser->firm_id,
                    $reasonCode,
                    (int) $firmUser->id,
                );

                if ($result === null) {
                    $reason = $outboxEvents->diagnoseRequeueIneligibility((int) $record['model_id'], (int) $firmUser->firm_id);

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
        });
    }
}
