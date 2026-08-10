<?php

namespace App\Jobs;

use App\Enums\AutomationActionExecutionStatus;
use App\Exceptions\AutomationActionPermanentException;
use App\Exceptions\AutomationActionTransientException;
use App\Models\AutomationActionExecution;
use App\Models\Firm;
use App\Services\Automation\AutomationActionExecutionClaimService;
use App\Services\Automation\AutomationActionHandlerRegistry;
use App\Services\Automation\AutomationExecutionCompletionService;
use App\Support\TenantAwareJobContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * AutomationActionDispatchJob — Event-Driven Automation Engine, item
 * 6/9/10. The per-firm, per-tick unit of work draining
 * automation_action_executions — the layer that actually invokes an
 * AutomationActionHandler. Mirrors AutomationEventDispatchJob's own
 * shape; kept as a SEPARATE job/table/claim-loop deliberately (see
 * DomainEventClaimService's own docblock) so one action's failure can
 * never block rule-matching, or a different action, for the same event.
 *
 * Note this pass's own inherited limitation, matching (not exceeding)
 * OutboxDispatchJob's already-accepted design: claim(), the handler's
 * own side effect (e.g. TaskService::create()), and complete()/fail()
 * are three separate statements, not one transaction — a process crash
 * strictly between the handler succeeding and complete() running would
 * leave the row 'running' until the 15-minute stale-lock window
 * recovers it, and a re-claim would invoke the handler again. This is
 * the SAME accepted tradeoff the Integration Outbox already makes in
 * this codebase, not a new gap.
 */
final class AutomationActionDispatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TenantAwareJobContext;

    public function __construct(
        public readonly int $firmId,
        public readonly int $batchSize = 25,
    ) {}

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('automation-actions:'.$this->firmId))->releaseAfter(60)->expireAfter(900),
        ];
    }

    public function handle(
        AutomationActionExecutionClaimService $claims,
        AutomationActionHandlerRegistry $handlers,
        AutomationExecutionCompletionService $completion,
    ): void {
        $this->runInFirmContext($this->firmId, function () use ($claims, $handlers, $completion) {
            $firm = Firm::query()->find($this->firmId);

            if ($firm === null) {
                return;
            }

            $claimed = $claims->claim($this->firmId, $this->batchSize);

            foreach ($claimed as $actionExecution) {
                $this->dispatchOne($firm, $actionExecution, $claims, $handlers, $completion);
            }
        });
    }

    private function dispatchOne(
        Firm $firm,
        AutomationActionExecution $actionExecution,
        AutomationActionExecutionClaimService $claims,
        AutomationActionHandlerRegistry $handlers,
        AutomationExecutionCompletionService $completion,
    ): void {
        $event = $actionExecution->execution->domainEvent;
        $updated = null;

        try {
            $handler = $handlers->resolve($actionExecution->action_type);
            $outcome = $handler->handle($firm, $event, $actionExecution->action_config_json);

            if ($outcome->skipped) {
                $updated = $claims->skip($actionExecution->id, $outcome->message ?? 'Skipped.');
            } else {
                $updated = $claims->complete($actionExecution->id, $outcome->resultReferenceType, $outcome->resultReferenceId);
            }
        } catch (AutomationActionTransientException $e) {
            $updated = $claims->fail($actionExecution->id, $e->getMessage());
        } catch (AutomationActionPermanentException $e) {
            $updated = $claims->fail($actionExecution->id, $e->getMessage(), terminal: true);
        } catch (Throwable $e) {
            Log::error('AutomationActionDispatchJob: handler threw an unrecognized exception type; treated as permanent failure.', [
                'automation_action_execution_id' => $actionExecution->id,
                'action_type' => $actionExecution->action_type->value,
                'exception_class' => get_class($e),
            ]);

            $updated = $claims->fail($actionExecution->id, 'internal_dispatch_error', terminal: true);
        }

        // Only a terminal outcome (Succeeded/Failed) can possibly close
        // out the parent AutomationExecution — a RetryScheduled action
        // means the execution is still legitimately in progress.
        if ($updated !== null && in_array($updated->status, [AutomationActionExecutionStatus::Succeeded, AutomationActionExecutionStatus::Failed], true)) {
            $completion->refresh($updated->execution);
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('AutomationActionDispatchJob: batch-level failure, no row-level cleanup attempted (relying on stale-lock recovery).', [
            'firm_id' => $this->firmId,
            'exception' => $exception?->getMessage(),
        ]);
    }
}
