<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Services\PlatformFirmIntegrationBoundedAccessService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * NudgeIntegrationQueueAsSupportAction — Checkpoint 11 (frozen-design-
 * post-security-review.md §7, §12). Header action on
 * App\Filament\Pages\PlatformFirmIntegrationsPage. On-demand per-firm
 * queue nudge — exclusively through
 * PlatformFirmIntegrationBoundedAccessService::nudgeQueue(), which
 * dispatches App\Jobs\OutboxDispatchJob / App\Jobs\SyncRetryPollJob —
 * the EXACT dispatch the existing scheduler already performs (frozen
 * design §7), never a new dispatch shape.
 */
class NudgeIntegrationQueueAsSupportAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'nudgeIntegrationQueue';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Nudge Queue');
        $this->icon(Heroicon::OutlinedBolt);
        $this->color('gray');
        $this->requiresConfirmation();
        $this->modalHeading('Nudge Integration Queue');
        $this->modalDescription('Dispatches an immediate outbox-dispatch and sync-retry-poll tick for this firm, exactly as the scheduler already does on its normal cadence.');

        $this->action(function ($livewire, PlatformFirmIntegrationBoundedAccessService $boundedAccess): void {
            $admin = Auth::guard('platform_admin')->user();

            if (! $admin instanceof PlatformAdmin) {
                Notification::make()->title('You are not signed in as a platform admin.')->danger()->send();

                return;
            }

            $firm = Firm::findByUuid((string) $livewire->firmUuid);

            try {
                $boundedAccess->nudgeQueue($admin, $firm);
            } catch (RuntimeException $e) {
                Notification::make()->title('Could not nudge the queue')->body($e->getMessage())->danger()->send();

                return;
            }

            Notification::make()
                ->title('Queue nudged')
                ->body('An outbox-dispatch and sync-retry-poll tick has been dispatched for this firm.')
                ->success()
                ->send();
        });
    }
}
