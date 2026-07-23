<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\FirmActivationStatus;
use App\Jobs\SyncRetryPollJob;
use App\Models\Firm;
use Illuminate\Console\Command;

/**
 * integrations:sync:retry-poll — Layer 1 of the sync retry-poller's
 * two-layer dispatch loop (Checkpoint 8,
 * agent-8h-architecture-security-review.md §1 item 1 / §2 item 1),
 * mirroring App\Console\Commands\DispatchOutboxEventsCommand's exact
 * shape. A plain, non-tenant, non-ShouldQueue Artisan command — `firms`
 * is not FORCE-RLS'd, so it is safe to enumerate without any tenant
 * context. Scheduled every 1-5 minutes (`everyThreeMinutes()
 * ->withoutOverlapping()`) in bootstrap/app.php.
 */
final class SyncRetryPollCommand extends Command
{
    protected $signature = 'integrations:sync:retry-poll {--batch-size=25}';

    protected $description = 'Dispatches one SyncRetryPollJob per activated firm to claim and resolve due failed_retryable sync items.';

    public function handle(): int
    {
        $batchSize = (int) $this->option('batch-size');

        Firm::query()
            ->where('activation_status', FirmActivationStatus::Activated)
            ->pluck('id')
            ->each(fn (int $firmId) => SyncRetryPollJob::dispatch($firmId, $batchSize));

        return self::SUCCESS;
    }
}
