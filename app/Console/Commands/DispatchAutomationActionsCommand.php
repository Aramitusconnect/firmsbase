<?php

namespace App\Console\Commands;

use App\Enums\FirmActivationStatus;
use App\Jobs\AutomationActionDispatchJob;
use App\Models\Firm;
use Illuminate\Console\Command;

/**
 * automation:actions:dispatch — Event-Driven Automation Engine, item 9.
 * Layer 1 of the two-layer dispatch loop for automation_action_executions.
 */
final class DispatchAutomationActionsCommand extends Command
{
    protected $signature = 'automation:actions:dispatch {--batch-size=25}';

    protected $description = 'Dispatches one AutomationActionDispatchJob per activated firm to drain due automation_action_executions rows.';

    public function handle(): int
    {
        $batchSize = (int) $this->option('batch-size');

        Firm::query()
            ->where('activation_status', FirmActivationStatus::Activated)
            ->pluck('id')
            ->each(fn (int $firmId) => AutomationActionDispatchJob::dispatch($firmId, $batchSize));

        return self::SUCCESS;
    }
}
