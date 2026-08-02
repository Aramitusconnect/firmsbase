<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SesEventConsumerService;
use Aws\Exception\AwsException;
use Aws\Sqs\SqsClient;
use Illuminate\Console\Command;
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
 * normal, expected, non-fatal operation and never stops the loop.
 */
class ConsumeSesEventsCommand extends Command
{
    protected $signature = 'ses:consume-events
        {--max-iterations= : Stop after this many receive-message cycles (omit to run indefinitely)}';

    protected $description = 'Long-poll the SES bounce/complaint SQS queue and process events (never via queue:work).';

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

        $maxIterations = $this->option('max-iterations') !== null
            ? (int) $this->option('max-iterations')
            : null;

        $iteration = 0;

        while ($maxIterations === null || $iteration < $maxIterations) {
            $iteration++;

            try {
                $result = $this->sqs->receiveMessage([
                    'QueueUrl' => $queueUrl,
                    'MaxNumberOfMessages' => config('services.ses_events.max_messages'),
                    'WaitTimeSeconds' => config('services.ses_events.wait_time_seconds'),
                    'VisibilityTimeout' => config('services.ses_events.visibility_timeout_seconds'),
                ]);
            } catch (AwsException|Throwable $e) {
                $this->components->error('SQS receiveMessage failed: '.$e->getMessage());

                return self::FAILURE;
            }

            $messages = $result['Messages'] ?? [];

            foreach ($messages as $message) {
                $this->processOne($queueUrl, $message);
            }
        }

        return self::SUCCESS;
    }

    /**
     * @param  array{MessageId: string, ReceiptHandle: string, Body: string}  $message
     */
    private function processOne(string $queueUrl, array $message): void
    {
        $sqsMessageId = $message['MessageId'] ?? 'unknown';

        $safeToDelete = $this->consumer->process($sqsMessageId, $message['Body'] ?? '');

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
