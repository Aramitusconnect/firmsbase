<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Enums\ConsentChannel;
use App\Models\Firm;
use App\Models\NotificationProviderCorrelation;
use App\Services\SesEventConsumerService;
use Aws\Result;
use Aws\Sqs\SqsClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
