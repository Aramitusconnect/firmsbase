<?php

namespace App\Jobs;

use App\Models\Firm;
use App\Services\Automation\AutomationRuleMatchingService;
use App\Services\Automation\DomainEventClaimService;
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
 * AutomationEventDispatchJob — Event-Driven Automation Engine, item
 * 9/12. The per-firm, per-tick unit of work draining domain_events,
 * mirroring App\Jobs\OutboxDispatchJob's own proven two-layer
 * dispatch shape exactly (DispatchAutomationEventsCommand is Layer 1).
 *
 * handle() never lets a single event's matching failure propagate
 * outward — DomainEventClaimService::fail() records it (with retry
 * backoff) and the loop continues to the next claimed event, same
 * discipline as OutboxDispatchJob::dispatchOne(). Only a genuine
 * batch-level failure (claim() itself throwing) reaches failed()
 * below.
 */
final class AutomationEventDispatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TenantAwareJobContext;

    public function __construct(
        public readonly int $firmId,
        public readonly int $batchSize = 25,
    ) {}

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('automation-events:'.$this->firmId))->releaseAfter(60)->expireAfter(900),
        ];
    }

    public function handle(DomainEventClaimService $claims, AutomationRuleMatchingService $matcher): void
    {
        $this->runInFirmContext($this->firmId, function () use ($claims, $matcher) {
            $firm = Firm::query()->find($this->firmId);

            if ($firm === null) {
                return;
            }

            $claimed = $claims->claim($this->firmId, $this->batchSize);

            foreach ($claimed as $event) {
                $lockToken = $event->lock_token;

                try {
                    $result = $matcher->match($firm, $event);

                    if ($result['loop_prevented']) {
                        // Item 18 (observability) — never silent. No
                        // payload content logged, only non-sensitive
                        // identifiers/counters, consistent with this
                        // job's own established Log::error() shape below.
                        Log::warning('AutomationEventDispatchJob: loop prevention triggered — event exceeded max causation depth.', [
                            'domain_event_id' => $event->id,
                            'event_type' => $event->event_type->value,
                            'causation_depth' => $event->causation_depth,
                            'max_causation_depth' => AutomationRuleMatchingService::MAX_CAUSATION_DEPTH,
                        ]);
                    }

                    $claims->complete($event->id, $lockToken);
                } catch (Throwable $e) {
                    Log::error('AutomationEventDispatchJob: rule matching failed for a domain event.', [
                        'domain_event_id' => $event->id,
                        'event_type' => $event->event_type->value,
                        'exception_class' => get_class($e),
                    ]);

                    $claims->fail($event->id, $lockToken, 'internal_matching_error');
                }
            }
        });
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('AutomationEventDispatchJob: batch-level failure, no row-level cleanup attempted (relying on stale-lock recovery).', [
            'firm_id' => $this->firmId,
            'exception' => $exception?->getMessage(),
        ]);
    }
}
