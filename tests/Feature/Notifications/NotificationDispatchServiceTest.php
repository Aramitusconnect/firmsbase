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
use App\Models\NotificationTemplate;
use App\Services\ConsentService;
use App\Services\NotificationDispatchService;
use App\Services\NotificationEligibilityService;
use App\Services\NotificationTemplateService;
use App\Services\SenderDomainVerificationService;
use App\Services\SuppressionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            new NotificationTemplateService(),
            new SenderDomainVerificationService(),
            new NotificationEligibilityService(new ConsentService(), new SuppressionService()),
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

        $blockedEvent = \App\Models\NotificationEvent::query()
            ->where('firm_id', $firm->id)
            ->where('status', NotificationEventStatus::Blocked->value)
            ->first();

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

        $this->assertSame(1, \App\Models\NotificationEvent::query()
            ->where('firm_id', $firm->id)
            ->where('status', NotificationEventStatus::Attempted->value)
            ->count());
    }
}
