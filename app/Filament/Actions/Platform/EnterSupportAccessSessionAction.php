<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Enums\SupportAccessRequestStatus;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Models\SupportAccessRequest;
use App\Services\PlatformFirmIntegrationBoundedAccessService;
use App\Services\TenantContextService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * EnterSupportAccessSessionAction — Checkpoint 11 (frozen-design-post-
 * security-review.md §7, §8, §12). Header action on
 * App\Filament\Pages\PlatformFirmIntegrationsPage. The first real caller
 * of App\Services\SupportAccessSessionService::start(), exclusively
 * through PlatformFirmIntegrationBoundedAccessService::
 * enterSupportAccessSession() (gap closures #2 and #4: requester-
 * identity check and a fresh canStartSession() re-check, both performed
 * inside that chokepoint, never here).
 *
 * The request options list is deliberately scoped to THIS acting admin's
 * OWN non-terminal requests for THIS firm (requested_by = $admin->id) —
 * a UX narrowing only; the chokepoint's own requester-identity check is
 * the real enforcement, re-verified fresh regardless of what this list
 * shows.
 */
class EnterSupportAccessSessionAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'enterSupportAccessSession';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Enter Support Access Session');
        $this->icon(Heroicon::OutlinedLockOpen);
        $this->color('gray');

        $this->schema([
            Select::make('request_uuid')
                ->label('Support access request')
                ->options(function ($livewire): array {
                    $admin = Auth::guard('platform_admin')->user();

                    if (! $admin instanceof PlatformAdmin) {
                        return [];
                    }

                    $firm = Firm::findByUuid((string) $livewire->firmUuid);

                    return app(TenantContextService::class)->runWithFirmContext($firm, function () use ($admin, $firm): array {
                        return SupportAccessRequest::query()
                            ->where('firm_id', $firm->id)
                            ->where('requested_by', $admin->id)
                            ->whereIn('status', [SupportAccessRequestStatus::Requested->value, SupportAccessRequestStatus::Approved->value])
                            ->orderByDesc('created_at')
                            ->limit(20)
                            ->get()
                            ->mapWithKeys(fn (SupportAccessRequest $request): array => [
                                $request->uuid => sprintf('%s — %s (%s)', $request->uuid, $request->access_type->value, $request->status->value),
                            ])
                            ->all();
                    });
                })
                ->required()
                ->native(false),
        ]);

        $this->requiresConfirmation();
        $this->modalHeading('Enter Support Access Session');

        $this->action(function (array $data, $livewire, PlatformFirmIntegrationBoundedAccessService $boundedAccess): void {
            $admin = Auth::guard('platform_admin')->user();

            if (! $admin instanceof PlatformAdmin) {
                Notification::make()->title('You are not signed in as a platform admin.')->danger()->send();

                return;
            }

            $firm = Firm::findByUuid((string) $livewire->firmUuid);

            $request = app(TenantContextService::class)->runWithFirmContext(
                $firm,
                fn (): ?SupportAccessRequest => SupportAccessRequest::query()
                    ->where('firm_id', $firm->id)
                    ->where('uuid', (string) $data['request_uuid'])
                    ->first()
            );

            if ($request === null) {
                Notification::make()->title('That support access request could not be found.')->danger()->send();

                return;
            }

            try {
                $session = $boundedAccess->enterSupportAccessSession($admin, $request);
            } catch (RuntimeException $e) {
                Notification::make()->title('Could not enter this session')->body($e->getMessage())->danger()->send();

                return;
            }

            Notification::make()
                ->title('Support access session started')
                ->body("Session {$session->uuid} is now active, expiring at {$session->expires_at?->toDayDateTimeString()}.")
                ->success()
                ->send();
        });
    }
}
