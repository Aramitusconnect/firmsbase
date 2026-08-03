<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SesEventConsumerService;
use Aws\Exception\AwsException;
use Aws\Sqs\SqsClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ses:consume-events — the dedicated long-polling consumer for the
 * SES bounce/complaint SQS queue. Deliberately NOT a ShouldQueue job
 * and NOT run via `queue:work`: this queue carries raw SES/SNS event
 * JSON, not serialized Laravel job payloads, and `queue:work` would
 * fail trying to unserialize it.
 *
 * Long polling: every receiveMessage() call passes WaitTimeSeconds
 * from config, so an empty queue results in one blocking wait per
 * loop iteration rather than a busy-poll.
 *
 * Delete-only-after-success: deleteMessage() is called if and only if
 * SesEventConsumerService::process() returns true. Everything else
 * (malformed/unresolved/wrong-tenant/a genuine processing exception)
 * leaves the message in the queue — SQS's own visibility timeout and
 * the queue's redrive policy to the DLQ handle retry/escalation; this
 * command never retries a single message itself.
 *
 * Exit behavior: returns self::FAILURE only when the SQS client itself
 * throws (queue misconfigured, no permission, network failure) —
 * distinct from an individual message failing to process, which is
 * normal, expected, non-fatal operation and never stops the loop. A
 * clean SIGTERM/SIGINT shutdown (see installShutdownSignalHandlers())
 * returns self::SUCCESS — it is a normal, expected way for this
 * long-running ECS process to stop, not a failure.
 *
 * Graceful shutdown (ECS SIGTERM, see docs/ecs/graceful-shutdown.md):
 * pcntl_async_signals() + pcntl_signal() install a handler that only
 * ever sets a flag ($shouldStop), never anything that could interrupt
 * a database write mid-transaction. That flag is checked in exactly
 * two places — the top of the outer while loop (never starts another
 * receiveMessage() cycle) and right after each processOne() call
 * inside the inner foreach (stops advancing to the next message in an
 * already-received batch as soon as possible) — so a message already
 * being processed always runs to completion first. Because SQS long
 * polling has a hard ceiling (WaitTimeSeconds, capped at 20s by SQS
 * itself — see config('services.ses_events.wait_time_seconds')), the
 * process notices a pending shutdown and exits within, at worst, one
 * such wait even in the (henceforth theoretical, since pcntl async
 * signals are expected to interrupt the blocking call directly) case
 * where the signal is not delivered until the current receiveMessage()
 * call itself returns — well within ECS's stopTimeout budget, so
 * SIGKILL should not be the normal path. This is a single, per-process
 * signal handler for this command's own one-shot execution (the
 * process exits right after, never reused for a second invocation), so
 * unlike a shared long-lived process' event listeners, there is no
 * cross-invocation listener-leak risk — the handler is still reset to
 * the OS default in a `finally` block below purely as defensive
 * hygiene (relevant if this command is ever invoked more than once
 * within the same PHP process, e.g. under a test runner).
 */
class ConsumeSesEventsCommand extends Command
{
    protected $signature = 'ses:consume-events
        {--max-iterations= : Stop after this many receive-message cycles (omit to run indefinitely)}';

    protected $description = 'Long-poll the SES bounce/complaint SQS queue and process events (never via queue:work).';

    private bool $shouldStop = false;

