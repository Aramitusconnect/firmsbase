<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Enums\ConsentChannel;
use App\Models\Firm;
use App\Models\NotificationProviderCorrelation;
use App\Services\SesEventConsumerService;
use Aws\AwsClient;
use Aws\Result;
use Aws\Sqs\SqsClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * ConsumeSesEventsCommandTest — feature/ses-event-consumer. Never
 * touches a live SQS queue: Aws\Sqs\SqsClient is fully mocked via the
 * container (matching config/services.php's own contract — the real
 * class is only ever constructed from config, never hardcoded), so
 * these tests exercise the command's real receive/process/delete loop
 * end to end without any network call.
 */
class ConsumeSesEventsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.ses_events.queue_url' => 'https://sqs.us-east-1.amazonaws.com/603013471426/firmsvault-staging-ses-events']);
    }

    private function bounceBody(string $messageId, string $bounceType, string $recipient): string
    {
        return json_encode([
            'eventType' => 'Bounce',
            'mail' => ['messageId' => $messageId, 'destination' => [$recipient]],
            'bounce' => [
                'bounceType' => $bounceType,
                'bouncedRecipients' => [['emailAddress' => $recipient]],
                'feedbackId' => (string) Str::uuid(),
            ],
        ]);
    }

    public function test_successful_processing_deletes_the_sqs_message(): void
    {
        $firm = Firm::factory()->create();
        NotificationProviderCorrelation::create([
            'correlation_id' => (string) Str::uuid(),
            'firm_id' => $firm->id,
            'channel' => ConsentChannel::Email->value,
            'recipient_normalized' => 'owner@example.com',
            'provider_message_id' => 'msg-cmd-1',
        ]);

        $sqs = Mockery::mock(SqsClient::class);
        $sqs->shouldReceive('receiveMessage')
            ->once()
            ->andReturn(new Result([
                'Messages' => [[
                    'MessageId' => 'sqs-cmd-1',
                    'ReceiptHandle' => 'receipt-1',
                    'Body' => $this->bounceBody('msg-cmd-1', 'Permanent', 'owner@example.com'),
                ]],
            ]));
        $sqs->shouldReceive('deleteMessage')
            ->once()
            ->with(Mockery::on(fn ($args) => $args['ReceiptHandle'] === 'receipt-1'));

        $this->app->instance(SqsClient::class, $sqs);

        $this->artisan('ses:consume-events', ['--max-iterations' => 1])
            ->assertExitCode(0);
    }

    public function test_processing_failure_leaves_the_message_undeleted(): void
    {
        // No NotificationProviderCorrelation exists for this message id
        // — SesEventConsumerService::process() will return false.
        $sqs = Mockery::mock(SqsClient::class);
        $sqs->shouldReceive('receiveMessage')
            ->once()
            ->andReturn(new Result([
                'Messages' => [[
                    'MessageId' => 'sqs-cmd-2',
                    'ReceiptHandle' => 'receipt-2',
                    'Body' => $this->bounceBody('msg-unresolved', 'Permanent', 'owner@example.com'),
                ]],
            ]));
        $sqs->shouldNotReceive('deleteMessage');

        $this->app->instance(SqsClient::class, $sqs);

        $this->artisan('ses:consume-events', ['--max-iterations' => 1])
            ->assertExitCode(0);
    }

    /**
     * Post-578ee98 audit finding B2: an unexpected exception from
     * SesEventConsumerService::process() (a DB error, a concurrent-
     * processing race, etc.) is a single-message processing failure,
     * never a reason to crash this long-running loop — this used to
     * propagate uncaught and kill the whole consumer process.
     */
    public function test_an_unexpected_exception_from_process_does_not_crash_the_command(): void
    {
        $sqs = Mockery::mock(SqsClient::class);
        $sqs->shouldReceive('receiveMessage')
            ->once()
            ->andReturn(new Result([
                'Messages' => [[
                    'MessageId' => 'sqs-cmd-3',
                    'ReceiptHandle' => 'receipt-3',
                    'Body' => $this->bounceBody('msg-cmd-3', 'Permanent', 'owner@example.com'),
                ]],
            ]));
        $sqs->shouldNotReceive('deleteMessage');

        $this->app->instance(SqsClient::class, $sqs);

        $consumer = Mockery::mock(SesEventConsumerService::class);
        $consumer->shouldReceive('process')->once()->andThrow(new \RuntimeException('simulated DB error'));
        $this->app->instance(SesEventConsumerService::class, $consumer);

        $this->artisan('ses:consume-events', ['--max-iterations' => 1])
            ->assertExitCode(0);
    }

    public function test_an_empty_receive_result_is_handled_without_error(): void
    {
        $sqs = Mockery::mock(SqsClient::class);
        $sqs->shouldReceive('receiveMessage')
            ->once()
            ->andReturn(new Result([]));
        $sqs->shouldNotReceive('deleteMessage');

        $this->app->instance(SqsClient::class, $sqs);

        $this->artisan('ses:consume-events', ['--max-iterations' => 1])
            ->assertExitCode(0);
    }

    public function test_command_requires_no_static_aws_credentials(): void
    {
        $this->assertNull(config('services.ses_events.key'));
        $this->assertNull(config('services.ses_events.secret'));

        // The container binding itself (app/Providers/AppServiceProvider)
        // never sets 'credentials' when key/secret are both empty —
        // confirmed directly against the real, unmocked binding.
        $client = app(SqsClient::class);
        $this->assertInstanceOf(SqsClient::class, $client);
    }

    /**
     * A real container-level smoke test (feature/ses-consumer-ecs-wiring)
     * proved pcntl's async signal dispatch cannot interrupt a blocking
     * C-library network call already in flight: against a black-holed
     * network path, the process hung until docker stop's SIGKILL rather
     * than exiting cleanly on SIGTERM. Bounding the SDK's own HTTP
     * connect/overall timeouts is what actually fixes that (the process
     * then exits via its own AwsException catch well within any
     * reasonable ECS stopTimeout, before SIGKILL is ever needed) — this
     * proves those bounds are actually configured on the real,
     * unmocked client, not just present in a code comment.
     */
    public function test_the_real_sqs_client_has_bounded_connect_and_overall_http_timeouts(): void
    {
        config(['services.ses_events.wait_time_seconds' => 20]);

        $client = app(SqsClient::class);

        // Aws\AwsClient stores the constructor's 'http' option in a
        // private property with no public getter — reflection is the
        // standard, legitimate way to verify OUR OWN service
        // provider's effect on the real, unmocked client object,
        // exactly as intended, rather than reimplementing the SDK's
        // own config-merging logic in a duplicate assertion.
        $reflection = new \ReflectionProperty(AwsClient::class, 'defaultRequestOptions');
        $reflection->setAccessible(true);
        $http = $reflection->getValue($client);

        $this->assertIsArray($http);
        $this->assertArrayHasKey('connect_timeout', $http);
        $this->assertArrayHasKey('timeout', $http);
        $this->assertLessThanOrEqual(10, $http['connect_timeout'], 'connect_timeout must stay well under any reasonable ECS stopTimeout.');
        $this->assertGreaterThan(20, $http['timeout'], 'timeout must comfortably exceed the configured long-poll wait_time_seconds so a legitimate long poll is never cut short.');
    }

    // ------------------------------------------------------------
    // Graceful shutdown (docs/ecs/graceful-shutdown.md). No real ECS
    // SIGTERM is available in a test process, so these simulate it the
    // standard way: posix_kill(getmypid(), SIGTERM) sent to THIS SAME
    // process from inside a mocked callback. Because the command
    // installs its own pcntl_signal() handler before entering the
    // receive loop, that handler — not the OS default — owns the
    // signal's disposition, so this is safe (it does not terminate the
    // test process) and exercises the real handler/flag/loop-control
    // code, not a reimplementation of it.
    // ------------------------------------------------------------

    public function test_sigterm_during_processing_prevents_further_messages_in_the_batch_and_another_receive_cycle(): void
    {
        if (! extension_loaded('pcntl') || ! extension_loaded('posix')) {
            $this->markTestSkipped('pcntl/posix not loaded in this environment.');
        }

        $firm = Firm::factory()->create();
        NotificationProviderCorrelation::create([
            'correlation_id' => (string) Str::uuid(),
            'firm_id' => $firm->id,
            'channel' => ConsentChannel::Email->value,
            'recipient_normalized' => 'owner@example.com',
            'provider_message_id' => 'msg-shutdown-1',
        ]);

        // A single receiveMessage() call returns TWO messages — proves
        // the shutdown flag also stops mid-batch, not just between
        // receive cycles.
        $sqs = Mockery::mock(SqsClient::class);
        $sqs->shouldReceive('receiveMessage')
            ->once()
            ->andReturn(new Result([
                'Messages' => [
                    [
                        'MessageId' => 'sqs-shutdown-1',
                        'ReceiptHandle' => 'receipt-shutdown-1',
                        'Body' => $this->bounceBody('msg-shutdown-1', 'Permanent', 'owner@example.com'),
                    ],
                    [
                        'MessageId' => 'sqs-shutdown-2',
                        'ReceiptHandle' => 'receipt-shutdown-2',
                        'Body' => $this->bounceBody('msg-shutdown-2', 'Permanent', 'owner@example.com'),
                    ],
                ],
            ]));
        // Only the first message's successful processing gets deleted —
        // proves "processing success still permits delete" survives a
        // shutdown signal raised immediately afterward, and the second
        // message (never reached) is correctly left untouched.
        $sqs->shouldReceive('deleteMessage')
            ->once()
            ->with(Mockery::on(fn ($args) => $args['ReceiptHandle'] === 'receipt-shutdown-1'));
        $this->app->instance(SqsClient::class, $sqs);

        $realConsumer = $this->app->make(SesEventConsumerService::class);
        $consumer = Mockery::mock(SesEventConsumerService::class);
        $consumer->shouldReceive('process')
            ->once()
            ->with('sqs-shutdown-1', Mockery::type('string'))
            ->andReturnUsing(function (string $sqsMessageId, string $rawBody) use ($realConsumer) {
                $result = $realConsumer->process($sqsMessageId, $rawBody);
                posix_kill(posix_getpid(), SIGTERM);

                return $result;
            });
        $this->app->instance(SesEventConsumerService::class, $consumer);

        // No --max-iterations: only the shutdown flag (not an iteration
        // cap) is what stops this run, proving the signal itself — not
        // a test-only escape hatch — controls the loop.
        $this->artisan('ses:consume-events')
            ->assertExitCode(0);

        // Mockery's ->once() expectations above already assert
        // receiveMessage/process/deleteMessage were called exactly
        // once each — i.e. no second receive cycle and no second
        // message processed after the signal.
    }

    public function test_shutdown_signal_log_never_contains_message_or_recipient_content(): void
    {
        if (! extension_loaded('pcntl') || ! extension_loaded('posix')) {
            $this->markTestSkipped('pcntl/posix not loaded in this environment.');
        }

        Log::spy();

        $sqs = Mockery::mock(SqsClient::class);
        $sqs->shouldReceive('receiveMessage')
            ->once()
            ->andReturn(new Result([
                'Messages' => [[
                    'MessageId' => 'sqs-shutdown-3',
                    'ReceiptHandle' => 'receipt-shutdown-3',
                    'Body' => $this->bounceBody('msg-shutdown-3', 'Permanent', 'secret-recipient@example.com'),
                ]],
            ]));
        $sqs->shouldReceive('deleteMessage')->zeroOrMoreTimes();
        $this->app->instance(SqsClient::class, $sqs);

        $consumer = Mockery::mock(SesEventConsumerService::class);
        $consumer->shouldReceive('process')
            ->once()
            ->andReturnUsing(function () {
                posix_kill(posix_getpid(), SIGTERM);

                return false;
            });
        $this->app->instance(SesEventConsumerService::class, $consumer);

        $this->artisan('ses:consume-events')->assertExitCode(0);

        Log::shouldHaveReceived('info')
            ->with('ses_consumer_shutdown_signal_received', Mockery::on(function (array $context) {
                $this->assertArrayNotHasKey('recipient', $context);
                $this->assertArrayNotHasKey('body', $context);
                $this->assertArrayNotHasKey('message', $context);

                return true;
            }))
            ->atLeast()->once();
    }
}
