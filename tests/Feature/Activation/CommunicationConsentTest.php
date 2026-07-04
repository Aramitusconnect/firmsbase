<?php

namespace Tests\Feature\Activation;

use App\Enums\ConsentChannel;
use App\Enums\ConsentStatus;
use App\Models\CommunicationConsent;
use App\Models\Firm;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommunicationConsentTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_can_be_created_via_factory(): void
    {
        $consent = CommunicationConsent::factory()->create();

        $this->assertDatabaseHas('communication_consents', ['id' => $consent->id]);
        $this->assertSame(ConsentStatus::Granted, $consent->status);
    }

    public function test_is_granted_true_for_granted_status_with_no_expiry(): void
    {
        $consent = CommunicationConsent::factory()->create();

        $this->assertTrue($consent->isGranted());
    }

    public function test_is_granted_false_when_revoked(): void
    {
        $consent = CommunicationConsent::factory()->revoked()->create();

        $this->assertFalse($consent->isGranted());
    }

    public function test_is_granted_false_when_expired_even_if_status_still_granted(): void
    {
        $consent = CommunicationConsent::factory()->create(['expires_at' => now()->subDay()]);

        $this->assertFalse($consent->isGranted());
    }

    public function test_unique_firm_client_channel(): void
    {
        $firm = Firm::factory()->create();

        CommunicationConsent::factory()->forFirm($firm)
            ->channel(ConsentChannel::Sms)
            ->create(['client_id' => 42]);

        $this->expectException(QueryException::class);

        CommunicationConsent::factory()->forFirm($firm)
            ->channel(ConsentChannel::Sms)
            ->create(['client_id' => 42]);
    }

    public function test_different_channels_for_same_client_are_allowed(): void
    {
        $firm = Firm::factory()->create();

        CommunicationConsent::factory()->forFirm($firm)->channel(ConsentChannel::Sms)->create(['client_id' => 42]);
        CommunicationConsent::factory()->forFirm($firm)->channel(ConsentChannel::Email)->create(['client_id' => 42]);

        $this->assertDatabaseCount('communication_consents', 2);
    }
}
