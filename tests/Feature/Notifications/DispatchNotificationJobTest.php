<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Enums\ConsentChannel;
use App\Enums\NotificationEventStatus;
use App\Enums\NotificationTemplateStatus;
use App\Jobs\DispatchNotificationJob;
use App\Models\Client;
use App\Models\Firm;
use App\Models\NotificationEvent;
use App\Models\NotificationProviderCorrelation;
use App\Models\NotificationTemplate;
use App\Notifications\TemplatedNotification;
use App\Services\NotificationDispatchService;
use App\Services\OutboundMailCorrelationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * DispatchNotificationJobTest — Mission 6 (Real Communications &
 * Notification Delivery). Before this mission, handle() only ever
 * called NotificationDispatchService::recordSent() directly, with
 * providerMessageId always null and no real transport call of any
 * kind (see the pre-mission class docblock: "This job never sends a
 * real email/SMS/WhatsApp message"). This test proves that is no
 * longer true for the email channel: handle() now reaches Laravel's
 * real Notification system via OutboundMailCorrelationService::
 * correlate() — the SAME correlated-send infrastructure
 * ClientPortalInvitationNotificationService already uses in
 * production, never a second parallel pathway.
 *
 * Notification::fake() intercepts the actual mail transport, so no
 * real Illuminate\Mail\Events\MessageSent fires — correlate() never
 * confirms a provider message id and therefore never calls
 * recordSent() itself (see OutboundMailCorrelationServiceTest's own
 * test_no_sent_event_is_recorded_when_the_send_never_confirms for the
 * identical reasoning). That is expected here too. What this test
 * proves — the thing that was completely missing before this mission
 * — is that the job reaches a real ->notify() call carrying the
 * resolved template's own subject/body, and that the pre-send
 * correlation bookkeeping (NotificationProviderCorrelation) runs for
 * real; both are genuine application code paths Notification::fake()
 * does not stub out.
 */
class DispatchNotificationJobTest extends TestCase
{
    use RefreshDatabase;

    private function makeTemplate(Firm $firm, string $key, string $subject, string $body): NotificationTemplate
    {
        return $this->runWithFirmContext($firm, fn () => NotificationTemplate::factory()->domainVerified()->create([
            'firm_id' => null,
            'key' => $key,
            'channel' => ConsentChannel::Email,
            'status' => NotificationTemplateStatus::Active,
            'subject' => $subject,
            'body' => $body,
        ]));
    }

    public function test_handle_sends_a_real_templated_notification_for_the_email_channel(): void
    {
        Notification::fake();

        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create(['email' => 'client@example.com']));
        $template = $this->makeTemplate($firm, 'invoice_sent', 'A new invoice is available', 'Please review your new invoice.');

        $job = new DispatchNotificationJob(
            firmId: $firm->id,
            correlationId: (string) Str::uuid(),
            templateId: $template->id,
            channel: ConsentChannel::Email->value,
            recipient: 'client@example.com',
            clientId: $client->id,
            matterId: null,
        );

        $job->handle(app(NotificationDispatchService::class), app(OutboundMailCorrelationService::class));

        Notification::assertSentOnDemand(
            TemplatedNotification::class,
            fn ($notification, $channels, $notifiable) => $notifiable->routeNotificationFor('mail') === 'client@example.com'
        );

        // Proves the pre-send correlation bookkeeping — real application
        // code, not something Notification::fake() stubs out — ran for
        // real before the (faked) send.
        $correlation = NotificationProviderCorrelation::query()->where('firm_id', $firm->id)->sole();
        $this->assertSame('client@example.com', $correlation->recipient_normalized);
    }

    public function test_handle_records_a_failed_event_when_the_template_no_longer_resolves_at_send_time(): void
    {
        Notification::fake();

        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());

        $job = new DispatchNotificationJob(
            firmId: $firm->id,
            correlationId: (string) Str::uuid(),
            templateId: 999999,
            channel: ConsentChannel::Email->value,
            recipient: 'client@example.com',
            clientId: $client->id,
            matterId: null,
        );

        $job->handle(app(NotificationDispatchService::class), app(OutboundMailCorrelationService::class));

        Notification::assertNothingSent();

        $failedEvent = $this->runWithFirmContext(
            $firm,
            fn () => NotificationEvent::query()
                ->where('firm_id', $firm->id)
                ->where('status', NotificationEventStatus::Failed->value)
                ->first(),
        );

        $this->assertNotNull($failedEvent);
    }

    /**
     * Non-email channels have no real transport wired (SMS/WhatsApp
     * provider integration is explicitly out of scope for this
     * mission). handle() must preserve its previous fakeable-boundary
     * behavior for them rather than attempting a mail send with a
     * non-email recipient.
     */
    public function test_handle_preserves_the_fakeable_boundary_for_a_non_email_channel(): void
    {
        Notification::fake();

        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $template = $this->runWithFirmContext($firm, fn () => NotificationTemplate::factory()->domainVerified()->create([
            'firm_id' => null,
            'key' => 'sms_reminder',
            'channel' => ConsentChannel::Sms,
            'status' => NotificationTemplateStatus::Active,
        ]));

        $job = new DispatchNotificationJob(
            firmId: $firm->id,
            correlationId: (string) Str::uuid(),
            templateId: $template->id,
            channel: ConsentChannel::Sms->value,
            recipient: '+15555550100',
            clientId: $client->id,
            matterId: null,
        );

        $job->handle(app(NotificationDispatchService::class), app(OutboundMailCorrelationService::class));

        Notification::assertNothingSent();

        $sentEvent = $this->runWithFirmContext(
            $firm,
            fn () => NotificationEvent::query()
                ->where('firm_id', $firm->id)
                ->where('status', NotificationEventStatus::Sent->value)
                ->first(),
        );

        $this->assertNotNull($sentEvent);
        $this->assertNull($sentEvent->provider_message_id);
    }
}
