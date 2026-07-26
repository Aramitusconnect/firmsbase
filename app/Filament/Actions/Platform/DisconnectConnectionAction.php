<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Integrations\Enums\ConnectionStatus;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Services\PlatformFirmIntegrationBoundedAccessService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * DisconnectConnectionAction — Phase 2 of the FirmsVault Platform Admin
 * Control Center mission ("Integration Operations Center"). The ONE
 * mutating action on the new cross-firm
 * App\Filament\Resources\ConnectionResource (row action on the list,
 * header action on the view page) — mirrors the exact TOCTOU-safe
 * pattern already established by every other App\Filament\Actions\
 * Platform\* class (e.g. RequeueOutboxEventAsSupportAction): fresh actor
 * resolution inside the closure, requiresConfirmation(), and routes
 * exclusively through
 * PlatformFirmIntegrationBoundedAccessService::disconnectConnection()
 * (the method built in the Phase 2 backend-foundations pass) — NEVER
 * App\Integrations\Services\ProviderConnectionService directly.
 *
 * Deliberately generic over its calling context: unlike
 * RequeueOutboxEventAsSupportAction (which reads `$livewire->firmUuid`,
 * since it only ever mounts on the per-firm
 * PlatformFirmIntegrationDetailPage), this action reads `firm_uuid` and
 * `id` directly off the `$record` array itself — the row/detail shape
 * both ConnectionResource's List and View pages already carry (see
 * App\Services\PlatformConnectionDirectoryService::toRow()) — so it
 * works identically whether mounted as a list row action or a view-page
 * header action, without depending on any `$livewire`-specific public
 * property.
 *
 * No pause/resume/reconnect actions exist anywhere in this class or
 * file — the investigation confirmed no "paused" state exists in
 * ConnectionStatus and reconnect requires a real browser OAuth
 * round-trip an Admin panel structurally cannot perform; both were
 * correctly scoped out and are not added back here.
 */
class DisconnectConnectionAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'disconnectConnection';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Disconnect');
        $this->icon(Heroicon::OutlinedNoSymbol);
        $this->color('danger');

        $this->schema([
            Textarea::make('reason')
                ->label('Reason')
                ->required()
                ->rows(2)
                ->helperText('Recorded on this firm\'s oversight audit trail.'),
        ]);

        $this->requiresConfirmation();
        $this->modalHeading('Disconnect Connection');
        $this->modalDescription(
            'This immediately disconnects the firm\'s live provider connection (attempting a best-effort remote '.
            'revoke, then tearing down local credential material). This cannot be undone from this panel — '.
            'reconnecting requires the firm\'s own users to complete a new OAuth authorization in the firm panel.'
        );
        $this->modalSubmitActionLabel('Disconnect');

        // Already-disconnected connections have nothing left to do here
        // — mirrors ProviderConnectionService::disconnect()'s own
        // idempotent short-circuit, surfaced at the UI layer too so the
        // action isn't offered on a row that would just no-op.
        $this->visible(fn (array $record): bool => ($record['status'] ?? null) !== ConnectionStatus::Disconnected->value);

        $this->action(function (array $record, array $data): void {
            $admin = Auth::guard('platform_admin')->user();

            if (! $admin instanceof PlatformAdmin) {
                Notification::make()->title('You are not signed in as a platform admin.')->danger()->send();

                return;
            }

            $firmUuid = (string) ($record['firm_uuid'] ?? '');
            $connectionId = (int) ($record['id'] ?? 0);
            $reason = trim((string) ($data['reason'] ?? ''));

            if ($firmUuid === '' || $connectionId <= 0) {
                Notification::make()->title('Could not resolve this connection.')->danger()->send();

                return;
            }

            $firm = Firm::findByUuid($firmUuid);

            try {
                $disconnected = app(PlatformFirmIntegrationBoundedAccessService::class)
                    ->disconnectConnection($admin, $firm, $connectionId, $reason);
            } catch (RuntimeException $e) {
                Notification::make()->title('Not permitted')->body($e->getMessage())->danger()->send();

                return;
            }

            Notification::make()
                ->title('Connection disconnected')
                ->body("Status: {$disconnected->status->value}.")
                ->success()
                ->send();
        });
    }
}