    public function __construct(
        private readonly SqsClient $sqs,
        private readonly SesEventConsumerService $consumer,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $queueUrl = config('services.ses_events.queue_url');

        if (empty($queueUrl)) {
            $this->components->error('services.ses_events.queue_url is not configured — refusing to start.');

            return self::FAILURE;
        }

        $this->shouldStop = false;
        $this->installShutdownSignalHandlers();

        try {
            $maxIterations = $this->option('max-iterations') !== null
                ? (int) $this->option('max-iterations')
                : null;

            $iteration = 0;

            while (! $this->shouldStop && ($maxIterations === null || $iteration < $maxIterations)) {
                $iteration++;

                try {
                    $result = $this->sqs->receiveMessage([
                        'QueueUrl' => $queueUrl,
                        'MaxNumberOfMessages' => config('services.ses_events.max_messages'),
                        'WaitTimeSeconds' => config('services.ses_events.wait_time_seconds'),
                        'VisibilityTimeout' => config('services.ses_events.visibility_timeout_seconds'),
                    ]);
                } catch (AwsException|Throwable $e) {
                    // A signal can arrive while receiveMessage() is
                    // blocked mid-network-call — depending on timing,
                    // that surfaces here as a thrown exception (a
                    // curl/AWS timeout or connection-abort), which
                    // looks identical to a genuine SQS outage. The
                    // $shouldStop flag (set only by our own signal
                    // handler, never by this exception itself)
                    // disambiguates: if a shutdown was actually
                    // requested, this is expected, intentional
                    // shutdown — not a fatal consumer error — and must
                    // exit successfully so ECS never mistakes a clean
                    // stop for a crash-looping task. A genuine failure
                    // with no signal requested keeps the exact original
                    // behavior: logged as an error and self::FAILURE,
                    // preserving whatever retry/redrive expectations
                    // depend on that exit code.
                    if ($this->shouldStop) {
                        Log::info('ses_consumer_shutdown_during_receive');

                        break;
                    }

                    $this->components->error('SQS receiveMessage failed: '.$e->getMessage());

                    return self::FAILURE;
                }

                $messages = $result['Messages'] ?? [];

                foreach ($messages as $message) {
                    $this->processOne($queueUrl, $message);

                    // Let the message just processed finish and be
                    // deleted (already happened, above) before honoring
                    // a pending shutdown — never abandons mid-message,
                    // but also never starts a fresh one after the
                    // signal arrived.
                    if ($this->shouldStop) {
                        break;
                    }
                }
            }

            if ($this->shouldStop) {
                Log::info('ses_consumer_shutdown_complete');
            }

            return self::SUCCESS;
        } finally {
            $this->restoreDefaultSignalHandlers();
        }
    }

    /**
     * Registers SIGTERM/SIGINT handlers that do nothing but flip
     * $shouldStop — never touches the database, SQS, or logs anything
     * beyond a generic "signal received" line (no message content, no
     * recipient, no secret). Requires pcntl (confirmed present in this
     * image — see docs/ecs/ec2-dependency-audit.md); silently a no-op
     * if it is ever absent, matching this consumer's existing "never
     * crash the long-running loop over an environment quirk" posture.
     */
    private function installShutdownSignalHandlers(): void
    {
        if (! extension_loaded('pcntl')) {
            return;
        }

        pcntl_async_signals(true);

        $onShutdownSignal = function (int $signal): void {
            $this->shouldStop = true;
            Log::info('ses_consumer_shutdown_signal_received', ['signal' => $signal]);
        };

        pcntl_signal(SIGTERM, $onShutdownSignal);
        pcntl_signal(SIGINT, $onShutdownSignal);
    }

    private function restoreDefaultSignalHandlers(): void
    {
        if (! extension_loaded('pcntl')) {
            return;
        }

        pcntl_signal(SIGTERM, SIG_DFL);
        pcntl_signal(SIGINT, SIG_DFL);
    }

    /**
     * @param  array{MessageId: string, ReceiptHandle: string, Body: string}  $message
     */
    private function processOne(string $queueUrl, array $message): void
    {
        $sqsMessageId = $message['MessageId'] ?? 'unknown';

        // An unexpected exception from process() (a DB error, a
        // concurrent-processing race, etc.) is a single-message
        // processing failure, not a reason to crash this long-running
        // loop — audit-fixed: this used to propagate uncaught, killing
        // the whole consumer process on the first unlucky message.
        // Never deleted in this branch, so SQS's own visibility-timeout
        // redelivery and redrive-to-DLQ policy still apply.
        try {
            $safeToDelete = $this->consumer->process($sqsMessageId, $message['Body'] ?? '');
        } catch (Throwable $e) {
            Log::error('ses_event_processing_exception', [
                'sqs_message_id' => $sqsMessageId,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return;
        }

        if (! $safeToDelete) {
            return;
        }

        try {
            $this->sqs->deleteMessage([
                'QueueUrl' => $queueUrl,
                'ReceiptHandle' => $message['ReceiptHandle'],
            ]);
        } catch (AwsException|Throwable $e) {
            // Deletion failure is not a processing failure — the
            // business-logic side already succeeded durably. The
            // message simply remains visible again after its
            // visibility timeout and gets reprocessed; the idempotency
            // ledger (SesEventReceipt) makes that redelivery a cheap,
            // safe no-op rather than a repeated suppression write.
            $this->components->warn("SQS deleteMessage failed for {$sqsMessageId}: ".$e->getMessage());
        }
    }
}
