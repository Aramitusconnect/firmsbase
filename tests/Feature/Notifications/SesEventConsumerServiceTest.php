<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Enums\ConsentChannel;
use App\Enums\SesEventType;
use App\Models\Firm;
use App\Models\NotificationProviderCorrelation;
use App\Models\PlatformNotificationCorrelation;
use App\Models\PlatformNotificationSuppression;
use App\Models\SesEventReceipt;
use App\Services\PlatformNotificationCorrelationService;
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

        config(['services.platform_notifications.recipient_fingerprint_hmac_key' => 'test-fingerprint-hmac-key']);

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

    /**
     * Post-578ee98 audit finding B4: an ISP feedback loop is known to
     * redact/broaden complainedRecipients — provider_message_id is
     * already authoritative, so a Complaint must still suppress even
     * when the reported recipient doesn't literally match. Contrast
     * with the Bounce test above, which correctly keeps the hard
     * reject (bounces come directly from SES, not a third-party
     * feedback loop).
     */
    public function test_complaint_with_a_redacted_or_mismatched_recipient_still_suppresses(): void
    {
        $firm = Firm::factory()->create();
        $this->correlate($firm, 'owner@example.com', 'msg-redacted-1');

        $result = $this->consumer->process('sqs-1', $this->complaintEvent('msg-redacted-1', ['redacted-by-isp@example.com']));

        $this->assertTrue($result);
        $this->assertTrue($this->runWithFirmContext(
            $firm,
            fn () => $this->suppression->isSuppressed($firm, 'owner@example.com', ConsentChannel::Email),
        ));
    }

    /**
     * Post-578ee98 audit finding B3: the receipt write is the actual
     * concurrency gate. Reflection is used to invoke the private
     * recordReceipt() method directly with a pre-existing conflicting
     * row — the only reliable way to exercise a genuine unique-
     * constraint race in a single-threaded test, since a second
     * process() call would simply see the first call's row via the
     * ordinary exists() pre-check rather than racing past it.
     */
    public function test_a_receipt_unique_constraint_race_is_handled_without_throwing(): void
    {
        SesEventReceipt::create([
            'idempotency_key' => 'Bounce:race-key-1',
            'event_type' => 'Bounce',
            'ses_message_id' => 'msg-race-1',
            'sqs_message_id' => 'sqs-winner',
            'processed_at' => now(),
        ]);

        $method = new \ReflectionMethod(SesEventConsumerService::class, 'recordReceipt');
        $method->setAccessible(true);

        $eventType = SesEventType::Bounce;

        $result = $method->invoke($this->consumer, 'Bounce:race-key-1', $eventType, 'msg-race-1', 'sqs-loser');

        $this->assertTrue($result, 'A unique-violation on the receipt insert must be treated as a safe duplicate, not thrown.');
        $this->assertSame(1, SesEventReceipt::query()->where('idempotency_key', 'Bounce:race-key-1')->count());
    }

    public function test_platform_scope_correlation_resolves_a_permanent_bounce_without_touching_suppression_service(): void
    {
        $service = app(PlatformNotificationCorrelationService::class);

        $correlation = PlatformNotificationCorrelation::create([
            'correlation_id' => (string) Str::uuid(),
            'account_type' => 'App\\Models\\User',
            'account_id' => 1,
            'notification_type' => 'user_password_reset',
            'recipient_fingerprint' => $service->fingerprintFor('owner@example.com'),
            'provider_message_id' => 'msg-platform-1',
        ]);

        $result = $this->consumer->process('sqs-1', $this->bounceEvent('msg-platform-1', 'Permanent', ['owner@example.com']));

        $this->assertTrue($result);
        $this->assertTrue($service->isRecipientSuppressed('owner@example.com'));

        $suppression = PlatformNotificationSuppression::query()->sole();
        $this->assertSame($correlation->correlation_id, $suppression->correlation_id);
        $this->assertSame('bounced', $suppression->status->value);
    }

    public function test_platform_scope_correlation_is_checked_only_after_firm_scope_correlation_misses(): void
    {
        $firm = Firm::factory()->create();
        $this->correlate($firm, 'owner@example.com', 'msg-firm-wins');

        // A platform-scope correlation for the SAME provider_message_id
        // should never be reachable in practice (provider_message_id is
        // unique per table), but this proves the firm-scope table is
        // always tried first and, once it resolves, the firm-scoped
        // SuppressionService path is used — never the platform one.
        $result = $this->consumer->process('sqs-1', $this->bounceEvent('msg-firm-wins', 'Permanent', ['owner@example.com']));

        $this->assertTrue($result);
        $this->assertTrue($this->runWithFirmContext(
            $firm,
            fn () => $this->suppression->isSuppressed($firm, 'owner@example.com', ConsentChannel::Email),
        ));
        $this->assertSame(0, PlatformNotificationSuppression::query()->count());
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
