<?php

namespace App\Console\Commands;

use App\Enums\FirmActivationStatus;
use App\Jobs\AutomationEventDispatchJob;
use App\Models\Firm;
use Illuminate\Console\Command;

/**
 * automation:events:dispatch — Event-Driven Automation Engine, item 9.
 * Layer 1 of the two-layer dispatch loop for domain_events, mirroring
 * DispatchOutboxEventsCommand exactly. A plain, non-tenant,
 * non-ShouldQueue command — `firms` carries no RLS, safe to enumerate
 * without any tenant context.
 */
final class DispatchAutomationEventsCommand extends Command
{
    protected $signature = 'automation:events:dispatch {--batch-size=25}';

    protected $description = 'Dispatches one AutomationEventDispatchJob per activated firm to drain due domain_events rows.';

    public function handle(): int
    {
        $batchSize = (int) $this->option('batch-size');

        Firm::query()
            ->where('activation_status', FirmActivationStatus::Activated)
            ->pluck('id')
            ->each(fn (int $firmId) => AutomationEventDispatchJob::dispatch($firmId, $batchSize));

        return self::SUCCESS;
    }
}
