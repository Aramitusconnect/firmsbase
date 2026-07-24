<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Models\SupportAccessSession;
use App\Services\PlatformFirmIntegrationBoundedAccessService;
use App\Services\TenantContextService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * RevokeSupportAccessSessionAction — Checkpoint 11 (frozen-design-post-
 * security-review.md §7, §8, §12). Header action on
 * App\Filament\Pages\PlatformFirmIntegrationsPage. The first real caller
 * of App\Services\SupportAccessSessionService::revoke(), exclusively
 * through PlatformFirmIntegrationBoundedAccessService::
 * revokeSupportAccessSession() (gap closure #3: a fresh, locked re-read
 * immediately before revoke(), no-op if already terminal — performed
 * inside that chokepoint, never here).
 *
 * Deliberately broader than LeaveSupportAccessSessionAction: the
 * options list covers EVERY active session for this firm, not merely
 * this acting admin's own — a governance/emergency-stop action (e.g. an
 * ImplementationSpecialist revoking a SupportAgent's session), gated by
 * the same coarse role-level oversight check every other action in this
 * checkpoint uses; App\Services\SupportAccessSessionService::revoke()
 * itself does not restrict the actor to the session's own owner either.
 */
class RevokeSupportAccessSessionAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'revokeSupportAccessSession';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Revoke Support Access Session');
        $this->icon(Heroicon::OutlinedNoSymbol);
        $this->color('danger');

        $this->schema([
            Select::make('session_uuid')
                ->label('Active session')
                ->options(fn ($livewire): array => LeaveSupportAccessSessionAction::activeSessionOptions($livewire, ownOnly: false))
                ->required()
                ->native(false),
        ]);

        $this->requiresConfirmation();
        $this->modalHeading('Revoke Support Access Session');
        $this->modalDescription('This immediately ends the session for whichever platform admin is using it. This cannot be undone.');

        $this->action(function (array $data, $livewire, PlatformFirmIntegrationBoundedAccessService $boundedAccess): void {
            $admin = Auth::guard('platform_admin')->user();

            if (! $admin instanceof PlatformAdmin) {
                Notification::make()->title('You are not signed in as a platform admin.')->danger()->send();

                return;
            }

            $firm = Firm::findByUuid((string) $livewire->firmUuid);

            $session = app(TenantContextService::class)->runWithFirmContext(
                $firm,
                fn (): ?SupportAccessSession => SupportAccessSession::query()
                    ->where('firm_id', $firm->id)
                    ->where('uuid', (string) $data['session_uuid'])
                    ->first()
            );

            if ($session === null) {
                Notification::make()->title('That support access session could not be found.')->danger()->send();

                return;
            }

            try {
                $boundedAccess->revokeSupportAccessSession($admin, $session);
            } catch (RuntimeException $e) {
                Notification::make()->title('Could not revoke this session')->body($e->getMessage())->danger()->send();

                return;
            }

            Notification::make()->title('Support access session revoked')->success()->send();
        });
    }
}
