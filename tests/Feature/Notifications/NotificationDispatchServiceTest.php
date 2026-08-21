<?php

namespace Tests\Feature\Notifications;

use App\Enums\ConsentChannel;
use App\Enums\ConsentStatus;
use App\Enums\NotificationEventStatus;
use App\Enums\NotificationTemplateStatus;
use App\Jobs\DispatchNotificationJob;
use App\Models\Client;
use App\Models\ClientCommunicationPreference;
use App\Models\CommunicationConsent;
use App\Models\Firm;
use App\Models\NotificationEvent;
use App\Models\NotificationProviderCorrelation;
use App\Models\NotificationTemplate;
use App\Services\ConsentService;
use App\Services\NotificationDispatchService;
use App\Services\NotificationEligibilityService;
use App\Services\NotificationTemplateService;
use App\Services\SenderDomainVerificationService;
use App\Services\SuppressionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class NotificationDispatchServiceTest extends TestCase
{
    use RefreshDatabase;

    private NotificationDispatchService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new NotificationDispatchService(
            new NotificationTemplateService,
            new SenderDomainVerificationService,
            new NotificationEligibilityService(new ConsentService, new SuppressionService),
        );
    }

    private function grantConsent(Firm $firm, Client $client, ConsentChannel $channel): void
    {
        CommunicationConsent::factory()->create([
            'firm_id' => $firm->id,
            'client_id' => $client->id,
            'channel' => $channel,
            'status' => ConsentStatus::Granted,
            'granted_at' => now(),
        ]);
    }

    public function test_dispatch_is_blocked_when_no_active_template_resolves(): void
    {
        Queue::fake();
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();

        $result = $this->service->dispatch($firm, $client, ConsentChannel::Email, $client->email, 'nonexistent_key');

        $this->assertFalse($result->accepted);
        $this->assertSame(NotificationEventStatus::Blocked, $result->status);
        Queue::assertNothingPushed();
    }

    /**
     * The required proof: sender/domain unverified must block dispatch
     * and be recorded in notification_events, even though the
     * template resolves and the client's consent/eligibility is fine.
     */
    public function test_dispatch_is_blocked_when_the_sender_domain_is_not_verified(): void
    {
        Queue::fake();
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();
        $this->grantConsent($firm, $client, ConsentChannel::Email);

        NotificationTemplate::factory()->domainUnverified()->create([
            'firm_id' => null,
            'key' => 'document_reminder',
            'channel' => ConsentChannel::Email,
            'status' => NotificationTemplateStatus::Active,
        ]);

        $result = $this->service->dispatch($firm, $client, ConsentChannel::Email, $client->email, 'document_reminder');

        $this->assertFalse($result->accepted);
        $this->assertSame(NotificationEventStatus::Blocked, $result->status);
        $this->assertStringContainsString('sender domain not verified', $result->reason);

        // Section 39A-3L, Checkpoint 24: notification_events is now
        // FORCE RLS, and dispatch()'s whole-method runWithFirmContext()
        // wrap clears app.current_firm_id before dispatch() returns.
        // This bare read (outside any context) must be explicitly
        // wrapped or it would incorrectly see zero rows regardless of
        // correctness.
        $blockedEvent = $this->runWithFirmContext(
            $firm,
            fn () => NotificationEvent::query()
                ->where('firm_id', $firm->id)
                ->where('status', NotificationEventStatus::Blocked->value)
                ->first(),
        );

        $this->assertNotNull($blockedEvent);
        $this->assertStringContainsString('sender domain not verified', $blockedEvent->reason);
        Queue::assertNothingPushed();
    }

    public function test_dispatch_is_blocked_when_the_client_has_no_granted_consent(): void
    {
        Queue::fake();
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();

        NotificationTemplate::factory()->domainVerified()->create([
            'firm_id' => null,
            'key' => 'document_reminder',
            'channel' => ConsentChannel::Email,
            'status' => NotificationTemplateStatus::Active,
        ]);

        $result = $this->service->dispatch($firm, $client, ConsentChannel::Email, $client->email, 'document_reminder');

        $this->assertFalse($result->accepted);
        Queue::assertNothingPushed();
    }

    /**
     * Section 39A-3L, Checkpoint 24 — dispatch() wraps its entire body
     * in one runWithFirmContext() call (it receives $firm directly, and
     * none of its three collaborators self-wrap), so it correctly
     * establishes context before ClientCommunicationPreference's
     * do_not_contact lookup and holds it through the whole gate chain.
     * An earlier version of this checkpoint's wiring (four independent
     * per-recordEvent() wraps) briefly broke this by clearing context
     * before the eligibility gate ran; that was found and fixed before
     * this checkpoint was reviewed — see tests/Feature/Security/
     * RlsForceRollout/NotificationEventsForceRlsActivationTest.php's
     * class docblock for the full history.
     */
    public function test_dispatch_is_blocked_when_do_not_contact_is_set(): void
    {
        Queue::fake();
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();
        $this->grantConsent($firm, $client, ConsentChannel::Email);

        ClientCommunicationPreference::factory()->create([
            'firm_id' => $firm->id,
            'client_id' => $client->id,
            'do_not_contact' => true,
        ]);

        NotificationTemplate::factory()->domainVerified()->create([
            'firm_id' => null,
            'key' => 'document_reminder',
            'channel' => ConsentChannel::Email,
            'status' => NotificationTemplateStatus::Active,
        ]);

        $result = $this->service->dispatch($firm, $client, ConsentChannel::Email, $client->email, 'document_reminder');

        $this->assertFalse($result->accepted);
        $this->assertStringContainsString('do_not_contact', $result->reason);
    }

    /**
     * Section 39A-3L, Checkpoint 24 — dispatch() wraps its entire body
     * in one runWithFirmContext() call, so ConsentService::isGranted()
     * correctly sees this test's grantConsent() row and dispatch()
     * genuinely reaches accepted=true/Queued. See
     * test_dispatch_is_blocked_when_do_not_contact_is_set's docblock
     * above for the fix history.
     */
    public function test_dispatch_succeeds_and_queues_a_job_when_every_gate_passes(): void
    {
        Queue::fake();
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();
        $this->grantConsent($firm, $client, ConsentChannel::Email);

        NotificationTemplate::factory()->domainVerified()->create([
            'firm_id' => null,
            'key' => 'document_reminder',
            'channel' => ConsentChannel::Email,
            'status' => NotificationTemplateStatus::Active,
        ]);

        $result = $this->service->dispatch($firm, $client, ConsentChannel::Email, $client->email, 'document_reminder');

        $this->assertTrue($result->accepted);
        $this->assertSame(NotificationEventStatus::Queued, $result->status);
        Queue::assertPushed(DispatchNotificationJob::class);
    }

    public function test_every_dispatch_attempt_first_records_an_attempted_event(): void
    {
        Queue::fake();
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();

        $this->service->dispatch($firm, $client, ConsentChannel::Email, $client->email, 'document_reminder');

        // Section 39A-3L, Checkpoint 24: same bare-read wrap reasoning
        // as test_dispatch_is_blocked_when_the_sender_domain_is_not_
        // verified above — dispatch()'s whole-method wrap clears
        // context before returning, so this read must be explicit.
        $count = $this->runWithFirmContext(
            $firm,
            fn () => NotificationEvent::query()
                ->where('firm_id', $firm->id)
                ->where('status', NotificationEventStatus::Attempted->value)
                ->count(),
        );

        $this->assertSame(1, $count);
    }

    /**
     * Mission 6 (Real Communications & Notification Delivery) — the
     * end-to-end proof that dispatch() now results in a REAL mail send
     * attempt, not merely a queued-and-forgotten job. Deliberately does
     * NOT fake the Queue or Notification/Mail facades: phpunit.xml sets
     * QUEUE_CONNECTION=sync (DispatchNotificationJob::dispatch() below
     * runs its handle() synchronously, in-process) and MAIL_MAILER=array
     * (a real, un-faked Symfony transport that genuinely dispatches
     * Illuminate\Mail\Events\MessageSent — this is the same real event
     * OutboundMailCorrelationService's own listener reacts to in
     * production, just observed here directly instead of via a
     * hand-built synthetic one).
     *
     * The array transport does not set the SES-specific X-Message-ID
     * header OutboundMailCorrelationService's listener looks for, so
     * (correctly, per that service's own documented contract) no
     * notification_events "Sent" row is recorded and no
     * provider_message_id is confirmed here — this test intentionally
     * does not assert either. That is the expected, honest behavior of
     * a non-SES mailer, not a gap in this test.
     */
    public function test_dispatch_reaches_a_real_mail_send_through_to_laravels_mail_system(): void
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create(['email' => 'client@example.com']);
        $this->grantConsent($firm, $client, ConsentChannel::Email);

        NotificationTemplate::factory()->domainVerified()->create([
            'firm_id' => null,
            'key' => 'document_reminder',
            'channel' => ConsentChannel::Email,
            'status' => NotificationTemplateStatus::Active,
            'subject' => 'A document is waiting on you',
            'body' => 'Please log in to your portal to upload the requested document.',
        ]);

        $capturedSubjects = [];
        Event::listen(MessageSent::class, function (MessageSent $event) use (&$capturedSubjects): void {
            $capturedSubjects[] = $event->message->getSubject();
        });

        $result = $this->service->dispatch($firm, $client, ConsentChannel::Email, $client->email, 'document_reminder');

        $this->assertTrue($result->accepted);
        $this->assertContains('A document is waiting on you', $capturedSubjects, 'DispatchNotificationJob::handle() must reach a real Illuminate\Mail\Events\MessageSent dispatch, not silently no-op.');

        $correlation = NotificationProviderCorrelation::query()->where('firm_id', $firm->id)->sole();
        $this->assertSame('client@example.com', $correlation->recipient_normalized);
    }
}
