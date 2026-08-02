<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Enums\ConsentChannel;
use App\Models\Firm;
use App\Models\NotificationProviderCorrelation;
use App\Models\SesEventReceipt;
use App\Services\SesEventConsumerService;
use App\Services\SuppressionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SesEventConsumerServiceTest — feature/ses-event-consumer. Never
 * touches a live SQS queue: process() is called directly with a raw
 * message body string, exactly as ConsumeSesEventsCommand would supply
 * it from a real (or, in ConsumeSesEventsCommandTest, mocked) SQS
 * receiveMessage() response.
 */
class SesEventConsumerServiceTest extends TestCase
{
    use RefreshDatabase;

    private SesEventConsumerService $consumer;

    private SuppressionService $suppression;

    protected function setUp(): void
    {
        parent::setUp();

        $this->consumer = app(SesEventConsumerService::class);
        $this->suppression = app(SuppressionService::class);
    }

    private function correlate(Firm $firm, string $recipient, string $providerMessageId): NotificationProviderCorrelation
    {
        return NotificationProviderCorrelation::create([
            'correlation_id' => (string) Str::uuid(),
            'firm_id' => $firm->id,
            'channel' => ConsentChannel::Email->value,
            'recipient_normalized' => mb_strtolower($recipient),
            'provider_message_id' => $providerMessageId,
        ]);
    }

    private function bounceEvent(string $messageId, string $bounceType, array $recipients, ?string $feedbackId = null): string
    {
        return json_encode([
            'eventType' => 'Bounce',
            'mail' => ['messageId' => $messageId, 'destination' => $recipients],
            'bounce' => [
                'bounceType' => $bounceType,
                'bouncedRecipients' => array_map(fn ($r) => ['emailAddress' => $r], $recipients),
                'feedbackId' => $feedbackId ?? (string) Str::uuid(),
            ],
        ]);
    }

    private function complaintEvent(string $messageId, array $recipients, ?string $feedbackId = null): string
    {
        return json_encode([
            'eventType' => 'Complaint',
            'mail' => ['messageId' => $messageId, 'destination' => $recipients],
            'complaint' => [
                'complainedRecipients' => array_map(fn ($r) => ['emailAddress' => $r], $recipients),
                'feedbackId' => $feedbackId ?? (string) Str::uuid(),
            ],
        ]);
    }

    private function simpleEvent(string $eventType, string $messageId, array $recipients): string
    {
        return json_encode([
            'eventType' => $eventType,
            'mail' => ['messageId' => $messageId, 'destination' => $recipients],
        ]);
    }

    public function test_permanent_bounce_suppresses_the_recipient(): void
    {
        $firm = Firm::factory()->create();
        $correlation = $this->correlate($firm, 'owner@example.com', 'msg-perm-1');

        $result = $this->consumer->process('sqs-1', $this->bounceEvent('msg-perm-1', 'Permanent', ['owner@example.com']));

        $this->assertTrue($result);
        $this->assertTrue($this->runWithFirmContext(
            $firm,
            fn () => $this->suppression->isSuppressed($firm, 'owner@example.com', ConsentChannel::Email),
        ));
    }

    public function test_transient_bounce_does_not_suppress(): void
    {
        $firm = Firm::factory()->create();
        $this->correlate($firm, 'owner@example.com', 'msg-trans-1');

        $result = $this->consumer->process('sqs-1', $this->bounceEvent('msg-trans-1', 'Transient', ['owner@example.com']));

        $this->assertTrue($result);
        $this->assertFalse($this->runWithFirmContext(
            $firm,
            fn () => $this->suppression->isSuppressed($firm, 'owner@example.com', ConsentChannel::Email),
        ));
    }

    public function test_complaint_suppresses_the_recipient(): void
    {
        $firm = Firm::factory()->create();
        $this->correlate($firm, 'owner@example.com', 'msg-complaint-1');

        $result = $this->consumer->process('sqs-1', $this->complaintEvent('msg-complaint-1', ['owner@example.com']));

        $this->assertTrue($result);
        $this->assertTrue($this->runWithFirmContext(
            $firm,
            fn () => $this->suppression->isSuppressed($firm, 'owner@example.com', ConsentChannel::Email),
        ));
    }

    public function test_delivery_delay_does_not_suppress(): void
    {
        $firm = Firm::factory()->create();
        $this->correlate($firm, 'owner@example.com', 'msg-delay-1');

        $result = $this->consumer->process('sqs-1', $this->simpleEvent('DeliveryDelay', 'msg-delay-1', ['owner@example.com']));

        $this->assertTrue($result);
        $this->assertFalse($this->runWithFirmContext(
            $firm,
            fn () => $this->suppression->isSuppressed($firm, 'owner@example.com', ConsentChannel::Email),
        ));
    }

