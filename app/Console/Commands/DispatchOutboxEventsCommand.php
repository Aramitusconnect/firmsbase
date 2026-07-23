<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\FirmActivationStatus;
use App\Jobs\OutboxDispatchJob;
use App\Models\Firm;
use Illuminate\Console\Command;

/**
 * integrations:outbox:dispatch — Layer 1 of the two-layer outbox
 * dispatch loop (Checkpoint 8, agent-8b-outbox-dispatch-design.md §1.4;
 * agent-8h-architecture-security-review.md §1 item 1). A plain,
 * non-tenant, non-ShouldQueue Artisan command — `firms` is not
 * FORCE-RLS'd, so it is safe to enumerate without any tenant context.
 * Scheduled `everyMinute()->withoutOverlapping()` in bootstrap/app.php.
 *
 * `firm_integration_id` is nullable on `integration_outbox_events`
 * (not every internal async event is tied to a specific provider
 * connection), so the firm set is EVERY activated firm, not merely
 * firms with an active firm_integrations row — narrowing would
 * silently starve any purely-internal outbox event type.
 */
final class DispatchOutboxEventsCommand extends Command
{
    protected $signature = 'integrations:outbox:dispatch {--batch-size=25}';

    protected $description = 'Dispatches one OutboxDispatchJob per activated firm to drain due integration_outbox_events rows.';

    public function handle(): int
    {
        $batchSize = (int) $this->option('batch-size');

        Firm::query()
            ->where('activation_status', FirmActivationStatus::Activated)
            ->pluck('id')
            ->each(fn (int $firmId) => OutboxDispatchJob::dispatch($firmId, $batchSize));

        return self::SUCCESS;
    }
}
