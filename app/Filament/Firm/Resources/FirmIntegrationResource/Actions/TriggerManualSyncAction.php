<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\FirmIntegrationResource\Actions;

use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\ResourceType;
use App\Integrations\Enums\SyncDirection;
use App\Integrations\Enums\SyncTriggerSource;
use App\Integrations\Exceptions\SyncRunAlreadyInProgressException;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Services\IntegrationAccessPolicyService;
use App\Integrations\Services\SyncRunService;
use App\Jobs\PullSyncJob;
use App\Services\IntegrationEntitlementPolicyService;
use App\Services\TenantContextService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * TriggerManualSyncAction — Checkpoint 10 (frozen-design-post-security-
 * review.md §11; agent-10h-architecture-security-review.md §12,
 * "Manual sync"). Scoped to INBOUND/PULL only (push targets one local
 * record, a poor fit for a generic dispatch button) — never dispatches
 * a push job.
 *
 * Dispatch design (10E §3, adopted by 10H without modification): the
 * Livewire handler calls SyncRunService::startRun() SYNCHRONOUSLY
 * (SyncTriggerSource::Manual) so the UI has an immediate run id to show
 * and SyncRunAlreadyInProgressException becomes a catchable,
 * user-facing "already in progress" notification rather than a silent
 * job no-op, THEN dispatches PullSyncJob with the pre-created run's id
 * (App\Jobs\PullSyncJob's new, additive $preCreatedRunId parameter) so
 * the job does not double-create the run.
 *
 * Entitlement/role wiring (frozen design §4 item 2): checked HERE, in
 * this caller-layer action handler — NEVER inside
 * SyncRunService::startRun() itself, which is a shared,
 * trigger-source-agnostic primitive invoked by every SyncTriggerSource
 * (scheduler, webhook, cursor-repair, retry-poller, connect, manual).
 *
 * TOCTOU discipline (frozen design §10): re-fetches the owning
 * connection fresh by primary key INSIDE this closure — never reuses
 * the RelationManager's mount()-hydrated owner record — and re-runs
 * assertEnabled()/assertCanSync() unconditionally every call.
 *
 * PRODUCTION BUG FIX: the closure runs via Filament's shared
 * `livewire/update` AJAX endpoint, which never runs this app's
 * `EstablishFirmTenantContext` middleware (see ViewFirmIntegration's
 * identical docblock note for the full root cause). The fresh re-fetch
 * above and the `SyncRunService::startRun()` call below (which — per
 * its own docblock — always expects to run inside an already-active
 * `TenantContextService::runWithFirmContext()`) are now both wrapped in
 * exactly that, using the acting `FirmUser`'s own
 * `Auth::user()->activeFirmUser()->firm_id` (session-resolvable without
 * any ambient firm context — see `User::activeFirmUser()`).
 */
class TriggerManualSyncAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'triggerManualSync';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Request Manual Sync');
        $this->icon(Heroicon::OutlinedArrowPath);
        $this->color('primary');

        $this->schema([
            Select::make('resource_type')
                ->label('Resource type')
                ->options(collect(ResourceType::cases())->mapWithKeys(
                    static fn (ResourceType $type) => [$type->value => str($type->value)->replace('_', ' ')->headline()]
                )->all())
                ->required()
                ->native(false),
        ]);

        $this->modalHeading('Request Manual Sync');
        $this->modalSubmitActionLabel('Start Sync');

        $this->visible(function (RelationManager $livewire): bool {
            $connection = $livewire->getOwnerRecord();

            if (! $connection instanceof FirmIntegration || $connection->status !== ConnectionStatus::Active) {
                return false;
            }

            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || (int) $firmUser->firm_id !== (int) $connection->firm_id) {
                return false;
            }

            return app(IntegrationEntitlementPolicyService::class)->isEnabled($firmUser->firm)
                && app(IntegrationAccessPolicyService::class)->canSync($firmUser->role);
        });

        $this->action(function (array $data, RelationManager $livewire, SyncRunService $runs): void {
            $ownerRecord = $livewire->getOwnerRecord();

            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null) {
                Notification::make()->title('You do not have access to this connection.')->danger()->send();

                return;
            }

            app(TenantContextService::class)->runWithFirmContext((int) $firmUser->firm_id, function () use ($ownerRecord, $firmUser, $data, $runs): void {
                $connection = FirmIntegration::query()
                    ->where('id', $ownerRecord->getKey())
                    ->firstOrFail();

                if ((int) $firmUser->firm_id !== (int) $connection->firm_id) {
                    Notification::make()->title('You do not have access to this connection.')->danger()->send();

                    return;
                }

                try {
                    app(IntegrationEntitlementPolicyService::class)->assertEnabled($firmUser->firm);
                    app(IntegrationAccessPolicyService::class)->assertCanSync($firmUser);
                } catch (RuntimeException $e) {
                    Notification::make()->title('Not permitted')->body($e->getMessage())->danger()->send();

                    return;
                }

                if ($connection->status !== ConnectionStatus::Active) {
                    Notification::make()->title('This connection is not Active — reconnect before syncing.')->danger()->send();

                    return;
                }

                $resourceType = (string) $data['resource_type'];

                try {
                    $run = $runs->startRun(
                        $connection,
                        $resourceType,
                        SyncDirection::Inbound,
                        SyncTriggerSource::Manual,
                        actorFirmUserId: (int) $firmUser->id,
                    );
                } catch (SyncRunAlreadyInProgressException $e) {
                    Notification::make()
                        ->title('A sync is already in progress')
                        ->body("See run #{$e->existingRun->id}.")
                        ->warning()
                        ->send();

                    return;
                }

                PullSyncJob::dispatch(
                    $connection->id,
                    $connection->firm_id,
                    $resourceType,
                    preCreatedRunId: $run->id,
                );

                Notification::make()
                    ->title('Sync queued')
                    ->body("Run #{$run->id} has been queued.")
                    ->success()
                    ->send();
            });
        });
    }
}
