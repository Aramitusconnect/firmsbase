<?php

declare(strict_types=1);

namespace App\Integrations\Services;

use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;

/**
 * IntegrationRequeueAuditLogger — Checkpoint 9 (frozen design §7;
 * agent-9e-requeue-governance.md §5/§6, "audit-evidence design";
 * agent-9h-architecture-security-review.md §11.1, implementer
 * discretion note). Deliberately mirrors
 * App\Integrations\Services\RetentionSweepAuditLogger/
 * InboundWebhookAuditLogger's exact shape: a closed event-name
 * allowlist, a dedicated `Log::build()` platform-only log channel
 * (matching this checkpoint series' own established precedent rather
 * than a `config/logging.php` entry, which is outside this checkpoint's
 * frozen production-file allowlist).
 *
 * This is a SEPARATE, narrower sink from `App\Services\TimelineEventRecorder`
 * (the firm-facing 14-event taxonomy, frozen design §3) — requeue is
 * NOT one of those 14 events. Per agent-9e's own finding (§7 of that
 * report), the two existing per-table audit loggers in this codebase
 * (`RetentionSweepAuditLogger`, `InboundWebhookAuditLogger`) are two
 * separate classes with a similar-but-not-identical shape, never merged
 * into one generic `AuditLogger` — this class follows that same
 * precedent for the requeue-evidence concern, which is shared in SHAPE
 * (`{table, firm_id, id, reason_code, actor_firm_user_id,
 * requeue_count}`) between `IntegrationOutboxEventService::requeue()`
 * and `SyncItemService::requeueFromFailedPermanent()` without those two
 * primitives sharing a write-path abstraction.
 *
 * NEVER logs: row payload/body content, credentials, tokens, or any
 * other column value beyond the closed, typed set this class's own
 * method signature accepts — there is no code path through which
 * row-level content could reach this log even by future accident.
 */
final class IntegrationRequeueAuditLogger
{
    public const EVENT_OUTBOX_EVENT_REQUEUED = 'integration_requeue.outbox_event_requeued';

    public const EVENT_SYNC_ITEM_REQUEUED = 'integration_requeue.sync_item_requeued';

    /**
     * @var string[]
     */
    private const ALLOWED_EVENT_NAMES = [
        self::EVENT_OUTBOX_EVENT_REQUEUED,
        self::EVENT_SYNC_ITEM_REQUEUED,
    ];

    private ?LoggerInterface $logger = null;

    /**
     * Written ONLY when the guarded UPDATE actually returned a row
     * (i.e. only on the call that genuinely performed the transition) —
     * never speculatively before the UPDATE runs, so a losing duplicate
     * concurrent requeue call never produces a phantom audit entry
     * (agent-9e §"Idempotency of a duplicate requeue request").
     */
    public function record(
        string $eventName,
        string $table,
        int $firmId,
        int $id,
        string $reasonCode,
        ?int $actorFirmUserId = null,
        ?int $requeueCount = null,
    ): void {
        if (! in_array($eventName, self::ALLOWED_EVENT_NAMES, true)) {
            throw new InvalidArgumentException(
                "IntegrationRequeueAuditLogger::record() refuses unknown event name '{$eventName}' — ".
                'only the frozen event names (see class docblock) may be logged.'
            );
        }

        $this->logger()->info($eventName, [
            'table' => $table,
            'firm_id' => $firmId,
            'id' => $id,
            'reason_code' => $reasonCode,
            'actor_firm_user_id' => $actorFirmUserId,
            'requeue_count' => $requeueCount,
        ]);
    }

    private function logger(): LoggerInterface
    {
        if ($this->logger === null) {
            $this->logger = Log::build([
                'driver' => 'single',
                'path' => storage_path('logs/integration-requeue-audit.log'),
                'level' => 'info',
            ]);
        }

        return $this->logger;
    }
}
