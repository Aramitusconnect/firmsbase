<?php

declare(strict_types=1);

namespace App\Integrations\Services;

use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;

/**
 * RetentionSweepAuditLogger — Checkpoint 8
 * (agent-8g-retention-cleanup-design.md §7.5), deliberately mirroring
 * App\Integrations\Services\InboundWebhookAuditLogger's exact shape: a
 * closed event-name allowlist, a dedicated `Log::build()` platform-only
 * log channel (matching this checkpoint series' own established
 * precedent rather than a `config/logging.php` entry, which is outside
 * this checkpoint's frozen production-file allowlist).
 *
 * NEVER logs, by construction (not merely by convention): row ids, raw
 * payload/body content, routing tokens or hashes, provider event ids,
 * failure detail text, resolution notes, or any other row-level column
 * value — every context payload accepted here is
 * {table, firm_id|null, count, dry_run}-SHAPED ONLY (enforced by this
 * class's own typed method signatures, not a denylist over a free-form
 * array), so there is no code path through which row-level content
 * could reach this log even by future accident.
 */
final class RetentionSweepAuditLogger
{
    public const EVENT_RUN_STARTED = 'integration_retention.run_started';

    public const EVENT_TABLE_BATCH_COMPLETED = 'integration_retention.table_batch_completed';

    public const EVENT_TABLE_SWEPT = 'integration_retention.table_swept';

    public const EVENT_RUN_COMPLETED = 'integration_retention.run_completed';

    public const EVENT_FIRM_ITERATION_ERROR = 'integration_retention.firm_iteration_error';

    public const EVENT_STUCK_TERMINAL_DEADLINE_ROW = 'integration_retention.stuck_terminal_deadline_row';

    public const EVENT_OAUTH_STATE_UNCONSUMED_CLEANUP_NOT_CONFIGURED = 'integration_retention.oauth_state_unconsumed_cleanup_not_configured';

    /**
     * @var string[]
     */
    private const ALLOWED_EVENT_NAMES = [
        self::EVENT_RUN_STARTED,
        self::EVENT_TABLE_BATCH_COMPLETED,
        self::EVENT_TABLE_SWEPT,
        self::EVENT_RUN_COMPLETED,
        self::EVENT_FIRM_ITERATION_ERROR,
        self::EVENT_STUCK_TERMINAL_DEADLINE_ROW,
        self::EVENT_OAUTH_STATE_UNCONSUMED_CLEANUP_NOT_CONFIGURED,
    ];

    private ?LoggerInterface $logger = null;

    /**
     * $firmId is nullable — null represents the one platform-owned
     * sweeper (integration_webhook_receipts, which has no firm_id
     * column at all, agent-8g §3/§6).
     */
    public function record(
        string $eventName,
        ?string $table = null,
        ?int $firmId = null,
        ?int $count = null,
        bool $dryRun = false,
    ): void {
        if (! in_array($eventName, self::ALLOWED_EVENT_NAMES, true)) {
            throw new InvalidArgumentException(
                "RetentionSweepAuditLogger::record() refuses unknown event name '{$eventName}' — ".
                'only the frozen event names (see class docblock) may be logged.'
            );
        }

        $this->logger()->info($eventName, [
            'table' => $table,
            'firm_id' => $firmId,
            'count' => $count,
            'dry_run' => $dryRun,
        ]);
    }

    private function logger(): LoggerInterface
    {
        if ($this->logger === null) {
            $this->logger = Log::build([
                'driver' => 'single',
                'path' => storage_path('logs/integration-retention-sweep.log'),
                'level' => 'info',
            ]);
        }

        return $this->logger;
    }
}
