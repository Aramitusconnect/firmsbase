<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Enums\ConsentChannel;
use App\Enums\NotificationEventStatus;
use App\Models\Firm;
use App\Models\NotificationEvent;
use App\Models\NotificationProviderCorrelation;
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
}
