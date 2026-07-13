<?php

namespace Tests\Feature\Activation;

use App\Enums\ConsentChannel;
use App\Enums\ConsentStatus;
use App\Models\Client;
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
        $client = Client::factory()->forFirm($firm)->create();
        $actor = User::factory()->create();

        $consent = $this->service->capture(
            firm: $firm,
            clientId: $client->id,
            channel: ConsentChannel::Sms,
            consentTextVersion: 'v3',
            actor: $actor,
            capturedVia: 'intake_form',
            capturedIp: '10.0.0.5',
        );

        $this->assertSame(ConsentStatus::Granted, $consent->status);
        $this->assertNotNull($consent->granted_at);

        // Section 39A-3L, Checkpoint 12 fallout: communication_consent_events
        // is now FORCE-RLS protected too, and capture() clears its own
        // context wrap before returning, so a bare assertDatabaseHas()
        // here (outside any active context) would find zero rows even
        // though the event genuinely persisted. Same fix already applied
        // to the communication_consents assertions in this file at
        // Checkpoint 11 — this file was never updated to apply the same
        // fix to the communication_consent_events assertions until now.
        $this->runWithFirmContext($firm, function () use ($consent, $actor) {
            $this->assertDatabaseHas('communication_consent_events', [
                'communication_consent_id' => $consent->id,
                'action' => 'captured',
                'new_status' => 'granted',
                'actor_user_id' => $actor->id,
            ]);
        });
    }

    public function test_capture_twice_updates_in_place_rather_than_duplicating(): void
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();

        $first = $this->service->capture($firm, $client->id, ConsentChannel::Email, 'v1');
        $second = $this->service->capture($firm, $client->id, ConsentChannel::Email, 'v2');

        $this->assertSame($first->id, $second->id);
        // Section 39A-3L, Checkpoint 11 fallout: communication_consents is
        // now FORCE-RLS protected, and capture() clears its own context
        // wrap before returning, so a bare $second->fresh() re-query here
        // (outside any active context) would return null. capture()
        // already returns $consent->fresh() from INSIDE its own context
        // wrap, so $second is already the correct, up-to-date row —
        // asserting directly on it needs no re-fetch at all.
        $this->assertSame('v2', $second->consent_text_version);
        $this->assertSame(
            1,
            $this->runWithFirmContext($firm, fn () => CommunicationConsent::where('firm_id', $firm->id)
                ->where('client_id', $client->id)
                ->where('channel', 'email')
                ->count())
        );

        // Section 39A-3L, Checkpoint 12 fallout: see the identical fix and
        // explanation in test_capture_creates_granted_consent_and_audit_event
        // above — communication_consent_events is now FORCE-RLS protected.
        $this->runWithFirmContext($firm, function () use ($first) {
            $this->assertDatabaseHas('communication_consent_events', [
                'communication_consent_id' => $first->id,
                'action' => 'recaptured',
            ]);
        });
    }

    public function test_revoke_transitions_status_and_writes_audit_event(): void
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();
        $consent = $this->service->capture($firm, $client->id, ConsentChannel::WhatsApp, 'v1');

        $revoked = $this->service->revoke($firm, $client->id, ConsentChannel::WhatsApp, reason: 'client requested opt-out');

        $this->assertSame(ConsentStatus::Revoked, $revoked->status);
        $this->assertNotNull($revoked->revoked_at);

        // Section 39A-3L, Checkpoint 12 fallout: see the identical fix and
        // explanation in test_capture_creates_granted_consent_and_audit_event
        // above — communication_consent_events is now FORCE-RLS protected.
        $this->runWithFirmContext($firm, function () use ($consent) {
            $this->assertDatabaseHas('communication_consent_events', [
                'communication_consent_id' => $consent->id,
                'action' => 'revoked',
                'previous_status' => 'granted',
                'new_status' => 'revoked',
            ]);
        });
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
        $client = Client::factory()->forFirm($firm)->create();
        $this->service->capture($firm, $client->id, ConsentChannel::Sms, 'v1');

        // Section 39A-3L, Checkpoint 11 fallout: isGranted() is
        // deliberately left unwrapped in ConsentService (a pure read
        // helper — see its own docblock), so any caller of a bare
        // isGranted() must supply its own active tenant context now
        // that communication_consents is FORCE-RLS protected. capture()
        // above clears its own context wrap before returning, so this
        // read genuinely needs its own wrap — it is not one this test
        // can share with capture()'s.
        $this->assertTrue($this->runWithFirmContext($firm, fn () => $this->service->isGranted($firm, $client->id, ConsentChannel::Sms)));
    }

    public function test_is_granted_false_after_revoke(): void
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();

        $this->service->capture($firm, $client->id, ConsentChannel::Sms, 'v1');
        $this->service->revoke($firm, $client->id, ConsentChannel::Sms, reason: 'client requested opt-out');

        // Same fix as test_is_granted_true_after_capture above. Without
        // this wrap the assertion would still coincidentally pass (a
        // missing-context read also returns false), but it would no
        // longer be genuinely proving the revoke() transition — it
        // would just be proving the read has no context. Wrapping makes
        // this test actually exercise the revoked-status behavior again.
        $this->assertFalse($this->runWithFirmContext($firm, fn () => $this->service->isGranted($firm, $client->id, ConsentChannel::Sms)));
    }
}
