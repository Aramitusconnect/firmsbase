<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Integrations\Data\SanitizedHealthDiagnostic;
use App\Integrations\Models\IntegrationOutboxEvent;
use App\Integrations\Outbox\Exceptions\OutboxHandlerPermanentException;
use App\Integrations\Outbox\Exceptions\OutboxHandlerReleaseException;
use App\Integrations\Outbox\Exceptions\OutboxHandlerTransientException;
use App\Integrations\Outbox\Exceptions\UnknownOutboxEventTypeException;
use App\Integrations\Outbox\OutboxEventHandlerRegistry;
use App\Integrations\Services\HealthStateService;
use App\Integrations\Services\IntegrationOutboxEventService;
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
 * OutboxDispatchJob — the per-firm, per-tick unit of queued work that
 * drains `integration_outbox_events` (Checkpoint 8,
 * agent-8b-outbox-dispatch-design.md; agent-8h-architecture-security-review.md
 * §1 item 1/2). Layer 2 of the two-layer dispatch loop:
 * App\Console\Commands\DispatchOutboxEventsCommand (Layer 1, a plain,
 * non-tenant scheduled command) enumerates active firms and dispatches
 * one instance of this job per firm id.
 *
 * Constructor carries IDs/scalars ONLY, mirroring App\Jobs\WebhookDispatchJob
 * exactly — no claimed row ids, no provider credentials, no decrypted
 * payload. The claim() call happens fresh inside handle(), never
 * carried in from dispatch time.
 *
 * handle() NEVER throws outward for a per-row failure — every claimed
 * row resolves to exactly one of complete()/fail()/release() before
 * the loop moves on, mirroring WebhookDispatchJob's identical
 * "handle() NEVER throws outward" discipline, for an outbox-specific
 * reason beyond house style: an uncaught per-row exception would leave
 * the row stuck `processing` until the 15-minute stale-lock window
 * elapses, silently reusing the outbox's own crash-recovery path as a
 * substitute retry mechanism with none of fail()'s
 * backoff/attempt-accounting/dead-letter logic applied in the
 * meantime. A genuine BATCH-level failure (e.g. claim() itself throws
 * because the DB connection is down) is the one case allowed to
 * propagate — see the "handle() structure" note below.
 */
