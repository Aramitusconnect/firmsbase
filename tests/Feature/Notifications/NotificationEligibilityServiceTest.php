<?php

namespace Tests\Feature\Notifications;

use App\Enums\ConsentChannel;
use App\Enums\ConsentStatus;
use App\Enums\NotificationEventStatus;
use App\Models\Client;
use App\Models\ClientCommunicationPreference;
use App\Models\CommunicationConsent;
use App\Models\Firm;
use App\Models\NotificationEvent;
use App\Services\ConsentService;
use App\Services\NotificationEligibilityService;
use App\Services\SuppressionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationEligibilityServiceTest extends TestCase
{
    use RefreshDatabase;

    private NotificationEligibilityService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new NotificationEligibilityService(new ConsentService(), new SuppressionService());
    }

    public function test_eligible_when_consent_is_granted_and_nothing_blocks(): void
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();
        CommunicationConsent::factory()->create([
            'firm_id' => $firm->id,
            'client_id' => $client->id,
            'channel' => ConsentChannel::Email,
            'status' => ConsentStatus::Granted,
        ]);

        $result = $this->service->check($firm, $client, ConsentChannel::Email, $client->email);

        $this->assertTrue($result->eligible);
        $this->assertSame($client->preferred_language, $result->clientLanguage);
    }

    public function test_not_eligible_without_any_consent(): void
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();

        $result = $this->service->check($firm, $client, ConsentChannel::Email, $client->email);

        $this->assertFalse($result->eligible);
        $this->assertStringContainsString('no granted consent', $result->reason);
    }

    public function test_not_eligible_when_do_not_contact_is_set_even_with_granted_consent(): void
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();
        CommunicationConsent::factory()->create([
            'firm_id' => $firm->id,
            'client_id' => $client->id,
            'channel' => ConsentChannel::Email,
            'status' => ConsentStatus::Granted,
        ]);
        ClientCommunicationPreference::factory()->create([
            'firm_id' => $firm->id,
            'client_id' => $client->id,
            'do_not_contact' => true,
        ]);

        $result = $this->service->check($firm, $client, ConsentChannel::Email, $client->email);

        $this->assertFalse($result->eligible);
        $this->assertStringContainsString('do_not_contact', $result->reason);
    }

    public function test_not_eligible_when_recipient_was_previously_suppressed(): void
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();
        CommunicationConsent::factory()->create([
            'firm_id' => $firm->id,
            'client_id' => $client->id,
            'channel' => ConsentChannel::Email,
            'status' => ConsentStatus::Granted,
        ]);
        NotificationEvent::factory()->create([
            'firm_id' => $firm->id,
            'recipient' => $client->email,
            'channel' => ConsentChannel::Email,
            'status' => NotificationEventStatus::Bounced,
        ]);

        $result = $this->service->check($firm, $client, ConsentChannel::Email, $client->email);

        $this->assertFalse($result->eligible);
        $this->assertStringContainsString('suppressed', $result->reason);
    }
}
