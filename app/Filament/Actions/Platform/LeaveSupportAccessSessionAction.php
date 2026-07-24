<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Enums\SupportAccessSessionStatus;
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
 * LeaveSupportAccessSessionAction — Checkpoint 11 (frozen-design-post-
 * security-review.md §7, §8, §12). Header action on
 * App\Filament\Pages\PlatformFirmIntegrationsPage. The first real caller
 * of App\Services\SupportAccessSessionService::end(), exclusively
 * through PlatformFirmIntegrationBoundedAccessService::
 * leaveSupportAccessSession() (gap closure #3: a fresh, locked re-read
 * immediately before end(), no-op if already terminal — performed
 * inside that chokepoint, never here).
 *
 * Self-service only — the options list is scoped to sessions belonging
 * to THIS acting admin (platform_admin_id = $admin->id); revoking a
 * DIFFERENT admin's session is RevokeSupportAccessSessionAction's
 * separate, broader governance action.
 */
class LeaveSupportAccessSessionAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'leaveSupportAccessSession';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Leave Support Access Session');
        $this->icon(Heroicon::OutlinedLockClosed);
        $this->color('gray');

        $this->schema([
            Select::make('session_uuid')
                ->label('Active session')
                ->options(fn ($livewire): array => self::activeSessionOptions($livewire, ownOnly: true))
                ->required()
                ->native(false),
        ]);

        $this->requiresConfirmation();
        $this->modalHeading('Leave Support Access Session');

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
                $boundedAccess->leaveSupportAccessSession($admin, $session);
            } catch (RuntimeException $e) {
                Notification::make()->title('Could not leave this session')->body($e->getMessage())->danger()->send();

                return;
            }

            Notification::make()->title('Support access session ended')->success()->send();
        });
    }

    /**
     * @return array<string, string>
     */
    public static function activeSessionOptions($livewire, bool $ownOnly): array
    {
        $admin = Auth::guard('platform_admin')->user();

        if (! $admin instanceof PlatformAdmin) {
            return [];
        }

        $firm = Firm::findByUuid((string) $livewire->firmUuid);

        return app(TenantContextService::class)->runWithFirmContext($firm, function () use ($admin, $firm, $ownOnly): array {
            $query = SupportAccessSession::query()
                ->where('firm_id', $firm->id)
                ->where('status', SupportAccessSessionStatus::Active->value)
                ->where('expires_at', '>', now());

            if ($ownOnly) {
                $query->where('platform_admin_id', $admin->id);
            }

            return $query
                ->orderByDesc('started_at')
                ->limit(20)
                ->get()
                ->mapWithKeys(fn (SupportAccessSession $session): array => [
                    $session->uuid => sprintf('%s — expires %s', $session->uuid, $session->expires_at?->toDayDateTimeString()),
                ])
                ->all();
        });
    }
}