    public function test_reject_is_logged_but_does_not_suppress(): void
    {
        $firm = Firm::factory()->create();
        $this->correlate($firm, 'owner@example.com', 'msg-reject-1');

        $result = $this->consumer->process('sqs-1', $this->simpleEvent('Reject', 'msg-reject-1', ['owner@example.com']));

        $this->assertTrue($result);
        $this->assertFalse($this->runWithFirmContext(
            $firm,
            fn () => $this->suppression->isSuppressed($firm, 'owner@example.com', ConsentChannel::Email),
        ));
    }

    public function test_rendering_failure_is_logged_but_does_not_suppress(): void
    {
        $firm = Firm::factory()->create();
        $this->correlate($firm, 'owner@example.com', 'msg-render-1');

        $result = $this->consumer->process('sqs-1', $this->simpleEvent('Rendering Failure', 'msg-render-1', ['owner@example.com']));

        $this->assertTrue($result);
        $this->assertFalse($this->runWithFirmContext(
            $firm,
            fn () => $this->suppression->isSuppressed($firm, 'owner@example.com', ConsentChannel::Email),
        ));
    }

    public function test_malformed_json_is_not_acknowledged(): void
    {
        $result = $this->consumer->process('sqs-1', '{not valid json');

        $this->assertFalse($result);
    }

    public function test_sns_subscription_confirmation_is_safely_acknowledged_without_processing(): void
    {
        $result = $this->consumer->process('sqs-1', json_encode([
            'Type' => 'SubscriptionConfirmation',
            'SubscribeURL' => 'https://sns.us-east-1.amazonaws.com/confirm-me',
        ]));

        $this->assertTrue($result);
        $this->assertSame(0, SesEventReceipt::query()->count());
    }

    public function test_duplicate_sqs_delivery_of_the_identical_message_is_idempotent(): void
    {
        $firm = Firm::factory()->create();
        $this->correlate($firm, 'owner@example.com', 'msg-dup-1');

        $body = $this->bounceEvent('msg-dup-1', 'Permanent', ['owner@example.com'], 'feedback-dup-1');

        $first = $this->consumer->process('sqs-1', $body);
        $second = $this->consumer->process('sqs-2', $body);

        $this->assertTrue($first);
        $this->assertTrue($second);
        $this->assertSame(1, SesEventReceipt::query()->where('idempotency_key', 'Bounce:feedback-dup-1')->count());
    }

    public function test_duplicate_ses_event_delivered_as_a_different_sqs_message_is_still_deduplicated(): void
    {
        $firm = Firm::factory()->create();
        $this->correlate($firm, 'owner@example.com', 'msg-dup-2');

        // Same underlying SES feedbackId, but simulate SNS's own retry
        // producing a genuinely different SQS message id/body ordering —
        // the idempotency key is derived from feedbackId, not the SQS
        // message id, so this must still be recognized as a duplicate.
        $first = $this->consumer->process('sqs-a', $this->bounceEvent('msg-dup-2', 'Permanent', ['owner@example.com'], 'feedback-dup-2'));
        $second = $this->consumer->process('sqs-b', $this->bounceEvent('msg-dup-2', 'Permanent', ['owner@example.com'], 'feedback-dup-2'));

        $this->assertTrue($first);
        $this->assertTrue($second);
        $this->assertSame(1, SesEventReceipt::query()->count());
    }

    public function test_missing_firm_correlation_is_not_acknowledged(): void
    {
        // No NotificationProviderCorrelation created for this message id.
        $result = $this->consumer->process('sqs-1', $this->bounceEvent('msg-unknown', 'Permanent', ['owner@example.com']));

        $this->assertFalse($result);
        $this->assertSame(0, SesEventReceipt::query()->count());
    }

    public function test_invalid_event_structure_is_not_acknowledged(): void
    {
        $result = $this->consumer->process('sqs-1', json_encode(['eventType' => 'Bounce', 'mail' => []]));

        $this->assertFalse($result);
    }

    public function test_wrong_tenant_correlation_recipient_mismatch_is_not_acknowledged(): void
    {
        $firm = Firm::factory()->create();
        // Correlation exists for THIS message id, but for a different recipient than the event claims bounced.
        $this->correlate($firm, 'someone-else@example.com', 'msg-mismatch-1');

        $result = $this->consumer->process('sqs-1', $this->bounceEvent('msg-mismatch-1', 'Permanent', ['owner@example.com']));

        $this->assertFalse($result);
        $this->assertFalse($this->runWithFirmContext(
            $firm,
            fn () => $this->suppression->isSuppressed($firm, 'owner@example.com', ConsentChannel::Email),
        ));
    }

    public function test_rls_tenant_context_is_cleared_after_processing(): void
    {
        $firm = Firm::factory()->create();
        $this->correlate($firm, 'owner@example.com', 'msg-context-1');

        $this->consumer->process('sqs-1', $this->bounceEvent('msg-context-1', 'Permanent', ['owner@example.com']));

        $this->assertNoDatabaseTenantContext();
    }

    public function test_processing_never_requires_static_aws_credentials(): void
    {
        $this->assertNull(config('services.ses_events.key'));
        $this->assertNull(config('services.ses_events.secret'));
    }
}
