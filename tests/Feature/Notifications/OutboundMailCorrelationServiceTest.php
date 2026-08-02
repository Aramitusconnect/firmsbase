<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Enums\ConsentChannel;
use App\Enums\NotificationEventStatus;
use App\Exceptions\NotificationTransportFailedException;
use App\Models\Firm;
use App\Models\NotificationEvent;
use App\Models\NotificationProviderCorrelation;
use App\Services\NotificationDispatchService;
use App\Services\OutboundMailCorrelationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\SentMessage as IlluminateSentMessage;
use Illuminate\Support\Facades\Event;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage as SymfonySentMessage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * OutboundMailCorrelationServiceTest — feature/ses-event-consumer.
 * Never sends real mail: the send confirmation is simulated by
 * dispatching a real Illuminate\Mail\Events\MessageSent event carrying
 * a hand-built Symfony message with the exact headers
 * Illuminate\Mail\Transport\SesTransport would leave on a genuinely
 * sent message (X-Metadata-correlation_id from MailMessage::metadata(),
 * X-Message-ID from a confirmed SES send) — proving
 * OutboundMailCorrelationService's own listener logic without
 * depending on a live mail transport.
 */
class OutboundMailCorrelationServiceTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_correlate_creates_a_correlation_record_before_send_and_persists_the_provider_message_id_after(): void
    {
        $firm = Firm::factory()->create();

        app(OutboundMailCorrelationService::class)->correlate(
            $firm,
            ConsentChannel::Email,
            'Owner@Example.com',
            function (string $correlationId) {
                $this->dispatchFakeSentMessage($correlationId, 'ses-msg-abc-123', 'owner@example.com');
            },
        );

        $correlation = NotificationProviderCorrelation::query()->where('firm_id', $firm->id)->sole();

        $this->assertSame('owner@example.com', $correlation->recipient_normalized);
        $this->assertSame('ses-msg-abc-123', $correlation->provider_message_id);
    }

    public function test_correlate_records_exactly_one_sent_notification_event_only_after_confirmation(): void
    {
        $firm = Firm::factory()->create();

        app(OutboundMailCorrelationService::class)->correlate(
            $firm,
            ConsentChannel::Email,
            'owner@example.com',
            function (string $correlationId) {
                $this->dispatchFakeSentMessage($correlationId, 'ses-msg-xyz-456', 'owner@example.com');
            },
        );

        $sentEvents = $this->runWithFirmContext(
            $firm,
            fn () => NotificationEvent::query()
                ->where('firm_id', $firm->id)
                ->where('status', NotificationEventStatus::Sent->value)
                ->get(),
        );

        $this->assertCount(1, $sentEvents);
        $this->assertSame('ses-msg-xyz-456', $sentEvents->first()->provider_message_id);
    }

    public function test_no_sent_event_is_recorded_when_the_send_never_confirms(): void
    {
        $firm = Firm::factory()->create();

        // $send does nothing — simulates an exception thrown before the
        // mail transport ever returns, or a transport that never fires
        // MessageSent. No fake "Sent" event may ever be recorded.
        app(OutboundMailCorrelationService::class)->correlate(
            $firm,
            ConsentChannel::Email,
            'owner@example.com',
            function (string $correlationId) {
                // intentionally empty
            },
        );

        $sentEvents = $this->runWithFirmContext(
            $firm,
            fn () => NotificationEvent::query()->where('firm_id', $firm->id)->count(),
        );

        $this->assertSame(0, $sentEvents);

        $correlation = NotificationProviderCorrelation::query()->where('firm_id', $firm->id)->sole();
        $this->assertNull($correlation->provider_message_id);
    }

    /**
     * @return array<int, mixed>
     */
    private function messageSentListeners(): array
    {
        return app('events')->getRawListeners()[MessageSent::class] ?? [];
    }

    public function test_listener_is_removed_after_a_successful_send(): void
    {
        $firm = Firm::factory()->create();

        $this->assertSame([], $this->messageSentListeners());

        app(OutboundMailCorrelationService::class)->correlate(
            $firm,
            ConsentChannel::Email,
            'owner@example.com',
            function (string $correlationId) {
                $this->dispatchFakeSentMessage($correlationId, 'ses-msg-listener-1', 'owner@example.com');
            },
        );

        $this->assertSame([], $this->messageSentListeners(), 'correlate() must leave no MessageSent listener registered after a successful send.');
    }

    public function test_listener_is_removed_after_the_send_throws(): void
    {
        $firm = Firm::factory()->create();

        try {
            app(OutboundMailCorrelationService::class)->correlate(
                $firm,
                ConsentChannel::Email,
                'owner@example.com',
                function (string $correlationId): void {
                    throw new \RuntimeException('simulated transport failure');
                },
            );

            $this->fail('Expected the simulated transport exception to propagate.');
        } catch (NotificationTransportFailedException $e) {
            // Post-9722e88 audit remediation: send-closure failures are
            // now wrapped so callers can distinguish "transport itself
            // failed" from "correlation bookkeeping failed" — see
            // CorrelatedPasswordResetSenderService.
            $this->assertSame('simulated transport failure', $e->getPrevious()?->getMessage());
        }

        $this->assertSame([], $this->messageSentListeners(), 'A failed send must leave no stale MessageSent listener.');
    }

    public function test_two_sequential_correlate_calls_in_the_same_process_do_not_accumulate_listeners(): void
    {
        $firm = Firm::factory()->create();

        app(OutboundMailCorrelationService::class)->correlate(
            $firm,
            ConsentChannel::Email,
            'first@example.com',
            function (string $correlationId) {
                $this->dispatchFakeSentMessage($correlationId, 'ses-msg-first', 'first@example.com');
            },
        );

        $this->assertCount(0, $this->messageSentListeners());

        app(OutboundMailCorrelationService::class)->correlate(
            $firm,
            ConsentChannel::Email,
            'second@example.com',
            function (string $correlationId) {
                $this->dispatchFakeSentMessage($correlationId, 'ses-msg-second', 'second@example.com');
            },
        );

        $this->assertCount(0, $this->messageSentListeners(), 'A long-running process calling correlate() repeatedly must never accumulate listeners.');
    }

    public function test_two_sequential_correlate_calls_do_not_cross_correlate_the_provider_message_id(): void
    {
        $firm = Firm::factory()->create();

        app(OutboundMailCorrelationService::class)->correlate(
            $firm,
            ConsentChannel::Email,
            'first@example.com',
            function (string $correlationId) {
                $this->dispatchFakeSentMessage($correlationId, 'ses-msg-cross-1', 'first@example.com');
            },
        );

        app(OutboundMailCorrelationService::class)->correlate(
            $firm,
            ConsentChannel::Email,
            'second@example.com',
            function (string $correlationId) {
                $this->dispatchFakeSentMessage($correlationId, 'ses-msg-cross-2', 'second@example.com');
            },
        );

        $first = NotificationProviderCorrelation::query()->where('recipient_normalized', 'first@example.com')->sole();
        $second = NotificationProviderCorrelation::query()->where('recipient_normalized', 'second@example.com')->sole();

        $this->assertSame('ses-msg-cross-1', $first->provider_message_id);
        $this->assertSame('ses-msg-cross-2', $second->provider_message_id);
    }

    public function test_correlate_does_not_remove_a_pre_existing_unrelated_message_sent_listener(): void
    {
        $firm = Firm::factory()->create();

        $unrelatedListenerFired = false;
        Event::listen(MessageSent::class, function () use (&$unrelatedListenerFired): void {
            $unrelatedListenerFired = true;
        });

        app(OutboundMailCorrelationService::class)->correlate(
            $firm,
            ConsentChannel::Email,
            'owner@example.com',
            function (string $correlationId) {
                $this->dispatchFakeSentMessage($correlationId, 'ses-msg-unrelated', 'owner@example.com');
            },
        );

        $this->assertTrue($unrelatedListenerFired, 'A pre-existing MessageSent listener must still fire.');
        $this->assertCount(1, $this->messageSentListeners(), 'The pre-existing listener must survive; only this service\'s own listener is removed.');
    }

    /**
     * Post-9722e88 audit remediation requirement 2 (post-send failure):
     * SES has already accepted the message by the time recordSent()
     * runs — a failure here must never trigger a second send, and must
     * never be silently swallowed as a fully correlated success. Proves
     * both: correlate() does not rethrow (the caller must not treat
     * this as "nothing was sent"), and the send closure ran exactly
     * once (no retry-and-resend).
     */
    public function test_post_send_bookkeeping_failure_does_not_rethrow_and_never_sends_twice(): void
    {
        $firm = Firm::factory()->create();

        $failingDispatchService = \Mockery::mock(NotificationDispatchService::class);
        $failingDispatchService->shouldReceive('recordSent')->once()->andThrow(new \RuntimeException('simulated DB failure'));
        $this->app->instance(NotificationDispatchService::class, $failingDispatchService);

        $sendCount = 0;

        // Re-resolve after rebinding NotificationDispatchService so the
        // service under test actually receives the failing mock.
        app(OutboundMailCorrelationService::class)->correlate(
            $firm,
            ConsentChannel::Email,
            'owner@example.com',
            function (string $correlationId) use (&$sendCount) {
                $sendCount++;
                $this->dispatchFakeSentMessage($correlationId, 'ses-msg-post-send-failure', 'owner@example.com');
            },
        );

        $this->assertSame(1, $sendCount, 'A post-send bookkeeping failure must never cause the send closure to run again.');

        // provider_message_id IS persisted (that update() runs before
        // the failing recordSent() call) — the reconciliation gap is
        // specifically that no notification_events "Sent" row exists,
        // which is exactly what the critical log must surface for
        // manual reconciliation, without ever rethrowing (the email
        // genuinely was sent).
        $correlation = NotificationProviderCorrelation::query()->where('firm_id', $firm->id)->sole();
        $this->assertSame('ses-msg-post-send-failure', $correlation->provider_message_id);
    }
}
