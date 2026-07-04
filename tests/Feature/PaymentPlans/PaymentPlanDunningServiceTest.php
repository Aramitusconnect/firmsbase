<?php

namespace Tests\Feature\PaymentPlans;

use App\Enums\ConsentChannel;
use App\Enums\ConsentStatus;
use App\Enums\PaymentPlanInstallmentStatus;
use App\Enums\PaymentPlanStatus;
use App\Models\Client;
use App\Models\ClientCommunicationPreference;
use App\Models\CommunicationConsent;
use App\Models\Firm;
use App\Models\PaymentPlan;
use App\Models\PaymentPlanInstallment;
use App\Services\ConsentService;
use App\Services\PaymentPlanDunningService;
use App\Services\TimelineEventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentPlanDunningServiceTest extends TestCase
{
    use RefreshDatabase;

    private PaymentPlanDunningService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PaymentPlanDunningService(new ConsentService(), new TimelineEventRecorder());
    }

    private function missedInstallmentFor(Firm $firm, Client $client): PaymentPlanInstallment
    {
        $plan = PaymentPlan::factory()->forClient($client)->active()->create();

        return PaymentPlanInstallment::factory()->forPlan($plan)->status(PaymentPlanInstallmentStatus::Missed)->create();
    }

    public function test_eligible_when_consent_granted_and_no_do_not_contact(): void
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();
        (new ConsentService())->capture($firm, $client->id, ConsentChannel::Email, 'v1');
        $installment = $this->missedInstallmentFor($firm, $client);

        $result = $this->service->checkAndLog($installment);

        $this->assertTrue($result->eligible);
        $this->assertSame($client->preferred_language, $result->clientLanguage);
        $this->assertSame($client->preferred_timezone, $result->clientTimezone);
        $this->assertSame('reminder_queued', $installment->fresh()->dunning_state);
    }

    public function test_not_eligible_when_do_not_contact_flag_is_set(): void
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();
        (new ConsentService())->capture($firm, $client->id, ConsentChannel::Email, 'v1');
        ClientCommunicationPreference::factory()->forClient($client)->doNotContact()->create();
        $installment = $this->missedInstallmentFor($firm, $client);

        $result = $this->service->checkAndLog($installment);

        $this->assertFalse($result->eligible);
        $this->assertSame('do_not_contact flag is set', $result->reason);
        $this->assertSame('reminder_skipped', $installment->fresh()->dunning_state);
    }

    public function test_not_eligible_when_no_consent_is_granted_for_the_channel(): void
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();
        $installment = $this->missedInstallmentFor($firm, $client);

        $result = $this->service->checkAndLog($installment, ConsentChannel::Sms);

        $this->assertFalse($result->eligible);
        $this->assertStringContainsString('no granted consent', $result->reason);
    }

    public function test_not_eligible_when_consent_was_revoked(): void
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();
        $consentService = new ConsentService();
        $consentService->capture($firm, $client->id, ConsentChannel::Email, 'v1');
        $consentService->revoke($firm, $client->id, ConsentChannel::Email);
        $installment = $this->missedInstallmentFor($firm, $client);

        $result = $this->service->checkAndLog($installment);

        $this->assertFalse($result->eligible);
    }

    public function test_dunning_pauses_when_plan_is_paused(): void
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();
        (new ConsentService())->capture($firm, $client->id, ConsentChannel::Email, 'v1');
        $plan = PaymentPlan::factory()->forClient($client)->create(['status' => PaymentPlanStatus::Paused]);
        $installment = PaymentPlanInstallment::factory()->forPlan($plan)->status(PaymentPlanInstallmentStatus::Missed)->create();

        $result = $this->service->checkAndLog($installment);

        $this->assertFalse($result->eligible);
        $this->assertStringContainsString('is not active', $result->reason);
    }

    public function test_dunning_pauses_when_plan_is_renegotiated(): void
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();
        (new ConsentService())->capture($firm, $client->id, ConsentChannel::Email, 'v1');
        $plan = PaymentPlan::factory()->forClient($client)->create(['status' => PaymentPlanStatus::Renegotiated]);
        $installment = PaymentPlanInstallment::factory()->forPlan($plan)->status(PaymentPlanInstallmentStatus::Missed)->create();

        $result = $this->service->checkAndLog($installment);

        $this->assertFalse($result->eligible);
    }

    public function test_no_dunning_event_is_logged_when_plan_is_not_active(): void
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();
        $plan = PaymentPlan::factory()->forClient($client)->create(['status' => PaymentPlanStatus::Paused]);
        $installment = PaymentPlanInstallment::factory()->forPlan($plan)->status(PaymentPlanInstallmentStatus::Missed)->create();

        $this->service->checkAndLog($installment);

        $this->assertDatabaseCount('payment_plan_events', 0);
    }

    public function test_not_eligible_when_installment_is_not_yet_due_or_missed(): void
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();
        (new ConsentService())->capture($firm, $client->id, ConsentChannel::Email, 'v1');
        $plan = PaymentPlan::factory()->forClient($client)->active()->create();
        $installment = PaymentPlanInstallment::factory()->forPlan($plan)->status(PaymentPlanInstallmentStatus::Scheduled)->create();

        $result = $this->service->checkAndLog($installment);

        $this->assertFalse($result->eligible);
    }
}