final class OutboxDispatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TenantAwareJobContext;

    public function __construct(
        public readonly int $firmId,
        public readonly int $batchSize = 25,
    ) {
    }

    /**
     * Per-firm, not per-connection or global (agent-8b "Job class
     * shape" §): an efficiency guard against redundant claim() attempts
     * when one tick's job is still running as the next tick fires — not
     * a correctness guard (claim()'s own SKIP LOCKED design already
     * makes two genuinely-concurrent claims against the same firm
     * structurally safe). expireAfter(900) deliberately matches
     * config('integrations.outbox.stale_lock_minutes')'s own 15-minute
     * default.
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping((string) $this->firmId))->releaseAfter(60)->expireAfter(900),
        ];
    }

    public function handle(
        OutboxEventHandlerRegistry $handlers,
        IntegrationOutboxEventService $outbox,
        HealthStateService $healthState,
    ): void {
        $this->runInFirmContext($this->firmId, function () use ($handlers, $outbox, $healthState) {
            $claimed = $outbox->claim($this->firmId, $this->batchSize);

            foreach ($claimed as $event) {
                $this->dispatchOne($event, $handlers, $outbox, $healthState);
            }
        });
    }

    private function dispatchOne(
        IntegrationOutboxEvent $event,
        OutboxEventHandlerRegistry $handlers,
        IntegrationOutboxEventService $outbox,
        HealthStateService $healthState,
    ): void {
        // Read directly off the claimed model, never re-fetched via a
        // plain ->find($event->id) afterward — see agent-8b §9's
        // explicit "never re-fetch, always use the value the claim
        // response itself returned" discipline.
        $lockToken = $event->lock_token;

        try {
            $handler = $handlers->get($event->event_type);
            $handler->handle($event->firm_id, $event->firm_integration_id, $event->domain_event_id, $event->payload_json);
            $outbox->complete($event->id, $lockToken);
        } catch (OutboxHandlerReleaseException) {
            $outbox->release($event->id, $lockToken);
        } catch (OutboxHandlerTransientException $e) {
            $outbox->fail($event->id, $lockToken, $e->sanitizedReason(), $e->category());
            $this->recordHealthSignal($event, $e->category(), $healthState);
        } catch (OutboxHandlerPermanentException $e) {
            $outbox->fail($event->id, $lockToken, $e->sanitizedReason(), $e->category() ?? 'configuration_error');
            $this->recordHealthSignal($event, $e->category(), $healthState);
        } catch (UnknownOutboxEventTypeException $e) {
            // Always permanent — no handler exists to ever succeed on
            // retry (agent-8b §2/§6).
            $outbox->fail($event->id, $lockToken, 'unknown_outbox_event_type', 'configuration_error');
        } catch (Throwable $e) {
            Log::error('OutboxDispatchJob: handler threw an unrecognized exception type; treated as permanent failure.', [
                'outbox_event_id' => $event->id,
                'event_type' => $event->event_type,
                'exception_class' => get_class($e),
            ]);

            $outbox->fail($event->id, $lockToken, 'internal_dispatch_error', 'configuration_error');
        }
    }

    /**
     * Frozen requirement (agent-8h-architecture-security-review.md §6):
     * wherever a category is resolved immediately before calling
     * fail(), that same call site must also call the matching
     * HealthStateService::record*() method (per 8E's nine-category
     * mapping) when $event->firm_integration_id is known for that row.
     */
    private function recordHealthSignal(IntegrationOutboxEvent $event, ?string $category, HealthStateService $healthState): void
    {
        if ($event->firm_integration_id === null || $category === null) {
            return;
        }

        $operation = SanitizedHealthDiagnostic::OPERATION_OUTBOX_DISPATCH;

        match (true) {
            $category === 'rate_limited' => $healthState->recordRateLimited(
                $event->firm_integration_id,
                $event->firm_id,
                now()->addMinutes(1),
                new SanitizedHealthDiagnostic(SanitizedHealthDiagnostic::CATEGORY_RATE_LIMITED, $operation),
            ),
            in_array($category, ['authentication_failed', 'invalid_grant'], true) => $healthState->recordCredentialError(
                $event->firm_integration_id,
                $event->firm_id,
                new SanitizedHealthDiagnostic(SanitizedHealthDiagnostic::CATEGORY_CREDENTIAL_ERROR, $operation),
            ),
            $category === 'authorization_failed' => $healthState->recordScopeError(
                $event->firm_integration_id,
                $event->firm_id,
                new SanitizedHealthDiagnostic(SanitizedHealthDiagnostic::CATEGORY_SCOPE_ERROR, $operation),
            ),
            default => $healthState->recordProviderError(
                $event->firm_integration_id,
                $event->firm_id,
                new SanitizedHealthDiagnostic(SanitizedHealthDiagnostic::CATEGORY_PROVIDER_ERROR, $operation),
            ),
        };
    }

    /**
     * Reached only for a batch-level failure that propagated out of
     * handle() itself (e.g. claim() throws because the DB connection is
     * down) — never for a per-row handler failure, which dispatchOne()
     * already resolves without throwing. Whatever row this job DID
     * manage to claim before the batch-level failure occurred is left
     * exactly where the outbox's own stale-lock reclaim already safely
     * handles it: `processing`, recoverable by the next scheduled
     * tick's reclaim once 15 minutes elapse. No speculative row-level
     * cleanup is attempted here — that would risk acting on a row a
     * DIFFERENT worker has since legitimately claimed in the meantime.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('OutboxDispatchJob: batch-level failure, no row-level cleanup attempted (relying on stale-lock recovery).', [
            'firm_id' => $this->firmId,
            'exception' => $exception?->getMessage(),
        ]);
    }
}
