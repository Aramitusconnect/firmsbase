<?php

namespace Tests\Feature\Activation;

use App\Enums\ConsentChannel;
use App\Enums\ConsentStatus;
use App\Models\CommunicationConsent;
use App\Models\Firm;
use App\Models\User;
use App\Services\ConsentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConsentServiceTest extends TestCase
{
    use RefreshDatabase;

    private ConsentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ConsentService();
    }

    public function test_capture_creates_granted_consent_and_audit_event(): void
    {
        $firm = Firm::factory()->create();
        $actor = User::factory()->create();

        $consent = $this->service->capture(
            firm: $firm,
            clientId: 501,
            channel: ConsentChannel::Sms,
            consentTextVersion: 'v3',
            actor: $actor,
            capturedVia: 'intake_form',
            capturedIp: '10.0.0.5',
        );

        $this->assertSame(ConsentStatus::Granted, $consent->status);
        $this->assertNotNull($consent->granted_at);

        $this->assertDatabaseHas('communication_consent_events', [
            'communication_consent_id' => $consent->id,
            'action' => 'captured',
            'new_status' => 'granted',
            'actor_user_id' => $actor->id,
        ]);
    }

    public function test_capture_twice_updates_in_place_rather_than_duplicating(): void
    {
        $firm = Firm::factory()->create();

        $first = $this->service->capture($firm, 501, ConsentChannel::Email, 'v1');
        $second = $this->service->capture($firm, 501, ConsentChannel::Email, 'v2');

        $this->assertSame($first->id, $second->id);
        $this->assertSame('v2', $second->fresh()->consent_text_version);
        $this->assertSame(
            1,
            CommunicationConsent::where('firm_id', $firm->id)
                ->where('client_id', 501)
                ->where('channel', 'email')
                ->count()
        );

        $this->assertDatabaseHas('communication_consent_events', [
            'communication_consent_id' => $first->id,
            'action' => 'recaptured',
        ]);
    }

    public function test_revoke_transitions_status_and_writes_audit_event(): void
    {
        $firm = Firm::factory()->create();
        $consent = $this->service->capture($firm, 501, ConsentChannel::WhatsApp, 'v1');

        $revoked = $this->service->revoke($firm, 501, ConsentChannel::WhatsApp, reason: 'client requested opt-out');

        $this->assertSame(ConsentStatus::Revoked, $revoked->status);
        $this->assertNotNull($revoked->revoked_at);

        $this->assertDatabaseHas('communication_consent_events', [
            'communication_consent_id' => $consent->id,
            'action' => 'revoked',
            'previous_status' => 'granted',
            'new_status' => 'revoked',
        ]);
    }

    public function test_revoke_throws_when_no_consent_exists(): void
    {
        $firm = Firm::factory()->create();

        $this->expectException(\RuntimeException::class);

        $this->service->revoke($firm, 999, ConsentChannel::Portal);
    }

    public function test_is_granted_false_before_any_consent_captured(): void
    {
        $firm = Firm::factory()->create();

        $this->assertFalse($this->service->isGranted($firm, 501, ConsentChannel::Sms));
    }

    public function test_is_granted_true_after_capture(): void
    {
        $firm = Firm::factory()->create();
        $this->service->capture($firm, 501, ConsentChannel::Sms, 'v1');

        $this->assertTrue($this->service->isGranted($firm, 501, ConsentChannel::Sms));
    }

    public function test_is_granted_false_after_revoke(): void
    {
        $firm = Firm::factory()->create();
        $this->service->capture($firm, 501, ConsentChannel::Sms, 'v1');
        $this->service->revoke($firm, 501, ConsentChannel::Sms);

        $this->assertFalse($this->service->isGranted($firm, 501, ConsentChannel::Sms));
    }
}
