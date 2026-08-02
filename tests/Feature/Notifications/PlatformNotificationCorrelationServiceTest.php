<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Enums\NotificationEventStatus;
use App\Models\PlatformNotificationCorrelation;
use App\Models\PlatformNotificationSuppression;
use App\Services\PlatformNotificationCorrelationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\SentMessage as IlluminateSentMessage;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage as SymfonySentMessage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * PlatformNotificationCorrelationServiceTest — post-578ee98 audit
 * remediation (finding H1). Never sends real mail — mirrors
 * OutboundMailCorrelationServiceTest's own MessageSent-simulation
 * technique exactly.
 */
class PlatformNotificationCorrelationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.platform_notifications.recipient_fingerprint_hmac_key' => 'test-fingerprint-hmac-key']);
    }

    private function dispatchFakeSentMessage(string $correlationId, string $sesMessageId, string $recipient): void
    {
        $email = (new Email)
            ->from('no-reply@staging-mail.firmsvault.com')
            ->to($recipient)
            ->subject('Test')
            ->text('Body');

        $email->getHeaders()->addTextHeader('X-Metadata-correlation_id', $correlationId);
        $email->getHeaders()->addTextHeader('X-Message-ID', $sesMessageId);

        $envelope = new Envelope(
            new Address('no-reply@staging-mail.firmsvault.com'),
            [new Address($recipient)],
        );

        Event::dispatch(new MessageSent(new IlluminateSentMessage(new SymfonySentMessage($email, $envelope))));
    }

    public function test_correlate_stores_a_keyed_hmac_fingerprint_never_plaintext(): void
    {
        $service = app(PlatformNotificationCorrelationService::class);

        $service->correlate(
            'App\\Models\\User',
            42,
            'user_password_reset',
            'Owner@Example.com',
            function (string $correlationId) {
                $this->dispatchFakeSentMessage($correlationId, 'ses-msg-platform-1', 'owner@example.com');
            },
        );

        $correlation = PlatformNotificationCorrelation::query()->sole();

        $this->assertNotSame('owner@example.com', $correlation->recipient_fingerprint);
        $this->assertSame(64, strlen($correlation->recipient_fingerprint), 'Expected a hex-encoded SHA-256 HMAC (64 chars).');
        $this->assertSame($service->fingerprintFor('owner@example.com'), $correlation->recipient_fingerprint);
        $this->assertSame('App\\Models\\User', $correlation->account_type);
        $this->assertSame(42, $correlation->account_id);
        $this->assertSame('user_password_reset', $correlation->notification_type);
        $this->assertSame('ses-msg-platform-1', $correlation->provider_message_id);
    }

    public function test_fingerprint_is_case_and_whitespace_normalized(): void
    {
        $service = app(PlatformNotificationCorrelationService::class);

        $this->assertSame(
            $service->fingerprintFor('Owner@Example.com'),
            $service->fingerprintFor('  owner@example.com  '),
        );
    }

    public function test_hmac_key_is_required_and_fails_closed_when_missing(): void
    {
        config(['services.platform_notifications.recipient_fingerprint_hmac_key' => null]);

        $service = app(PlatformNotificationCorrelationService::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/recipient_fingerprint_hmac_key/');

        $service->fingerprintFor('owner@example.com');
    }

    public function test_is_recipient_suppressed_is_false_until_record_outcome_is_called(): void
    {
        $service = app(PlatformNotificationCorrelationService::class);

        $this->assertFalse($service->isRecipientSuppressed('owner@example.com'));

        $correlation = PlatformNotificationCorrelation::create([
            'correlation_id' => (string) Str::uuid(),
            'account_type' => 'App\\Models\\User',
            'account_id' => 1,
            'notification_type' => 'user_password_reset',
            'recipient_fingerprint' => $service->fingerprintFor('owner@example.com'),
            'provider_message_id' => 'msg-suppress-1',
        ]);

        $service->recordOutcome($correlation, NotificationEventStatus::Bounced, 'ses_bounce_permanent');

        $this->assertTrue($service->isRecipientSuppressed('owner@example.com'));
    }

    public function test_record_outcome_upserts_and_never_creates_a_duplicate_suppression_row(): void
    {
        $service = app(PlatformNotificationCorrelationService::class);

        $correlation = PlatformNotificationCorrelation::create([
            'correlation_id' => (string) Str::uuid(),
            'account_type' => 'App\\Models\\User',
            'account_id' => 1,
            'notification_type' => 'user_password_reset',
            'recipient_fingerprint' => $service->fingerprintFor('owner@example.com'),
            'provider_message_id' => 'msg-suppress-2',
        ]);

        $service->recordOutcome($correlation, NotificationEventStatus::Bounced, 'ses_bounce_permanent');
        $service->recordOutcome($correlation, NotificationEventStatus::Bounced, 'ses_bounce_permanent');

        $this->assertSame(1, PlatformNotificationSuppression::query()->count());
    }

    public function test_listener_is_removed_after_a_successful_correlate_call(): void
    {
        $service = app(PlatformNotificationCorrelationService::class);

        $this->assertSame([], app('events')->getRawListeners()[MessageSent::class] ?? []);

        $service->correlate(
            'App\\Models\\User',
            1,
            'user_password_reset',
            'owner@example.com',
            function (string $correlationId) {
                $this->dispatchFakeSentMessage($correlationId, 'ses-msg-platform-listener', 'owner@example.com');
            },
        );

        $this->assertSame([], app('events')->getRawListeners()[MessageSent::class] ?? []);
    }
}
